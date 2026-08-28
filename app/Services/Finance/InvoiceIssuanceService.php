<?php

namespace App\Services\Finance;

use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentPlan;
use App\Models\Student;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Canonical persistence path for issuing a student invoice.
 *
 * Extracted verbatim from StudentInvoiceController::store() so the same
 * transactional issuance logic can be reused (e.g. by mass billing) without
 * duplicating it. Behaviour is intentionally unchanged: academic-year
 * consistency, enrollment validation, server-side tariff resolution, invoice
 * numbering, invoice-item snapshots, compatibility invoice_fee rows,
 * installment creation, registration-fee duplicate protection, audit logging,
 * totals, EGP currency rules, and the transaction/row-lock behaviour are all
 * preserved exactly.
 *
 * The invoice's issue date continues to be carried by created_at (set to the
 * selected pricing date); there is no separate issue_date column because that
 * field already serves that role for existing records.
 */
class InvoiceIssuanceService
{
    public function __construct(
        private InvoiceCalculationService $calculator,
        private InstallmentPlanService $plans,
    ) {
    }

    /**
     * Issue an invoice for the given student from validated request data.
     *
     * @param  array<string, mixed>  $data  Output of StoreInvoiceRequest::validated().
     * @param  ?Closure(Fee, array<string, mixed>, Enrollment): ?int  $subscriptionResolver
     *         Invoked once per line item that has no existing active
     *         StudentServiceSubscription, so the caller can create one and
     *         return its id to attach — this service deliberately has no
     *         knowledge of StudentServiceSubscriptionService, MealSubscription,
     *         or any other Admissions-domain concern; that stays entirely
     *         with the caller's resolver. Passing null (the default)
     *         preserves the original behaviour of only ever linking an
     *         already-existing subscription, never creating one.
     */
    public function issue(Student $student, array $data, User $actor, ?string $ip = null, ?string $userAgent = null, ?Closure $subscriptionResolver = null): Invoice
    {
        if ((int) $data['student_id'] !== $student->id) {
            throw ValidationException::withMessages(['student_id' => 'Выбранный ученик не соответствует адресу формы.']);
        }

        return DB::transaction(function () use ($data, $student, $actor, $ip, $userAgent, $subscriptionResolver) {
            $student = Student::query()->lockForUpdate()->findOrFail($student->id);
            $year = AcademicYear::query()->lockForUpdate()->findOrFail($data['academic_year_id']);
            $enrollment = Enrollment::query()->where('student_id', $student->id)->where('academic_year_id', $year->id)->where('is_active', true)->lockForUpdate()->first();
            if (! $year->is_active || ! $enrollment) {
                throw ValidationException::withMessages(['academic_year_id' => 'Активное зачисление на выбранный учебный год не найдено.']);
            }

            $registrationFeeIds = Fee::whereIn('id', collect($data['items'])->pluck('fee_id'))->where('category', Fee::CATEGORY_REGISTRATION)->pluck('id');
            if ($registrationFeeIds->isNotEmpty() && InvoiceItem::whereHas('invoice', fn ($query) => $query->where('student_id', $student->id)->where('academic_year_id', $year->id))->whereIn('fee_id', $registrationFeeIds)->exists()) {
                throw ValidationException::withMessages(['fees' => 'Регистрационный взнос уже начислен ученику за этот учебный год.']);
            }

            // Perf (504 investigation, 2026-08-29): Fee was previously reloaded
            // with a fresh findOrFail() per line item here, and again per line
            // item in the post-creation loop below — 2 queries per line on top
            // of InvoiceCalculationService's own batched fetch. One batched
            // fetch, reused by both loops, removes that duplication without
            // changing the not-found behaviour (still throws
            // ModelNotFoundException for an unknown fee_id).
            $feesById = Fee::whereIn('id', collect($data['items'])->pluck('fee_id')->unique())->get()->keyBy('id');
            $resolveFee = function (int $feeId) use ($feesById): Fee {
                return $feesById->get($feeId) ?? throw (new ModelNotFoundException())->setModel(Fee::class, [$feeId]);
            };

            $items = collect($data['items'])->map(function (array $item) use ($enrollment, $resolveFee) {
                $fee = $resolveFee((int) $item['fee_id']);
                if (in_array($fee->category, [Fee::CATEGORY_TUITION, Fee::CATEGORY_TUITION_REGULAR, Fee::CATEGORY_TUITION_FAMILY, Fee::CATEGORY_TUITION_EXTERNAL], true)
                    && blank($item['grade_group'] ?? null)) {
                    $item['grade_id'] = $enrollment->grade_id;
                }
                $item['enrollment_mode_id'] = $enrollment->enrollment_mode_id;

                return $item;
            })->all();
            // Discount fields are part of the shared StoreInvoiceRequest shape
            // (used by both the classic and per-student create screens) but
            // were previously only ever honoured by the classic controller's
            // own inline calculation. Reading them here — they are null/absent
            // for every caller that never offered a discount UI — makes the
            // classic screen's discount feature survive its migration onto
            // this service instead of being silently dropped.
            $calculation = $this->calculator->calculate($items, $data['discount_type'] ?? null, $data['discount_value'] ?? null, '0', $data['pricing_date'], $year->id);
            $invoiceData = [
                'student_id'=>$student->id, 'academic_year_id'=>$year->id, 'customer_name'=>$student->full_name,
                'currency'=>'EGP', 'subtotal_amount'=>$calculation['subtotal'], 'total_amount'=>$calculation['total_amount'],
                'discount_type'=>$data['discount_type'] ?? null, 'discount_value'=>$data['discount_value'] ?? '0.00',
                'discount_amount'=>$calculation['discount_amount'], 'paid_amount'=>'0.00', 'remaining_amount'=>$calculation['total_amount'],
                'status'=>Invoice::STATUS_UNPAID, 'due_date'=>$data['due_date'],
                'created_by'=>$actor->id,
            ];
            if (Schema::hasColumn('invoices', 'note')) {
                $invoiceData['note'] = $data['notes'] ?? null;
            }
            $invoice = new Invoice($invoiceData);
            $invoice->created_at = Carbon::parse($data['pricing_date'])->startOfDay();
            $invoice->save();
            $invoice->invoice_number = Invoice::numberFor($invoice->id, $invoice->created_at->format('Y'));
            $invoice->save();

            // TEMP DIAGNOSTIC (504 investigation, 2026-08-28) — remove after test.
            Log::info('quick_registration.checkpoint', [
                'stage' => 'E_invoice_row_created',
                'trace_id' => Context::get('qr_trace_id'),
                'elapsed_ms' => round((microtime(true) - Context::get('qr_start_at', microtime(true))) * 1000, 1),
            ]);

            // Perf (504 investigation, 2026-08-29): pre-load every active
            // subscription for this enrollment once instead of one
            // `->where('fee_id', ...)->first()` query per line item. Updated
            // in-loop (not just read) so a fee_id repeated across two line
            // items — same edge case the original per-item query handled —
            // still sees the subscription created for the first occurrence.
            $activeSubscriptionsByFee = $enrollment->serviceSubscriptions()->where('status', 'active')->get()->keyBy('fee_id');
            // Batched into one attach() call after the loop when every line
            // has a distinct fee_id (the overwhelming common case — the Quick
            // Registration UI can't submit the same fee twice). If a caller's
            // payload ever does repeat a fee_id, fall back to the original
            // one-attach()-per-line behaviour so pivot rows are never
            // silently collapsed.
            $feePivotRows = [];
            $hasDuplicateFeeLines = collect($calculation['line_items'])->pluck('fee_id')->duplicates()->isNotEmpty();

            foreach ($calculation['line_items'] as $line) {
                $selection = collect($items)->firstWhere('fee_id', $line['fee_id']);
                $fee = $resolveFee((int) $line['fee_id']);
                $subscriptionId = $activeSubscriptionsByFee->get($line['fee_id'])?->id;
                if (! $subscriptionId && $subscriptionResolver) {
                    $subscriptionId = $subscriptionResolver($fee, $selection, $enrollment);
                    $activeSubscriptionsByFee->put($line['fee_id'], (object) ['id' => $subscriptionId]);
                }
                InvoiceItem::create([
                    'invoice_id'=>$invoice->id, 'fee_id'=>$line['fee_id'], 'subscription_id'=>$subscriptionId,
                    'description'=>$line['description'], 'unit_price'=>$line['unit_price'], 'quantity'=>$line['quantity'],
                    'amount'=>$line['amount'], 'paid_amount'=>'0.00', 'remaining_amount'=>$line['amount'],
                    'is_non_refundable'=>$fee->is_non_refundable,
                    'metadata'=>collect($selection)->except(['fee_id','quantity'])->merge([
                        'pricing_date'=>$data['pricing_date'], 'tariff_valid_from'=>$line['tariff_valid_from'], 'tariff_valid_to'=>$line['tariff_valid_to'],
                    ])->filter(fn ($value) => filled($value))->all(),
                ]);
                $pivotData = [
                    'amount'=>$line['amount'], 'item'=>$line['item'], 'size'=>$line['size'],
                    'option_type'=>$line['option_type'], 'option_value'=>$line['option_value'],
                ];
                if ($hasDuplicateFeeLines) {
                    $invoice->fees()->attach($line['fee_id'], $pivotData);
                } else {
                    $feePivotRows[$line['fee_id']] = $pivotData;
                }
            }
            if ($feePivotRows) {
                $invoice->fees()->attach($feePivotRows);
            }

            if ($data['payment_type'] === 'plan') {
                $plan = PaymentPlan::active()->lockForUpdate()->findOrFail($data['payment_plan_id']);
                $this->plans->generate($invoice, $plan, $data['pricing_date']);
            } else {
                $this->plans->generateSingle($invoice, $data['due_date']);
            }

            // TEMP DIAGNOSTIC — remove after test.
            Log::info('quick_registration.checkpoint', [
                'stage' => 'F_installments_subscriptions_generated',
                'trace_id' => Context::get('qr_trace_id'),
                'elapsed_ms' => round((microtime(true) - Context::get('qr_start_at', microtime(true))) * 1000, 1),
            ]);

            AuditLog::create(['user_id'=>$actor->id,'action'=>'created','model'=>'Invoice','model_id'=>$invoice->id,'new_values'=>['invoice_number'=>$invoice->invoice_number,'total_amount'=>$invoice->total_amount],'ip'=>$ip,'user_agent'=>$userAgent]);

            return $invoice;
        });
    }
}
