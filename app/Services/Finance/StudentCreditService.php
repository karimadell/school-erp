<?php

namespace App\Services\Finance;

use App\Models\Invoice;
use App\Models\StudentCredit;
use App\Models\StudentCreditApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentCreditService
{
    public function apply(StudentCredit $credit, Invoice $invoice, string $amount, string $idempotencyKey, User $actor): StudentCreditApplication
    {
        if (! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Некорректный ключ повторного запроса.']);
        }
        $amount = bcadd($amount, '0', 2);
        if (bccomp($amount, '0.00', 2) <= 0) {
            throw ValidationException::withMessages(['amount' => 'Сумма применения кредита должна быть больше нуля.']);
        }

        return DB::transaction(function () use ($credit, $invoice, $amount, $idempotencyKey, $actor): StudentCreditApplication {
            $existing = StudentCreditApplication::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ($existing->student_credit_id !== $credit->id || $existing->invoice_id !== $invoice->id || bccomp($existing->amount, $amount, 2) !== 0) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Ключ уже использован для другого применения кредита.']);
                }

                return $existing;
            }

            $credit = StudentCredit::query()->lockForUpdate()->findOrFail($credit->id);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($credit->student_id !== $invoice->student_id) {
                throw ValidationException::withMessages(['invoice_id' => 'Кредит и счёт принадлежат разным ученикам.']);
            }
            $alreadyApplied = bcadd((string) StudentCreditApplication::where('invoice_id', $invoice->id)->sum('amount'), '0', 2);
            $invoiceLiability = bcsub((string) $invoice->remaining_amount, $alreadyApplied, 2);
            if (bccomp($amount, (string) $credit->available_amount, 2) > 0 || bccomp($amount, $invoiceLiability, 2) > 0) {
                throw ValidationException::withMessages(['amount' => 'Сумма превышает доступный кредит или непогашенную ответственность по счёту.']);
            }

            $application = StudentCreditApplication::create([
                'student_credit_id' => $credit->id, 'student_id' => $credit->student_id,
                'invoice_id' => $invoice->id, 'amount' => $amount, 'idempotency_key' => $idempotencyKey,
                'applied_by' => $actor->id, 'applied_at' => now(),
            ]);
            $consumed = bcadd((string) $credit->consumed_amount, $amount, 2);
            $available = bcsub((string) $credit->original_amount, $consumed, 2);
            $credit->forceFill([
                'consumed_amount' => $consumed, 'available_amount' => $available,
                'status' => bccomp($available, '0.00', 2) === 0 ? StudentCredit::STATUS_CONSUMED : StudentCredit::STATUS_PARTIAL,
            ])->save();

            return $application;
        });
    }
}
