<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Canonical invoice void/cancellation.
 *
 * An issued invoice is never hard-deleted or rewritten: its number, items,
 * totals, issue date and creator are preserved. Cancellation only stamps a
 * terminal 'cancelled' status plus who/when/why, and makes the invoice
 * non-collectible. An invoice that still holds money (net of refunds) cannot
 * be cancelled — the refund must be resolved first.
 */
class InvoiceCancellationService
{
    public function void(Invoice $invoice, string $reason, ?User $actor = null): Invoice
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Укажите причину аннулирования.']);
        }

        return DB::transaction(function () use ($invoice, $reason, $actor) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->isCancelled()) {
                throw ValidationException::withMessages(['status' => 'Счёт уже аннулирован.']);
            }

            // Any money still held on the invoice must be returned first, so
            // cancellation can never strand a payment in an inconsistent state.
            if (bccomp($invoice->netPaidAmount(), '0.00', 2) > 0) {
                throw ValidationException::withMessages([
                    'status' => 'Нельзя аннулировать счёт с непогашенными платежами. Сначала оформите возврат.',
                ]);
            }

            $invoice->forceFill([
                'status' => Invoice::STATUS_CANCELLED,
                // Non-collectible: excluded from outstanding-debt sums.
                'remaining_amount' => '0.00',
                'cancelled_at' => now(),
                'cancelled_by' => $actor?->id,
                'cancellation_reason' => $reason,
            ])->save();

            AuditLog::create([
                'user_id' => $actor?->id,
                'action' => 'invoice_voided',
                'model' => 'Invoice',
                'model_id' => $invoice->id,
                'new_values' => [
                    'invoice_number' => $invoice->display_number,
                    'status' => $invoice->status,
                    'reason' => $reason,
                ],
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return $invoice;
        });
    }
}
