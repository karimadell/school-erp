<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\PhysicalClassroom;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Dashboard-native counterpart to
 * App\Filament\Resources\Classrooms\ClassroomResource (model:
 * PhysicalClassroom, table `classrooms` — distinct from the legacy,
 * orphaned App\Models\ClassRoom / `class_rooms` table documented in
 * docs/IMPLEMENTATION_READINESS_ROADMAP.md item E1). Same permission gates
 * ('view timetable' / 'manage timetable'), same validation
 * (PhysicalClassroom::booted()). No delete route: the model's own
 * `deleting` guard always throws, matching canDelete() === false.
 */
class ClassroomController extends Controller
{
    public function __construct()
    {
        $this->middleware(function (Request $request, $next) {
            abort_unless(auth()->user()?->hasAnyPermission(['view timetable', 'manage timetable']), 403);

            return $next($request);
        });

        $this->middleware('permission:manage timetable')->only(['create', 'store', 'edit', 'update']);
    }

    public function index(Request $request): View
    {
        $classrooms = PhysicalClassroom::with('academicYear')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');
                $query->where(function ($query) use ($search) {
                    $query->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('building', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('academic_year_id'), fn ($query) => $query->where('academic_year_id', $request->integer('academic_year_id')))
            ->when($request->filled('room_type'), fn ($query) => $query->where('room_type', $request->string('room_type')))
            ->orderBy('code')
            ->paginate(15)
            ->withQueryString();

        $academicYears = AcademicYear::orderByDesc('start_date')->get();

        return view('dashboard.classrooms.index', compact('classrooms', 'academicYears'));
    }

    public function create(): View
    {
        $academicYears = AcademicYear::orderByDesc('start_date')->get();

        return view('dashboard.classrooms.create', compact('academicYears'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        PhysicalClassroom::create($data);

        return redirect()
            ->route('dashboard.classrooms.index')
            ->with('success', __('classroom.created_success'));
    }

    public function edit(PhysicalClassroom $classroom): View
    {
        $academicYears = AcademicYear::orderByDesc('start_date')->get();

        return view('dashboard.classrooms.edit', compact('classroom', 'academicYears'));
    }

    public function update(Request $request, PhysicalClassroom $classroom): RedirectResponse
    {
        $data = $this->validated($request);

        $classroom->update($data);

        return redirect()
            ->route('dashboard.classrooms.index')
            ->with('success', __('classroom.updated_success'));
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'building' => ['nullable', 'string', 'max:255'],
            'floor' => ['nullable', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'capacity' => ['required', 'integer', 'min:1'],
            'room_type' => ['required', 'string', 'in:' . implode(',', PhysicalClassroom::TYPES)],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
