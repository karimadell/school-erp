<?php

namespace App\Http\Controllers\Dashboard;

use App\Exceptions\TransportCapacityExceeded;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Bus;
use App\Models\Enrollment;
use App\Models\StaffMember;
use App\Models\StudentTransportAssignment;
use App\Models\TransportRoute;
use App\Models\User;
use App\Models\VehicleStaffAssignment;
use App\Services\Transport\TransportAssignmentService;
use App\Services\Transport\TransportCatalogService;
use App\Services\Transport\VehicleStaffAssignmentService;
use App\Support\TransportPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TransportManagementController extends Controller
{
    public function __construct(
        private readonly TransportCatalogService $catalog,
        private readonly TransportAssignmentService $students,
        private readonly VehicleStaffAssignmentService $staff,
    ) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()->can(TransportPermissions::VIEW), 403);

        $date = CarbonImmutable::parse($request->string('date')->value() ?: now()->toDateString())->startOfDay();
        $academicYears = AcademicYear::query()->orderByDesc('start_date')->get();
        $yearId = $request->integer('academic_year_id') ?: AcademicYear::query()->where('is_active', true)->value('id');

        $activeAssignments = StudentTransportAssignment::query()
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->when($yearId, fn ($query) => $query->whereHas('enrollment', fn ($enrollment) => $enrollment->where('academic_year_id', $yearId)));

        $occupiedByBus = (clone $activeAssignments)->selectRaw('bus_id, count(*) as aggregate')->groupBy('bus_id')->pluck('aggregate', 'bus_id');
        $staffPassengerByBus = VehicleStaffAssignment::query()->where('role', '!=', VehicleStaffAssignment::ROLE_DRIVER)
            ->whereDate('effective_from', '<=', $date)->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->get()->filter(fn ($item) => $item->weekdays === null || in_array($date->dayOfWeekIso, $item->weekdays, true))->countBy('bus_id');
        $buses = Bus::query()->with(['staffAssignments.user', 'staffAssignments.staffMember', 'studentTransportAssignments.route'])->orderByDesc('is_active')->orderBy('vehicle_code')->orderBy('name')->get();
        $routes = TransportRoute::query()->withCount(['studentAssignments as active_students_count' => fn ($query) => $query
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($dates) => $dates->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->when($yearId, fn ($assignments) => $assignments->whereHas('enrollment', fn ($enrollment) => $enrollment->where('academic_year_id', $yearId)))])
            ->orderByDesc('is_active')->orderBy('name')->get();

        $enrollments = Enrollment::query()->active()->with(['student', 'schoolClass', 'grade', 'academicYear'])
            ->when($yearId, fn ($query) => $query->where('academic_year_id', $yearId))
            ->whereHas('student', fn ($query) => $query->when($request->filled('student'), fn ($student) => $student->where('name', 'like', '%'.$request->string('student')->value().'%')))
            ->orderBy('class_id')->orderBy('student_id')->get();

        $currentByEnrollment = (clone $activeAssignments)->with(['route', 'bus'])->get()->keyBy('enrollment_id');
        $history = StudentTransportAssignment::query()->with(['enrollment.student', 'enrollment.schoolClass', 'route', 'bus', 'creator', 'endedBy'])
            ->when($yearId, fn ($query) => $query->whereHas('enrollment', fn ($enrollment) => $enrollment->where('academic_year_id', $yearId)))
            ->when($request->integer('bus_id'), fn ($query, $id) => $query->where('bus_id', $id))
            ->when($request->integer('route_id'), fn ($query, $id) => $query->where('transport_route_id', $id))
            ->when($request->filled('student'), fn ($query) => $query->whereHas('enrollment.student', fn ($student) => $student->where('name', 'like', '%'.$request->string('student')->value().'%')))
            ->when($request->filled('history_date'), function ($query) use ($request) {
                $filterDate = $request->date('history_date');
                $query->whereDate('effective_from', '<=', $filterDate)->where(fn ($dates) => $dates->whereNull('effective_to')->orWhereDate('effective_to', '>=', $filterDate));
            })->latest('effective_from')->latest('id')->paginate(30)->withQueryString();

        $activeBuses = $buses->where('is_active', true);
        $totalCapacity = $activeBuses->sum('student_capacity');
        $occupied = $activeBuses->sum(fn (Bus $bus) => (int) ($occupiedByBus[$bus->id] ?? 0));
        $physicalByBus = $activeBuses->mapWithKeys(fn (Bus $bus) => [$bus->id => (int) ($occupiedByBus[$bus->id] ?? 0) + (int) ($staffPassengerByBus[$bus->id] ?? 0)]);
        $kpis = [
            'vehicles' => $activeBuses->count(),
            'capacity' => $totalCapacity,
            'occupied' => $occupied,
            'available' => max(0, $totalCapacity - $occupied),
            'students' => (clone $activeAssignments)->distinct()->count('enrollment_id'),
            'full' => $activeBuses->filter(fn (Bus $bus) => (int) ($occupiedByBus[$bus->id] ?? 0) >= min(14, $bus->student_capacity))->count(),
        ];

        return view('dashboard.transport-management.index', compact(
            'date', 'academicYears', 'yearId', 'buses', 'routes', 'enrollments',
            'currentByEnrollment', 'history', 'occupiedByBus', 'physicalByBus', 'kpis'
        ) + [
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(),
            'staffMembers' => StaffMember::query()->where('is_active', true)->orderBy('display_name')->get(),
            'staffAssignments' => VehicleStaffAssignment::query()->with(['bus', 'user', 'staffMember', 'creator', 'endedBy'])->latest('effective_from')->get(),
        ]);
    }

    public function storeVehicle(Request $request): RedirectResponse
    {
        $this->requirePermission($request, TransportPermissions::MANAGE_VEHICLES);
        $this->catalog->createVehicle($this->vehicleData($request), $request->user());

        return $this->back('Транспорт добавлен.');
    }

    public function updateVehicle(Request $request, Bus $bus): RedirectResponse
    {
        $this->requirePermission($request, TransportPermissions::MANAGE_VEHICLES);
        $this->catalog->updateVehicle($bus, $this->vehicleData($request, $bus), $request->user());

        return $this->back('Данные транспорта обновлены.');
    }

    public function toggleVehicle(Request $request, Bus $bus): RedirectResponse
    {
        $this->requirePermission($request, TransportPermissions::MANAGE_VEHICLES);
        $request->validate(['is_active' => ['required', 'boolean']]);
        $this->catalog->setVehicleActive($bus, $request->boolean('is_active'), $request->user());

        return $this->back('Статус транспорта обновлён.');
    }

    public function storeRoute(Request $request): RedirectResponse
    {
        $this->requirePermission($request, TransportPermissions::MANAGE_ROUTES);
        $this->catalog->createRoute($this->routeData($request), $request->user());

        return $this->back('Маршрут добавлен.');
    }

    public function updateRoute(Request $request, TransportRoute $transportRoute): RedirectResponse
    {
        $this->requirePermission($request, TransportPermissions::MANAGE_ROUTES);
        $this->catalog->updateRoute($transportRoute, $this->routeData($request, $transportRoute), $request->user());

        return $this->back('Маршрут обновлён.');
    }

    public function toggleRoute(Request $request, TransportRoute $transportRoute): RedirectResponse
    {
        $this->requirePermission($request, TransportPermissions::MANAGE_ROUTES);
        $request->validate(['is_active' => ['required', 'boolean']]);
        $this->catalog->setRouteActive($transportRoute, $request->boolean('is_active'), $request->user());

        return $this->back('Статус маршрута обновлён.');
    }

    public function assignStudent(Request $request): RedirectResponse
    {
        $this->requirePermission($request, TransportPermissions::MANAGE_ASSIGNMENTS);
        $data = $this->assignmentData($request);
        try {
            $this->students->assign(Enrollment::findOrFail($data['enrollment_id']), TransportRoute::findOrFail($data['transport_route_id']), Bus::findOrFail($data['bus_id']), $data, $request->user());
        } catch (TransportCapacityExceeded $exception) {
            throw ValidationException::withMessages(['bus_id' => $exception->getMessage()]);
        }

        return $this->back('Транспорт ученику назначен.');
    }

    public function transferStudent(Request $request, StudentTransportAssignment $assignment): RedirectResponse
    {
        $this->requirePermission($request, TransportPermissions::MANAGE_ASSIGNMENTS);
        $data = $this->assignmentData($request, false);
        $newZone = $data['pricing_zone'] ?? null;
        if ((string) $newZone !== (string) $assignment->pricing_zone) {
            throw ValidationException::withMessages(['pricing_zone' => 'Смена тарифной зоны требует проверки финансов и недоступна на этом этапе.']);
        }
        try {
            $this->students->transfer($assignment, TransportRoute::findOrFail($data['transport_route_id']), Bus::findOrFail($data['bus_id']), $data['effective_from'], $data, $request->user());
        } catch (TransportCapacityExceeded $exception) {
            throw ValidationException::withMessages(['bus_id' => $exception->getMessage()]);
        }

        return $this->back('Маршрут или транспорт изменён; история сохранена.');
    }

    public function endStudent(Request $request, StudentTransportAssignment $assignment): RedirectResponse
    {
        $this->requirePermission($request, TransportPermissions::MANAGE_ASSIGNMENTS);
        $data = $request->validate(['effective_to' => ['required', 'date'], 'change_reason' => ['required', 'string', 'max:1000']]);
        $this->students->end($assignment, $data['effective_to'], $request->user(), $data['change_reason']);

        return $this->back('Транспортное назначение завершено. Финансы не изменялись.');
    }

    public function assignStaff(Request $request): RedirectResponse
    {
        $this->requirePermission($request, TransportPermissions::MANAGE_ASSIGNMENTS);
        $data = $this->staffData($request);
        $this->staff->assign(Bus::findOrFail($data['bus_id']), $this->staffIdentity($data['identity']), $data['role'], $data['effective_from'], $data['effective_to'] ?? null, $data['weekdays'] ?? null, $request->user(), $data['change_reason'] ?? null);

        return $this->back('Сотрудник назначен на транспорт.');
    }

    public function changeStaff(Request $request, VehicleStaffAssignment $assignment): RedirectResponse
    {
        $this->requirePermission($request, TransportPermissions::MANAGE_ASSIGNMENTS);
        $data = $this->staffData($request);
        $this->staff->change($assignment, Bus::findOrFail($data['bus_id']), $this->staffIdentity($data['identity']), $data['role'], $data['effective_from'], $data['effective_to'] ?? null, $data['weekdays'] ?? null, $request->user(), $data['change_reason'] ?? null);

        return $this->back('Назначение сотрудника изменено; история сохранена.');
    }

    public function endStaff(Request $request, VehicleStaffAssignment $assignment): RedirectResponse
    {
        $this->requirePermission($request, TransportPermissions::MANAGE_ASSIGNMENTS);
        $data = $request->validate(['effective_to' => ['required', 'date'], 'change_reason' => ['required', 'string', 'max:1000']]);
        $this->staff->end($assignment, $data['effective_to'], $request->user(), $data['change_reason']);

        return $this->back('Назначение сотрудника завершено.');
    }

    private function vehicleData(Request $request, ?Bus $bus = null): array
    {
        return $request->validate([
            'vehicle_code' => ['nullable', 'string', 'max:50', Rule::unique('buses')->ignore($bus)],
            'name' => ['nullable', 'string', 'max:255'], 'plate_number' => ['required', 'string', 'max:50'],
            'student_capacity' => ['required', 'integer', 'min:1', 'max:14'],
            'passenger_capacity' => ['required', 'integer', 'gte:student_capacity'],
        ]);
    }

    private function routeData(Request $request, ?TransportRoute $route = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('transport_routes')->ignore($route)],
            'pricing_zone' => ['nullable', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function assignmentData(Request $request, bool $withEnrollment = true): array
    {
        return $request->validate(array_filter([
            'enrollment_id' => $withEnrollment ? ['required', 'integer', 'exists:enrollments,id'] : null,
            'pricing_zone' => ['nullable', 'string', 'max:100'], 'transport_route_id' => ['required', 'integer', 'exists:transport_routes,id'],
            'bus_id' => ['required', 'integer', 'exists:buses,id'], 'pickup_point' => ['nullable', 'string', 'max:500'],
            'effective_from' => ['required', 'date'], 'change_reason' => ['nullable', 'string', 'max:1000'],
        ]));
    }

    private function staffData(Request $request): array
    {
        $data = $request->validate([
            'bus_id' => ['required', 'integer', 'exists:buses,id'], 'identity' => ['nullable', 'string', 'regex:/^(staff|user):[0-9]+$/'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'role' => ['required', Rule::in(VehicleStaffAssignment::ROLES)], 'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'], 'weekdays' => ['nullable', 'array'],
            'weekdays.*' => ['integer', 'between:1,7'], 'change_reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $data['identity'] ??= isset($data['user_id']) ? 'user:'.$data['user_id'] : null;
        if (! $data['identity']) {
            throw ValidationException::withMessages(['identity' => 'Сотрудник обязателен.']);
        }

        return $data;
    }

    private function staffIdentity(string $identity): User|StaffMember
    {
        [$type, $id] = explode(':', $identity, 2);

        return $type === 'staff' ? StaffMember::findOrFail((int) $id) : User::findOrFail((int) $id);
    }

    private function back(string $message): RedirectResponse
    {
        return back()->with('success', $message);
    }

    private function requirePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission), 403);
    }
}
