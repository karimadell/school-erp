<?php

namespace App\Services\Finance;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PromiseToPay;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PromiseToPayService
{
    public function create(Student $student, array $data, User $actor): PromiseToPay
    {
        $invoice = isset($data['invoice_id']) ? Invoice::findOrFail($data['invoice_id']) : null;
        if ($invoice && $invoice->student_id !== $student->id) {
            throw ValidationException::withMessages(['invoice_id' => 'Счёт принадлежит другому ученику.']);
        }
        $amount = bcadd((string) $data['promised_amount'], '0', 2);
        if (bccomp($amount, '0.00', 2) <= 0) {
            throw ValidationException::withMessages(['promised_amount' => 'Обещанная сумма должна быть больше нуля.']);
        }

        return PromiseToPay::create([
            'student_id' => $student->id, 'invoice_id' => $invoice?->id,
            'promised_amount' => $amount, 'expected_payment_date' => $data['expected_payment_date'],
            'note' => $data['note'] ?? null, 'status' => PromiseToPay::STATUS_OPEN,
            'created_by' => $actor->id,
        ]);
    }

    public function cancel(PromiseToPay $promise, User $actor, ?string $note = null): PromiseToPay
    {
        return DB::transaction(function () use ($promise, $actor, $note): PromiseToPay {
            $promise = PromiseToPay::query()->lockForUpdate()->findOrFail($promise->id);
            if ($promise->status !== PromiseToPay::STATUS_OPEN) {
                throw ValidationException::withMessages(['promise' => 'Закрытое обещание нельзя отменить повторно.']);
            }
            $promise->forceFill([
                'status' => PromiseToPay::STATUS_CANCELLED, 'cancelled_at' => now(),
                'cancelled_by' => $actor->id, 'cancellation_note' => $note,
            ])->save();

            return $promise;
        });
    }

    public function fulfill(PromiseToPay $promise, InvoicePayment $payment, User $actor): PromiseToPay
    {
        return DB::transaction(function () use ($promise, $payment, $actor): PromiseToPay {
            $promise = PromiseToPay::query()->lockForUpdate()->findOrFail($promise->id);
            $payment->loadMissing('invoice');
            if ($promise->status === PromiseToPay::STATUS_FULFILLED && $promise->invoice_payment_id === $payment->id) {
                return $promise;
            }
            if ($promise->status !== PromiseToPay::STATUS_OPEN || $payment->invoice->student_id !== $promise->student_id
                || ($promise->invoice_id && $payment->invoice_id !== $promise->invoice_id)) {
                throw ValidationException::withMessages(['invoice_payment_id' => 'Платёж не может исполнить это обещание.']);
            }
            if (PromiseToPay::where('invoice_payment_id', $payment->id)->whereKeyNot($promise->id)->exists()) {
                throw ValidationException::withMessages(['invoice_payment_id' => 'Платёж уже исполняет другое обещание.']);
            }
            $promise->forceFill([
                'status' => PromiseToPay::STATUS_FULFILLED, 'fulfilled_at' => now(),
                'fulfilled_by' => $actor->id, 'invoice_payment_id' => $payment->id,
            ])->save();

            return $promise;
        });
    }
}
