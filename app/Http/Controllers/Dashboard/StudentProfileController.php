<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Student;
use App\Services\Students\StudentProfileCompletionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class StudentProfileController extends Controller
{
    public function __construct(private StudentProfileCompletionService $completion)
    {
        $this->middleware('permission:view students');
    }

    public function show(Student $student): View
    {
        $student->load([
            'currentEnrollment.academicYear', 'currentEnrollment.stage', 'currentEnrollment.grade',
            'currentEnrollment.schoolClass', 'currentEnrollment.enrollmentMode',
            'enrollments.academicYear', 'enrollments.serviceSubscriptions.fee', 'enrollments.serviceSubscriptions.events.creator',
            'enrollments.serviceSubscriptions.invoiceItems.invoice',
            'invoices.academicYear', 'invoices.createdBy', 'invoices.payments.cashAccount',
            'invoices.payments.creator', 'files.uploader', 'representatives', 'emergencyContacts', 'educationalNeed',
        ]);

        $completion = $this->completion->calculate($student);
        $invoices = $student->invoices->sortByDesc('created_at')->values();
        $payments = $invoices->flatMap->payments
            ->sortByDesc(fn ($payment) => $payment->paid_at ?? $payment->created_at)->values();
        $subscriptions = $student->enrollments->flatMap(function ($enrollment) {
            return $enrollment->serviceSubscriptions->map(function ($subscription) use ($enrollment) {
                $subscription->setRelation('profileEnrollment', $enrollment);
                $subscription->setAttribute('profile_snapshot', $subscription->invoiceItems
                    ->sortByDesc(fn ($item) => $item->invoice?->created_at)->first());
                return $subscription;
            });
        })->sortByDesc('created_at')->values();

        return view('dashboard.students.show', $this->payload($student, $completion, $invoices, $payments, $subscriptions));
    }

    public function print(Student $student): View
    {
        $student->load(['currentEnrollment.academicYear', 'currentEnrollment.stage', 'currentEnrollment.grade', 'currentEnrollment.schoolClass', 'currentEnrollment.enrollmentMode', 'files', 'representatives', 'emergencyContacts']);

        return view('dashboard.students.print', [
            'student' => $student,
            'enrollment' => $student->currentEnrollment,
            'completion' => $this->completion->calculate($student),
            'contacts' => $this->contacts($student),
            'photoUrl' => $this->photoUrl($student),
        ]);
    }

    private function payload(Student $student, array $completion, Collection $invoices, Collection $payments, Collection $subscriptions): array
    {
        $activeFiles = $student->files->whereNull('archived_at');
        $archivedFiles = $student->files->whereNotNull('archived_at');
        $financial = [
            'invoiced'=>$this->sum($invoices, 'total_amount'),
            'paid'=>$this->sum($payments, 'amount'),
            'remaining'=>$this->sum($invoices, 'remaining_amount'),
            'overdue'=>$this->sum($invoices->filter(fn ($invoice) => in_array($invoice->status, [Invoice::STATUS_UNPAID,Invoice::STATUS_PARTIAL], true) && $invoice->due_date?->isPast()), 'remaining_amount'),
            'upcoming_count'=>$invoices->filter(fn ($invoice) => bccomp((string) $invoice->remaining_amount, '0.00', 2) === 1 && $invoice->due_date?->isFuture())->count(),
            'invoice_count'=>$invoices->count(), 'payment_count'=>$payments->count(), 'latest_payment'=>$payments->first(),
        ];

        return [
            'profile' => [
                'student' => $student, 'photo_url' => $this->photoUrl($student), 'current_enrollment' => $student->currentEnrollment,
                'contacts' => $this->contacts($student), 'completion' => $completion, 'financial' => $financial,
                'subscriptions' => $subscriptions, 'invoices' => $invoices, 'payments' => $payments,
                'documents' => [
                    'active_count' => $activeFiles->count(), 'archived_count' => $archivedFiles->count(),
                    'latest' => $activeFiles->sortByDesc('created_at')->take(5)->values(),
                    'expiry_warnings' => $activeFiles->filter(fn ($file) => in_array($file->expiryStatus(), ['Просрочен', 'Скоро истекает'], true))->values(),
                ],
                'timeline'=>$this->timeline($student, $invoices, $payments, $subscriptions),
            ],
        ];
    }

    private function timeline(Student $student, Collection $invoices, Collection $payments, Collection $subscriptions): Collection
    {
        $events = collect();
        foreach ($student->enrollments as $enrollment) $events->push($this->event('Зачисление', $enrollment->created_at, collect([$enrollment->academicYear?->name,$enrollment->stage?->name,$enrollment->grade?->name,$enrollment->schoolClass?->name])->filter()->implode(' · ')));
        foreach ($subscriptions as $subscription) $events->push($this->event('Подключение услуги', $subscription->created_at, $subscription->fee?->name_ru));
        foreach ($invoices as $invoice) $events->push($this->event('Создание счёта', $invoice->created_at, $invoice->display_number, $invoice->createdBy?->name, route('dashboard.invoices.show',$invoice)));
        foreach ($payments as $payment) $events->push($this->event('Платёж', $payment->paid_at ?? $payment->created_at, ($payment->payment_number ?: 'Платёж').' · '.$payment->amount.' EGP', $payment->creator?->name, route('dashboard.invoices.show',$payment->invoice_id)));

        $fileIds = $student->files->pluck('id');
        AuditLog::with('user')->where(function ($query) use ($student,$fileIds) {
            $query->where(fn ($query) => $query->where('model','Student')->where('model_id',$student->id));
            if ($fileIds->isNotEmpty()) $query->orWhere(fn ($query) => $query->where('model','StudentFile')->whereIn('model_id',$fileIds));
        })->get()->each(function ($log) use ($events) {
            $label = match ($log->action) {
                'profile_updated' => 'Обновление личного дела', 'document_uploaded' => 'Добавление документа',
                'document_archived' => 'Архивирование документа', 'document_restored' => 'Восстановление документа',
                'submitted_for_review' => 'Отправка на проверку', 'registration_completed' => 'Регистрация завершена',
                'created' => $log->model === 'Student' ? 'Создание профиля ученика' : 'Добавление документа',
                'updated' => 'Изменение данных ученика', default => null,
            };
            if ($label) $events->push($this->event($label,$log->created_at,null,$log->user?->name));
        });
        return $events->filter(fn ($event) => $event['at'])->sortByDesc('at')->values();
    }

    private function event(string $label, $at, ?string $description=null, ?string $actor=null, ?string $url=null): array
    { return compact('label','at','description','actor','url'); }
    private function sum(Collection $records, string $field): string
    { return $records->reduce(fn (string $sum,$record) => bcadd($sum,(string)$record->{$field},2),'0.00'); }
    private function photoUrl(Student $student): ?string
    { $disk=Storage::disk(config('filesystems.uploads.public')); return $student->photo && $disk->exists($student->photo) ? $disk->url($student->photo) : null; }
    private function contacts(Student $student): array
    {
        $contacts = $student->documents ?? [];
        foreach (['father', 'mother'] as $relationship) {
            if ($normalized = $student->representativeData($relationship)) {
                $contacts[$relationship] = $normalized;
            }
        }
        if ($emergency = $student->emergencyContactData()) {
            $contacts['emergency'] = $emergency;
        }

        return $contacts;
    }
}
