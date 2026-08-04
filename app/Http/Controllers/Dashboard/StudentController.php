<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\SchoolClass;
use App\Models\AuditLog;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class StudentController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view students')->only(['index']);
        $this->middleware('permission:manage students')->only(['show']);
        $this->middleware('permission:create students')->only(['create', 'store']);
        $this->middleware('permission:update students')->only(['edit', 'update']);
        $this->middleware('permission:delete students')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $students = Student::with('class')
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->q;

                $query->where(function ($sub) use ($q) {
                    $sub->where('first_name_ru', 'like', "%{$q}%")
                        ->orWhere('last_name_ru', 'like', "%{$q}%")
                        ->orWhere('patronymic_ru', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('gender'), function ($query) use ($request) {
                $query->where('gender', $request->gender);
            })
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('dashboard.students.index', compact('students'));
    }

    public function create()
    {
        $classes = SchoolClass::query()
            ->select('classes.*')
            ->join('grades', 'grades.id', '=', 'classes.grade_id')
            ->orderByRaw('CASE WHEN grades.level IS NULL THEN 1 ELSE 0 END')
            ->orderBy('grades.level')
            ->orderBy('grades.id')
            ->orderBy('classes.code')
            ->orderBy('classes.id')
            ->get();

        return view('dashboard.students.create', compact('classes'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'class_id' => 'required|exists:classes,id',

            'last_name_ru' => 'required|string|max:255',
            'first_name_ru' => 'required|string|max:255',
            'patronymic_ru' => 'nullable|string|max:255',

            'birth_date' => 'nullable|date',
            'gender' => 'nullable|in:male,female',

            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'nationality' => 'nullable|string|max:100',
            'address' => 'nullable|string',

            'photo' => 'nullable|image|max:2048',
            'documents.*' => 'nullable|file|max:4096',
        ], [
            'last_name_ru.required' => 'Укажите фамилию ученика.',
            'first_name_ru.required' => 'Укажите имя ученика.',
        ]);

        $data = $request->only([
            'class_id',
            'last_name_ru',
            'first_name_ru',
            'patronymic_ru',
            'birth_date',
            'gender',
            'phone',
            'email',
            'nationality',
            'address',
        ]);

        if ($request->hasFile('photo')) {
            $data['photo'] = $request->file('photo')->store('students/photos', 'public');
        }

        if ($request->hasFile('documents')) {
            $files = [];

            foreach ($request->file('documents') as $file) {
                $files[] = $file->store('students/documents', 'public');
            }

            $data['documents'] = $files;
        }

        Student::create($data);

        return redirect()
            ->route('dashboard.students.index')
            ->with('success', __('students.created_success'));
    }

    public function show($id)
    {
        $student = Student::with([
            'class',
            'currentEnrollment.academicYear',
            'currentEnrollment.stage',
            'currentEnrollment.grade',
            'currentEnrollment.schoolClass',
            'enrollments.academicYear',
            'enrollments.stage',
            'enrollments.grade',
            'enrollments.schoolClass',
            'enrollments.serviceSubscriptions.fee.prices',
            'enrollments.mealSubscriptions.mealPlan',
            'invoices.academicYear',
            'invoices.items.fee',
            'invoices.payments.cashAccount',
        ])
            ->findOrFail($id);

        $currentEnrollment = $student->currentEnrollment;
        $invoices = $student->invoices->sortByDesc('created_at')->values();
        $payments = $invoices->flatMap->payments->sortByDesc(fn ($payment) => $payment->paid_at ?? $payment->created_at)->values();
        $outstandingAmount = $this->sumMoney($invoices->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL]), 'remaining_amount');
        $financial = [
            'invoiced' => $this->sumMoney($invoices, 'total_amount'),
            'paid' => $this->sumMoney($payments, 'amount'),
            'outstanding' => $outstandingAmount,
            'upcoming' => $invoices->filter(fn ($invoice) => in_array($invoice->status, [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL], true)
                && $invoice->due_date?->gte(today()))->values(),
            'status' => bccomp($outstandingAmount, '0.00', 2) > 0 ? 'debt' : 'clear',
        ];
        $subscriptions = $student->enrollments
            ->flatMap(fn ($enrollment) => $enrollment->serviceSubscriptions->map(function ($subscription) use ($enrollment) {
                $metadata = $subscription->metadata ?? [];
                $tariff = $subscription->fee?->prices
                    ->where('academic_year_id', $enrollment->academic_year_id)
                    ->where('is_active', true)
                    ->sortByDesc('start_date')
                    ->first(function ($price) use ($metadata) {
                        foreach (['grade_group', 'payment_period', 'size', 'item', 'option_type', 'option_value'] as $field) {
                            if (filled($metadata[$field] ?? null) && (string) $price->{$field} !== (string) $metadata[$field]) {
                                return false;
                            }
                        }

                        return true;
                    });
                $subscription->setAttribute('profile_tariff', $tariff);
                $subscription->setRelation('profileEnrollment', $enrollment);

                return $subscription;
            }))
            ->sortByDesc('start_date')->values();
        $documents = is_array($student->documents) ? $student->documents : [];
        $documentAttachments = collect($documents)->flatMap(function ($value, $key) {
            $paths = is_array($value) ? $value : [$value];

            return collect($paths)
                ->filter(fn ($path) => is_string($path) && Storage::disk('public')->exists($path))
                ->map(fn ($path) => [
                    'label' => is_string($key) ? match ($key) {
                        'identity_document' => 'Свидетельство о рождении / паспорт',
                        'medical' => 'Медицинский документ',
                        'photos' => 'Фотография',
                        default => 'Другое вложение',
                    } : 'Другое вложение',
                    'name' => basename($path),
                    'url' => Storage::disk('public')->url($path),
                ]);
        })->values();
        $timeline = $this->studentTimeline($student, $invoices, $payments);

        return view('dashboard.students.show', compact(
            'student', 'currentEnrollment', 'invoices', 'payments', 'financial', 'subscriptions',
            'documents', 'documentAttachments', 'timeline'
        ));
    }

    private function sumMoney(Collection $records, string $field): string
    {
        return $records->reduce(
            fn (string $sum, $record) => bcadd($sum, (string) $record->{$field}, 2),
            '0.00'
        );
    }

    private function studentTimeline(Student $student, Collection $invoices, Collection $payments): Collection
    {
        $events = collect();
        foreach ($student->enrollments as $enrollment) {
            $events->push([
                'at' => $enrollment->created_at ?? $enrollment->date,
                'type' => 'Зачисление',
                'text' => collect([$enrollment->academicYear?->name, $enrollment->stage?->name, $enrollment->grade?->name, $enrollment->schoolClass?->name])->filter()->implode(' · '),
            ]);
        }
        foreach ($invoices as $invoice) {
            $events->push(['at' => $invoice->created_at, 'type' => 'Счёт', 'text' => $invoice->display_number.' · '.number_format((float) $invoice->total_amount, 2, '.', ' ').' EGP']);
        }
        foreach ($payments as $payment) {
            $events->push(['at' => $payment->paid_at ?? $payment->created_at, 'type' => 'Платёж', 'text' => ($payment->payment_number ?? 'Платёж').' · '.number_format((float) $payment->amount, 2, '.', ' ').' EGP']);
        }

        $enrollmentIds = $student->enrollments->pluck('id');
        AuditLog::query()->where(function ($query) use ($student, $enrollmentIds) {
            $query->where(fn ($query) => $query->where('model', 'Student')->where('model_id', $student->id));
            if ($enrollmentIds->isNotEmpty()) {
                $query->orWhere(fn ($query) => $query->where('model', 'Enrollment')->whereIn('model_id', $enrollmentIds));
            }
        })->get()->each(function ($log) use ($events) {
            $changed = collect($log->new_values ?? [])->keys();
            $type = $changed->contains('status') ? 'Изменение статуса' : ($changed->intersect(['stage_id', 'grade_id', 'class_id', 'academic_year_id'])->isNotEmpty() ? 'Изменение учебных данных' : 'Изменение профиля');
            $events->push(['at' => $log->created_at, 'type' => $type, 'text' => match ($log->action) {'created' => 'Запись создана', 'deleted' => 'Запись удалена', default => 'Данные обновлены'}]);
        });

        return $events->filter(fn ($event) => $event['at'])->sortByDesc('at')->values();
    }

    public function edit($id)
    {
        $student = Student::findOrFail($id);
        $classes = SchoolClass::orderBy('name_ru')->get();

        return view('dashboard.students.edit', compact('student', 'classes'));
    }

    public function update(Request $request, $id)
    {
        $student = Student::findOrFail($id);

        $request->validate([
            'class_id' => 'required|exists:classes,id',

            'last_name_ru' => 'required|string|max:255',
            'first_name_ru' => 'required|string|max:255',
            'patronymic_ru' => 'nullable|string|max:255',

            'birth_date' => 'nullable|date',
            'gender' => 'nullable|in:male,female',

            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'nationality' => 'nullable|string|max:100',
            'address' => 'nullable|string',

            'photo' => 'nullable|image|max:2048',
            'documents.*' => 'nullable|file|max:4096',
        ], [
            'last_name_ru.required' => 'Укажите фамилию ученика.',
            'first_name_ru.required' => 'Укажите имя ученика.',
        ]);

        $data = $request->only([
            'class_id',
            'last_name_ru',
            'first_name_ru',
            'patronymic_ru',
            'birth_date',
            'gender',
            'phone',
            'email',
            'nationality',
            'address',
        ]);

        if ($request->hasFile('photo')) {
            if ($student->photo) {
                Storage::disk('public')->delete($student->photo);
            }

            $data['photo'] = $request->file('photo')->store('students/photos', 'public');
        }

        if ($request->hasFile('documents')) {
            if (!empty($student->documents)) {
                foreach ((array) $student->documents as $file) {
                    Storage::disk('public')->delete($file);
                }
            }

            $files = [];

            foreach ($request->file('documents') as $file) {
                $files[] = $file->store('students/documents', 'public');
            }

            $data['documents'] = $files;
        }

        $student->update($data);

        return redirect()
            ->route('dashboard.students.index')
            ->with('success', __('students.updated_success'));
    }

    public function destroy($id)
    {
        $student = Student::findOrFail($id);

        if ($student->photo) {
            Storage::disk('public')->delete($student->photo);
        }

        if (!empty($student->documents)) {
            foreach ((array) $student->documents as $file) {
                Storage::disk('public')->delete($file);
            }
        }

        $student->delete();

        return redirect()
            ->route('dashboard.students.index')
            ->with('success', __('students.deleted_success'));
    }
}
