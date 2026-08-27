<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\InvoiceInstallment;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\PaymentPlan;
use App\Models\Student;
use App\Models\StudentServiceSubscription;
use App\Services\Finance\CashSessionService;

/**
 * Single-page "Register + Invoice + Confirm Payment" hardening.
 *
 * Audit finding (read-only, evidence-based): QuickStudentRegistrationService::
 * register() already wraps student, enrollment, invoice issuance (via
 * InvoiceIssuanceService::issue()) and payment recording (via
 * InvoicePaymentService::record()) in a single outer DB::transaction(). Both
 * inner services open their own DB::transaction() too, but Laravel nests
 * those as SAVEPOINTs — any Throwable from the payment step propagates all
 * the way out and rolls back the whole thing, including the student and
 * invoice. These tests prove that guarantee holds, prove the paid_now
 * allocation is never silently inflated to the full invoice total, and prove
 * the resolved (not submitted) cash account is always the one charged.
 */
class QuickRegistrationAtomicPaymentRegressionTest extends QuickRegistrationUxTestCase
{
    // ----- 1. a clean one-time registration creates exactly one of each record ---

    public function test_successful_one_time_registration_creates_exactly_the_expected_records(): void
    {
        $structure = $this->structure();
        $fee = $this->fee();
        $account = CashAccount::operating();
        app(CashSessionService::class)->open($account, $this->accountant);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '1000.00']],
            'payment_method' => 'cash',
        ]));

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, Student::count());
        $this->assertSame(1, Enrollment::count());
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, InvoiceItem::count());
        $this->assertGreaterThanOrEqual(1, InvoiceInstallment::count());
        $this->assertSame(1, InvoicePayment::count());
        $this->assertSame(1, CashTransaction::count());
        $invoice = Invoice::sole();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame('0.00', $invoice->remaining_amount);
    }

    // ----- 2. entering a partial amount never becomes the full invoice total -----

    public function test_partial_amount_paid_now_is_recorded_exactly_and_never_inflated(): void
    {
        // Mirrors the reported UAT figures: total 77670, entered now 7000,
        // expected remaining 70670 — regardless of how the total is split
        // across service lines.
        $structure = $this->structure();
        $registration = $this->fee('Регистрационный взнос', \App\Models\Fee::CATEGORY_REGISTRATION);
        $tuition = \App\Models\Fee::create(['name_ru' => 'Обучение', 'category' => \App\Models\Fee::CATEGORY_TUITION, 'amount' => '76670.00', 'is_active' => true]);
        $account = CashAccount::operating();
        app(CashSessionService::class)->open($account, $this->accountant);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $registration, [
            'services' => [
                ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '0.00'],
                ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '7000.00'],
            ],
            'payment_method' => 'cash',
        ]));

        $response->assertSessionHasNoErrors()->assertRedirect();
        $invoice = Invoice::sole();
        $this->assertSame('77670.00', $invoice->total_amount);
        $this->assertSame('7000.00', $invoice->paid_amount);
        $this->assertSame('70670.00', $invoice->remaining_amount);
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->status);
        $this->assertSame('7000.00', InvoicePayment::sole()->amount);
    }

    // ----- 3. cash always resolves to the canonical operating account -----

    public function test_cash_payment_resolves_the_canonical_operating_account_regardless_of_submitted_id(): void
    {
        $structure = $this->structure();
        $fee = $this->fee();
        $operating = CashAccount::operating();
        app(CashSessionService::class)->open($operating, $this->accountant);
        $decoy = CashAccount::create(['name' => 'Другая касса', 'type' => 'cash', 'is_active' => true]);

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '1000.00']],
            'payment_method' => 'cash',
            'cash_account_id' => $decoy->id,
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($operating->id, InvoicePayment::sole()->cash_account_id);
        $this->assertSame($operating->id, CashTransaction::sole()->cash_account_id);
    }

    // ----- 4. amount > 0 can never post against "Без оплаты" -----

    public function test_a_positive_amount_cannot_be_submitted_without_a_payment_method(): void
    {
        $structure = $this->structure();
        $fee = $this->fee();

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '500.00']],
        ]))->assertSessionHasErrors('payment_method');

        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_payments', 0);
        $this->assertDatabaseCount('cash_transactions', 0);
    }

    // ----- 5. a payment-step failure rolls back everything already staged --------

    public function test_a_payment_failure_rolls_back_student_enrollment_invoice_and_all_related_records(): void
    {
        $structure = $this->structure();
        $fee = $this->fee();
        // The owner holding account is active (so form-request validation
        // passes) but InvoicePaymentService::record() must still refuse it
        // for a student payment — a genuine service-layer failure that only
        // surfaces after the student/enrollment/invoice have already been
        // built inside the same outer transaction.
        $owner = CashAccount::owner();
        $this->assertNotNull($owner, 'canonical owner account must exist for this test to be meaningful');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '500.00']],
            'payment_method' => 'card',
            'cash_account_id' => $owner->id,
        ]))->assertSessionHasErrors('cash_account_id');

        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('student_service_subscriptions', 0);
        $this->assertDatabaseCount('invoice_installments', 0);
        $this->assertDatabaseCount('invoice_payments', 0);
        $this->assertDatabaseCount('cash_transactions', 0);
    }

    // ----- 6. a closed cash drawer produces zero persisted business records ------

    public function test_a_closed_cash_session_produces_zero_persisted_quick_registration_records(): void
    {
        $structure = $this->structure();
        $fee = $this->fee();
        // Deliberately no CashSessionService::open() call — the operating
        // drawer exists (seeded canonically) but no shift is open on it.

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '500.00']],
            'payment_method' => 'cash',
        ]))->assertSessionHasErrors('payment_method');

        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('student_service_subscriptions', 0);
        $this->assertDatabaseCount('invoice_installments', 0);
        $this->assertDatabaseCount('invoice_payments', 0);
        $this->assertDatabaseCount('cash_transactions', 0);
    }

    // ----- 7. retrying after a failed attempt cannot create duplicates -----------

    public function test_retrying_after_a_failed_transaction_creates_exactly_one_set_of_records(): void
    {
        $structure = $this->structure();
        $fee = $this->fee();
        $payload = $this->payload($structure, $fee, [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '500.00']],
            'payment_method' => 'cash',
        ]);

        // First attempt: drawer is closed, everything must roll back.
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $payload)
            ->assertSessionHasErrors('payment_method');
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('invoices', 0);

        // Fix the blocking condition and resubmit the identical payload.
        app(CashSessionService::class)->open(CashAccount::operating(), $this->accountant);
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $payload)
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, Student::count());
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, InvoicePayment::count());
        $this->assertSame(1, CashTransaction::count());
    }

    // ----- 8. an installment plan only records what was actually collected now ---

    public function test_installment_plan_registration_records_only_the_payment_collected_now(): void
    {
        $structure = $this->structure();
        $fee = $this->fee('Обучение', \App\Models\Fee::CATEGORY_TUITION);
        $plan = PaymentPlan::create(['name_ru' => 'Два взноса', 'is_active' => true]);
        $plan->installments()->create(['name_ru' => 'Первый взнос', 'sequence' => 1, 'offset_days' => 0, 'percentage' => '40']);
        $plan->installments()->create(['name_ru' => 'Второй взнос', 'sequence' => 2, 'offset_days' => 60, 'percentage' => '60']);
        $account = CashAccount::operating();
        app(CashSessionService::class)->open($account, $this->accountant);

        // Fee amount defaults to 1000.00 — first installment (40%) is 400.00;
        // pay exactly that, not the full invoice.
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'payment_type' => 'plan', 'payment_plan_id' => $plan->id,
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '400.00']],
            'payment_method' => 'cash',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $invoice = Invoice::sole();
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->status);
        $this->assertSame(1, InvoicePayment::count());
        $installments = $invoice->installments()->orderBy('sequence')->get();
        $this->assertSame('400.00', $installments[0]->paid_amount);
        $this->assertSame('0.00', $installments[0]->remaining_amount);
        // The second, future installment must remain untouched.
        $this->assertSame('0.00', $installments[1]->paid_amount);
        $this->assertSame('600.00', $installments[1]->remaining_amount);
    }

    // ----- 9. the success screen exposes invoice/payment/receipt identifiers -----

    public function test_the_success_screen_exposes_invoice_and_payment_identifiers(): void
    {
        $structure = $this->structure();
        $fee = $this->fee();
        $account = CashAccount::operating();
        app(CashSessionService::class)->open($account, $this->accountant);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $fee, [
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '1000.00']],
            'payment_method' => 'cash',
        ]));
        $response->assertSessionHasNoErrors()->assertRedirect();

        $invoice = Invoice::sole();
        $payment = InvoicePayment::sole();
        $summary = $this->actingAs($this->accountant)->get($response->headers->get('Location'))->assertOk();
        $summary->assertSee($invoice->invoice_number)
            ->assertSee($payment->payment_number)
            ->assertSee('1000.00')
            ->assertSee('0.00')
            ->assertSee(route('dashboard.payments.receipt', $payment));
    }
}
