<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoiceInstallment;
use App\Models\InvoiceItem;
use App\Models\PaymentPlan;
use App\Models\ServiceCoverage;
use Illuminate\Support\Str;

/**
 * Finance Workspace corrective PR #1 — Classic Student Invoice
 * (StudentInvoiceController) now mints an idempotency key on create() and
 * passes it through to InvoiceIssuanceService::issue() exactly like every
 * other issuance entry point (Quick Registration, Charge & Collect,
 * InvoicePaymentService). Before this pass, store() called issue() without
 * a key at all, so a rapid double-submit/back-button/retry could create a
 * genuine duplicate invoice.
 *
 * This is the HTTP-level wiring proof for THIS controller only. The
 * service's own idempotency invariants (payload-hash mismatch rejection,
 * concurrent-insert handling, associative-key-reordering, etc.) are already
 * exhaustively covered by InvoiceIssuanceIdempotencyTest and are not
 * repeated here.
 *
 * Note: Classic Invoice's StoreInvoiceRequest deliberately restricts
 * payment_type to one_time/plan only (calendar billing is Quick-
 * Registration-only — see StoreInvoiceRequest's own comment), so this
 * controller never reaches InvoiceIssuanceService::createAutomaticCoverage()
 * and therefore never creates a ServiceCoverage row for any submission
 * through this entry point. The ServiceCoverage assertions below confirm
 * that stays true (zero, not duplicated) rather than exercising coverage
 * duplication itself — that is covered where it IS reachable, in
 * InvoiceIssuanceIdempotencyTest's calendar-billed scenarios.
 */
class ClassicInvoiceIdempotencyTest extends FinanceOperationsTestCase
{
    private function planWithTwoInstallments(): PaymentPlan
    {
        $plan = PaymentPlan::create(['name_ru' => 'План на 2 этапа', 'is_active' => true]);
        $plan->installments()->create(['name_ru' => 'Этап 1', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '60']);
        $plan->installments()->create(['name_ru' => 'Этап 2', 'sequence' => 2, 'offset_days' => 30, 'percentage' => '40']);

        return $plan;
    }

    private function planFee(PaymentPlan $plan): Fee
    {
        $fee = Fee::create(['name_ru' => 'Обучение (план)', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'custom_plan']);
        $fee->assignedPaymentPlans()->attach($plan->id);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'yearly', 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        return $fee;
    }

    private function payload(Fee $fee, PaymentPlan $plan, string $key): array
    {
        return [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'pricing_date' => '2026-09-01', 'due_date' => '2027-01-01',
            'fees' => [$fee->id], 'payment_type' => 'plan', 'payment_plan_id' => $plan->id,
            'idempotency_key' => $key,
        ];
    }

    public function test_create_form_carries_a_valid_idempotency_key(): void
    {
        $response = $this->actingAs($this->accountant)->get(route('dashboard.students.invoices.create', $this->student));

        $response->assertOk();
        $key = $response->viewData('idempotencyKey');
        $this->assertTrue(Str::isUuid($key), 'the create() view must receive a real UUID, not a placeholder');
        $response->assertSee('name="idempotency_key" value="'.$key.'"', false);
    }

    public function test_first_submission_creates_exactly_one_invoice(): void
    {
        $plan = $this->planWithTwoInstallments();
        $fee = $this->planFee($plan);

        $this->actingAs($this->accountant)
            ->post(route('dashboard.students.invoices.store', $this->student), $this->payload($fee, $plan, (string) Str::uuid()))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, InvoiceItem::count());
        $this->assertSame(2, InvoiceInstallment::count());
        $this->assertSame(0, ServiceCoverage::count());
    }

    public function test_repeating_the_identical_post_with_the_same_key_reuses_the_original_invoice_and_creates_nothing_new(): void
    {
        $plan = $this->planWithTwoInstallments();
        $fee = $this->planFee($plan);
        $key = (string) Str::uuid();
        $payload = $this->payload($fee, $plan, $key);

        $first = $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student), $payload);
        $first->assertSessionHasNoErrors()->assertRedirect();
        $firstInvoiceId = Invoice::sole()->id;

        // Same key, same form data — the exact shape a double back-button
        // submit or a retried request produces.
        $second = $this->post(route('dashboard.students.invoices.store', $this->student), $payload);
        $second->assertSessionHasNoErrors();
        $second->assertRedirect(route('dashboard.invoices.show', $firstInvoiceId));

        $this->assertSame(1, Invoice::count(), 'replay must not create a second Invoice');
        $this->assertSame(1, InvoiceItem::count(), 'replay must not duplicate InvoiceItems');
        $this->assertSame(2, InvoiceInstallment::count(), 'replay must not duplicate installments (still exactly the one plan schedule)');
        $this->assertSame(0, ServiceCoverage::count());
    }

    public function test_a_new_idempotency_key_creates_a_genuinely_separate_invoice(): void
    {
        $plan = $this->planWithTwoInstallments();
        $fee = $this->planFee($plan);

        $this->actingAs($this->accountant)
            ->post(route('dashboard.students.invoices.store', $this->student), $this->payload($fee, $plan, (string) Str::uuid()))
            ->assertSessionHasNoErrors();
        // A second, genuinely new invoice for the same student/fee is only
        // possible because CATEGORY_TUITION carries no single-open-invoice
        // guard (unlike Registration Fee, proven separately below) — this
        // isolates "does a new key work at all" from any unrelated business
        // rule that might otherwise block a second submission.
        $this->post(route('dashboard.students.invoices.store', $this->student), $this->payload($fee, $plan, (string) Str::uuid()))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Invoice::count());
        $this->assertSame(2, InvoiceItem::count());
        $this->assertSame(4, InvoiceInstallment::count());
    }

    public function test_missing_idempotency_key_is_rejected_safely(): void
    {
        $plan = $this->planWithTwoInstallments();
        $fee = $this->planFee($plan);
        $payload = $this->payload($fee, $plan, (string) Str::uuid());
        unset($payload['idempotency_key']);

        $this->actingAs($this->accountant)
            ->post(route('dashboard.students.invoices.store', $this->student), $payload)
            ->assertSessionHasErrors('idempotency_key');

        $this->assertSame(0, Invoice::count());
    }

    public function test_invalid_idempotency_key_is_rejected_safely(): void
    {
        $plan = $this->planWithTwoInstallments();
        $fee = $this->planFee($plan);
        $payload = $this->payload($fee, $plan, 'not-a-uuid');

        $this->actingAs($this->accountant)
            ->post(route('dashboard.students.invoices.store', $this->student), $payload)
            ->assertSessionHasErrors('idempotency_key');

        $this->assertSame(0, Invoice::count());
    }

    public function test_registration_fee_duplicate_protection_is_unchanged_by_a_fresh_key(): void
    {
        $registration = Fee::create(['name_ru' => 'Организационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => '1.00', 'is_active' => true]);
        FeePrice::create(['fee_id' => $registration->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'yearly', 'amount' => '500.00', 'currency' => 'EGP', 'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true]);
        $payload = fn (string $key) => [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'pricing_date' => '2026-09-01', 'due_date' => '2027-01-01',
            'fees' => [$registration->id], 'payment_type' => 'one_time',
            'idempotency_key' => $key,
        ];

        $this->actingAs($this->accountant)
            ->post(route('dashboard.students.invoices.store', $this->student), $payload((string) Str::uuid()))
            ->assertSessionHasNoErrors();

        // Deliberately a DIFFERENT key — proves the registration-duplicate
        // guard fires on its own business rule, not merely because a key
        // repeated (that scenario is covered by the replay test above).
        $this->post(route('dashboard.students.invoices.store', $this->student), $payload((string) Str::uuid()))
            ->assertSessionHasErrors('fees');

        $this->assertSame(1, Invoice::count());
    }
}
