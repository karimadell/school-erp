<?php

namespace App\Services\Finance;

use App\Models\CreditApplicationCoveragePeriod;
use App\Models\InstallmentCoveragePeriod;
use App\Models\Invoice;
use App\Models\StudentCredit;
use App\Models\StudentCreditApplication;
use App\Models\StudentCreditApplicationItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentCreditService
{
    /**
     * @param  ?array<int, array{invoice_item_id: int, amount: string, periods?: array<int, array{installment_coverage_period_id: int, amount: string}>}>  $allocations
     *         Finance V2, Phase 2D corrective pass #3 (P0 Blocker 1E —
     *         credit application coverage-period linkage). StudentCreditApplication
     *         is invoice-level only by design (confirmed by reading the
     *         model/migration directly — no item-level concept existed
     *         before this) — this is a NEW, optional, additive
     *         capability, mirroring InvoicePaymentService::record()'s
     *         own $allocations parameter shape one level further (item,
     *         then optionally period):
     *           - Omitted (null, every existing caller — TariffAdjustmentService's
     *             credit posting, any manual whole-invoice credit
     *             application): unchanged behaviour exactly as before —
     *             zero StudentCreditApplicationItem/CreditApplicationCoveragePeriod
     *             rows, the credit stays invoice-level only. Reads as
     *             explicitly 'unallocated' at the coverage-period
     *             granularity wherever that specific item/period is
     *             queried — never guessed.
     *           - Supplied: each line's invoice_item_id must belong to
     *             this invoice, amounts must be positive and sum to
     *             exactly $amount. Each line's OPTIONAL 'periods' array,
     *             when present, must belong to THAT SAME item's own
     *             coverage (ownership, mirroring Blocker 1C) and sum to
     *             exactly that line's own amount; each period is
     *             row-locked and checked against its own net-settled
     *             capacity (mirroring Blocker 1A) before being recorded
     *             — a credit application can no longer over-settle an
     *             already-settled period any more than a payment can. A
     *             line with NO 'periods' entry creates only the
     *             item-level StudentCreditApplicationItem row — that
     *             item's coverage then reads as 'unallocated' at the
     *             period granularity (credit reached the item, but which
     *             period(s) it settled is genuinely unrecorded), never
     *             guessed at.
     */
    public function apply(StudentCredit $credit, Invoice $invoice, string $amount, string $idempotencyKey, User $actor, ?array $allocations = null): StudentCreditApplication
    {
        if (! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Некорректный ключ повторного запроса.']);
        }
        $amount = bcadd($amount, '0', 2);
        if (bccomp($amount, '0.00', 2) <= 0) {
            throw ValidationException::withMessages(['amount' => 'Сумма применения кредита должна быть больше нуля.']);
        }

        $allocationMeaning = $this->canonicalCreditAllocations($allocations);

        return DB::transaction(function () use ($credit, $invoice, $amount, $idempotencyKey, $actor, $allocations, $allocationMeaning): StudentCreditApplication {
            $existing = StudentCreditApplication::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ($existing->student_credit_id !== $credit->id || $existing->invoice_id !== $invoice->id || bccomp($existing->amount, $amount, 2) !== 0
                    || $this->canonicalStoredCreditAllocations($existing) !== $allocationMeaning) {
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

            if ($allocations !== null) {
                $this->validateCreditAllocations($allocations, $invoice, $amount);
            }

            $application = StudentCreditApplication::create([
                'student_credit_id' => $credit->id, 'student_id' => $credit->student_id,
                'invoice_id' => $invoice->id, 'amount' => $amount, 'idempotency_key' => $idempotencyKey,
                'applied_by' => $actor->id, 'applied_at' => now(),
            ]);

            foreach ($allocations ?? [] as $line) {
                $itemAmount = bcadd((string) $line['amount'], '0', 2);
                $applicationItem = StudentCreditApplicationItem::create([
                    'student_credit_application_id' => $application->id,
                    'invoice_item_id' => (int) $line['invoice_item_id'],
                    'amount' => $itemAmount,
                ]);

                foreach ($line['periods'] ?? [] as $periodLine) {
                    $period = InstallmentCoveragePeriod::query()->lockForUpdate()->findOrFail((int) $periodLine['installment_coverage_period_id']);
                    $periodAmount = bcadd((string) $periodLine['amount'], '0', 2);

                    // Mirrors InvoicePaymentService::linkAllocationToCoveragePeriod()'s
                    // own A (period capacity, net of existing activity)
                    // — already validated for ownership/sum in
                    // validateCreditAllocations() above, re-checked here
                    // under the lock for the same race-safety reason.
                    if ($period->amount !== null) {
                        $netAlready = $period->netSettledAmount();
                        if (bccomp(bcadd($netAlready, $periodAmount, 2), (string) $period->amount, 2) > 0) {
                            throw ValidationException::withMessages([
                                'allocations' => 'Сумма превышает остаток по периоду покрытия услуги — период уже урегулирован.',
                            ]);
                        }
                    }

                    CreditApplicationCoveragePeriod::create([
                        'student_credit_application_item_id' => $applicationItem->id,
                        'installment_coverage_period_id' => $period->id,
                        'amount' => $periodAmount,
                    ]);
                }
            }

            $consumed = bcadd((string) $credit->consumed_amount, $amount, 2);
            $available = bcsub((string) $credit->original_amount, $consumed, 2);
            $credit->forceFill([
                'consumed_amount' => $consumed, 'available_amount' => $available,
                'status' => bccomp($available, '0.00', 2) === 0 ? StudentCredit::STATUS_CONSUMED : StudentCredit::STATUS_PARTIAL,
            ])->save();

            return $application;
        });
    }

    private function canonicalCreditAllocations(?array $allocations): ?array
    {
        if ($allocations === null) {
            return null;
        }

        return collect($allocations)->map(function (array $line): array {
            $periods = collect($line['periods'] ?? [])->map(fn (array $period) => [
                'installment_coverage_period_id' => (int) $period['installment_coverage_period_id'],
                'amount' => bcadd((string) $period['amount'], '0', 2),
            ])->sortBy(fn ($period) => sprintf('%020d|%s', $period['installment_coverage_period_id'], $period['amount']))->values()->all();

            return [
                'invoice_item_id' => (int) $line['invoice_item_id'],
                'amount' => bcadd((string) $line['amount'], '0', 2),
                'periods' => $periods,
            ];
        })->sortBy(fn ($line) => sprintf('%020d|%s|%s', $line['invoice_item_id'], $line['amount'], json_encode($line['periods'])))->values()->all();
    }

    private function canonicalStoredCreditAllocations(StudentCreditApplication $application): ?array
    {
        $items = StudentCreditApplicationItem::query()->where('student_credit_application_id', $application->id)->get();
        if ($items->isEmpty()) {
            return null;
        }

        $raw = $items->map(function (StudentCreditApplicationItem $item): array {
            return [
                'invoice_item_id' => $item->invoice_item_id,
                'amount' => (string) $item->amount,
                'periods' => CreditApplicationCoveragePeriod::query()
                    ->where('student_credit_application_item_id', $item->id)->get()
                    ->map(fn (CreditApplicationCoveragePeriod $period) => [
                        'installment_coverage_period_id' => $period->installment_coverage_period_id,
                        'amount' => (string) $period->amount,
                    ])->all(),
            ];
        })->all();

        return $this->canonicalCreditAllocations($raw);
    }

    /**
     * @param  array<int, array{invoice_item_id: int, amount: string, periods?: array<int, array{installment_coverage_period_id: int, amount: string}>}>  $allocations
     */
    private function validateCreditAllocations(array $allocations, Invoice $invoice, string $amount): void
    {
        if ($allocations === []) {
            throw ValidationException::withMessages(['allocations' => 'Укажите распределение кредита по услугам.']);
        }

        $validItemIds = $invoice->items()->pluck('id');
        $sum = '0.00';
        foreach ($allocations as $line) {
            if (! isset($line['invoice_item_id'], $line['amount'])) {
                throw ValidationException::withMessages(['allocations' => 'Некорректные данные распределения кредита.']);
            }
            $itemId = (int) $line['invoice_item_id'];
            if (! $validItemIds->contains($itemId)) {
                throw ValidationException::withMessages(['allocations' => 'Строка счёта не принадлежит указанному счёту.']);
            }
            $lineAmount = bcadd((string) $line['amount'], '0', 2);
            if (bccomp($lineAmount, '0.00', 2) <= 0) {
                throw ValidationException::withMessages(['allocations' => 'Сумма распределения должна быть больше нуля.']);
            }
            $sum = bcadd($sum, $lineAmount, 2);

            if (isset($line['periods'])) {
                $periodSum = '0.00';
                foreach ($line['periods'] as $periodLine) {
                    if (! isset($periodLine['installment_coverage_period_id'], $periodLine['amount'])) {
                        throw ValidationException::withMessages(['allocations' => 'Некорректные данные распределения по периодам покрытия.']);
                    }
                    $period = InstallmentCoveragePeriod::find((int) $periodLine['installment_coverage_period_id']);
                    if (! $period) {
                        throw ValidationException::withMessages(['allocations' => 'Период покрытия не найден.']);
                    }
                    // Blocker 1C — ownership: the period's own coverage
                    // must belong to THIS SAME invoice item, never a
                    // different Fee's coverage.
                    $coverageItemId = $period->coverage()->value('invoice_item_id');
                    if ($coverageItemId !== $itemId) {
                        throw ValidationException::withMessages(['allocations' => 'Период покрытия принадлежит другой услуге, чем распределение кредита.']);
                    }
                    $periodAmount = bcadd((string) $periodLine['amount'], '0', 2);
                    if (bccomp($periodAmount, '0.00', 2) <= 0) {
                        throw ValidationException::withMessages(['allocations' => 'Сумма распределения по периоду должна быть больше нуля.']);
                    }
                    $periodSum = bcadd($periodSum, $periodAmount, 2);
                }
                if (bccomp($periodSum, $lineAmount, 2) !== 0) {
                    throw ValidationException::withMessages(['allocations' => 'Сумма распределения по периодам должна совпадать с суммой по строке счёта.']);
                }
            }
        }

        if (bccomp($sum, $amount, 2) !== 0) {
            throw ValidationException::withMessages(['allocations' => 'Сумма распределения должна совпадать с суммой применения кредита.']);
        }
    }
}
