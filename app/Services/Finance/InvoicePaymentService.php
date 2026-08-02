<?php

namespace App\Services\Finance;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoicePaymentService
{
    public function record(
        int $invoiceId,
        int $cashAccountId,
        string $amount,
        string $paymentMethod,
        string $idempotencyKey,
        ?User $actor = null,
        ?string $reference = null,
        ?string $notes = null,
    ): InvoicePayment {
        $amount = $this->money($amount);
        if (! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Укажите корректный ключ повторного запроса.']);
        }
        if (! in_array($paymentMethod, ['cash', 'bank', 'card', 'transfer'], true)) {
            throw ValidationException::withMessages(['payment_method' => 'Выбран недопустимый способ оплаты.']);
        }
        $hash = hash('sha256', implode('|', [$invoiceId, $cashAccountId, $amount, $paymentMethod]));

        return DB::transaction(function () use ($invoiceId, $cashAccountId, $amount, $paymentMethod, $idempotencyKey, $actor, $reference, $notes, $hash) {
            $existing = InvoicePayment::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $this->replay($existing, $hash);
            }

            $invoice = Invoice::query()->lockForUpdate()->find($invoiceId);
            if (! $invoice) {
                throw ValidationException::withMessages(['invoice_id' => 'Счёт не найден.']);
            }

            // Recheck after serializing on the invoice row so concurrent retries
            // cannot pass the first lookup together.
            $existing = InvoicePayment::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $this->replay($existing, $hash);
            }

            if (bccomp($amount, '0.00', 2) <= 0) {
                throw ValidationException::withMessages(['amount' => 'Сумма платежа должна быть больше нуля.']);
            }

            $paid = $this->money((string) InvoicePayment::query()->where('invoice_id', $invoice->id)->sum('amount'));
            $remaining = bcsub($this->money($invoice->total_amount), $paid, 2);
            if (bccomp($remaining, '0.00', 2) <= 0) {
                throw ValidationException::withMessages(['amount' => 'Счёт уже полностью оплачен.']);
            }
            if (bccomp($amount, $remaining, 2) > 0) {
                throw ValidationException::withMessages(['amount' => 'Платёж не может превышать остаток по счёту.']);
            }

            $account = CashAccount::query()->lockForUpdate()->find($cashAccountId);
            if (! $account) {
                throw ValidationException::withMessages(['cash_account_id' => 'Касса не найдена.']);
            }
            if (! $account->is_active) {
                throw ValidationException::withMessages(['cash_account_id' => 'Выбранная касса неактивна.']);
            }

            $payment = InvoicePayment::create([
                'invoice_id' => $invoice->id,
                'cash_account_id' => $account->id,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'paid_at' => now(),
                'reference' => $reference,
                'notes' => $notes,
                'created_by' => $actor?->id,
                'idempotency_key' => $idempotencyKey,
                'idempotency_hash' => $hash,
            ]);
            $payment->payment_number = InvoicePayment::numberFor($payment->id, $payment->created_at->format('Y'));
            $payment->save();

            CashTransaction::create([
                'cash_account_id' => $account->id,
                'invoice_payment_id' => $payment->id,
                'amount' => $amount,
                'type' => CashTransaction::TYPE_IN,
                'category' => CashTransaction::CATEGORY_INCOME,
                'description' => "Платёж {$payment->payment_number} по счёту {$invoice->display_number}",
            ]);

            $newPaid = bcadd($paid, $amount, 2);
            $newRemaining = bcsub($this->money($invoice->total_amount), $newPaid, 2);
            $invoice->forceFill([
                'paid_amount' => $newPaid,
                'remaining_amount' => $newRemaining,
                'status' => bccomp($newRemaining, '0.00', 2) === 0 ? Invoice::STATUS_PAID : Invoice::STATUS_PARTIAL,
                'paid_at' => bccomp($newRemaining, '0.00', 2) === 0 ? now() : null,
                'payment_method' => $paymentMethod,
                'cash_account_id' => $account->id,
            ])->save();

            return $payment->fresh(['cashTransaction']);
        });
    }

    private function replay(InvoicePayment $payment, string $hash): InvoicePayment
    {
        if (! hash_equals((string) $payment->idempotency_hash, $hash)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Ключ повторного запроса уже использован для другого платежа.']);
        }

        return $payment;
    }

    private function money(string $value): string
    {
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages(['amount' => 'Укажите корректную сумму платежа.']);
        }

        return bcadd($value, '0', 2);
    }
}
