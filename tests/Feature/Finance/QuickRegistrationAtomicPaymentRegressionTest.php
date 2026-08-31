<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\Invoice;
use App\Models\InvoiceInstallment;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\PaymentPlan;
use App\Models\Student;
use App\Models\StudentServiceSubscription;
use App\Services\Finance\CashSessionService;
use App\Services\Finance\InvoiceIssuanceService;

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
        // Finance V2, Phase 2B: PaymentPlan must be explicitly assigned to
        // the Fee (and the Fee must allow 'custom_plan') to be usable.
        $fee->billingPeriods()->create(['billing_period' => 'custom_plan']);
        $fee->assignedPaymentPlans()->attach($plan->id);
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

    // ----- 10. string-typed fee_id (real browser shape) with paid_now on the -----
    // ----- FIRST, larger line and 0 on the SECOND, smaller line — the exact --
    // ----- UATDIAG3 shape that exposed the line-to-item matching bug --------

    /**
     * Regression for the 2026-08-29 UAT finding: every HTML form field is a
     * string over HTTP, but the previous line-to-item matching in
     * QuickStudentRegistrationService::register() compared fee_id with
     * strict === against InvoiceItem's (uncast, int) fee_id column. That
     * always failed to match, and Collection::search()'s `false` result
     * silently coerced to array index 0 — every line after the first
     * quietly reused the *first* submitted service's paid_now. It only
     * surfaced as a visible error when an earlier line's paid_now exceeded
     * a later line's real amount (registration 7000 before tuition 4500);
     * the existing test above puts paid_now on the *second* line, which
     * hides the bug because index 0's paid_now is always 0.00 there.
     *
     * fee_id is deliberately cast to string in the payload — passing the
     * model's native int id would skip past this bug entirely, since
     * Laravel's test client never round-trips through real HTTP.
     */
    public function test_string_fee_ids_with_paid_now_on_the_first_of_two_lines_does_not_misroute_amounts(): void
    {
        $structure = $this->structure();
        $registration = Fee::create(['name_ru' => 'Регистрационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => '7000.00', 'is_active' => true]);
        $tuition = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => '4500.00', 'is_active' => true]);
        $account = CashAccount::operating();
        app(CashSessionService::class)->open($account, $this->accountant);

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $registration, [
            'services' => [
                ['fee_id' => (string) $registration->id, 'quantity' => 1, 'paid_now' => '7000.00'],
                ['fee_id' => (string) $tuition->id, 'quantity' => 1, 'paid_now' => '0.00'],
            ],
            'payment_method' => 'cash',
        ]));

        // 1: no false "payment exceeds calculated cost" error.
        $response->assertSessionHasNoErrors()->assertRedirect();

        // 2: per-item financial fields — each line keeps its own figures,
        // not a value borrowed from the other line.
        $registrationItem = InvoiceItem::where('fee_id', $registration->id)->sole();
        $this->assertSame('7000.00', $registrationItem->amount);
        $this->assertSame('7000.00', $registrationItem->paid_amount);
        $this->assertSame('0.00', $registrationItem->remaining_amount);

        $tuitionItem = InvoiceItem::where('fee_id', $tuition->id)->sole();
        $this->assertSame('4500.00', $tuitionItem->amount);
        $this->assertSame('0.00', $tuitionItem->paid_amount);
        $this->assertSame('4500.00', $tuitionItem->remaining_amount);

        // 3: invoice-level result.
        $invoice = Invoice::sole();
        $this->assertSame('11500.00', $invoice->total_amount);
        $this->assertSame('7000.00', $invoice->paid_amount);
        $this->assertSame('4500.00', $invoice->remaining_amount);
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->status);

        // 4: payment/cash behavior — exactly one of each, exactly 7000.
        $this->assertSame(1, InvoicePayment::count());
        $this->assertSame('7000.00', InvoicePayment::sole()->amount);
        $this->assertSame(1, CashTransaction::count());
        $this->assertSame('7000.00', CashTransaction::sole()->amount);
    }

    // ----- 11. atomicity still holds for the fixed (int-cast) matching path -----

    /**
     * The fix changes *how* lines are matched, not the transaction
     * boundary. Reuses the same string-fee_id, front-loaded-payment shape
     * as test 10 but with a closed cash drawer, so the payment step itself
     * fails after the (now-correct) per-item matching has already run —
     * proving the whole thing (student, enrollment, invoice, items) still
     * rolls back together, and confirming idempotency-key generation
     * ({@see \Illuminate\Support\Str::uuid()} in register()) is never
     * reached/persisted on a rolled-back attempt.
     */
    public function test_atomic_rollback_still_holds_with_string_fee_ids_and_front_loaded_payment(): void
    {
        $structure = $this->structure();
        $registration = Fee::create(['name_ru' => 'Регистрационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => '7000.00', 'is_active' => true]);
        $tuition = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => '4500.00', 'is_active' => true]);
        // Deliberately no CashSessionService::open() — the operating drawer
        // exists but no shift is open on it, so payment recording fails
        // after items/matching have already succeeded.

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $registration, [
            'services' => [
                ['fee_id' => (string) $registration->id, 'quantity' => 1, 'paid_now' => '7000.00'],
                ['fee_id' => (string) $tuition->id, 'quantity' => 1, 'paid_now' => '0.00'],
            ],
            'payment_method' => 'cash',
        ]))->assertSessionHasErrors('payment_method');

        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('invoice_payments', 0);
        $this->assertDatabaseCount('cash_transactions', 0);

        // Retrying after fixing the blocking condition still creates
        // exactly one set of records — idempotency/retry behavior
        // unchanged by the matching fix.
        app(CashSessionService::class)->open(CashAccount::operating(), $this->accountant);
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $registration, [
            'services' => [
                ['fee_id' => (string) $registration->id, 'quantity' => 1, 'paid_now' => '7000.00'],
                ['fee_id' => (string) $tuition->id, 'quantity' => 1, 'paid_now' => '0.00'],
            ],
            'payment_method' => 'cash',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, Student::count());
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, InvoicePayment::count());
        $this->assertSame(1, CashTransaction::count());
    }

    // ----- 12. an unmatched fee_id fails loudly instead of defaulting to index 0 -----

    /**
     * Under correct operation, $invoice->items and $normalizedServices are
     * always built from the same submitted fee_ids, so they can only
     * diverge if something upstream is broken — exactly the class of bug
     * this fix guards against. InvoiceIssuanceService is mocked here to
     * hand back an invoice whose item references a fee_id that was never
     * submitted, which is the only way to force that divergence without
     * reintroducing the original type-mismatch bug. Proves the guard
     * throws instead of silently reading normalizedServices[0].
     */
    public function test_an_unmatched_fee_id_fails_loudly_instead_of_defaulting_to_service_index_zero(): void
    {
        $structure = $this->structure();
        $registration = $this->fee();
        $unrelatedFee = Fee::create(['name_ru' => 'Постороннее', 'category' => Fee::CATEGORY_OTHER, 'amount' => '999.00', 'is_active' => true]);
        [$year] = $structure;

        $this->mock(InvoiceIssuanceService::class, function ($mock) use ($unrelatedFee, $year) {
            $mock->shouldReceive('issue')->once()->andReturnUsing(function ($student) use ($unrelatedFee, $year) {
                $invoice = Invoice::create([
                    'student_id' => $student->id, 'academic_year_id' => $year->id, 'currency' => 'EGP',
                    'subtotal_amount' => '999.00', 'total_amount' => '999.00', 'paid_amount' => '0.00',
                    'remaining_amount' => '999.00', 'status' => Invoice::STATUS_UNPAID,
                    'due_date' => $year->end_date, 'created_by' => 1,
                ]);
                InvoiceItem::create([
                    'invoice_id' => $invoice->id, 'fee_id' => $unrelatedFee->id,
                    'description' => $unrelatedFee->name_ru, 'unit_price' => '999.00', 'quantity' => 1,
                    'amount' => '999.00', 'paid_amount' => '0.00', 'remaining_amount' => '999.00',
                ]);

                return $invoice->fresh();
            });
        });

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload($structure, $registration, [
            'services' => [['fee_id' => (string) $registration->id, 'quantity' => 1, 'paid_now' => '0.00']],
            'payment_method' => '',
        ]))->assertSessionHasErrors('services');

        // The whole attempt rolled back — including the student/enrollment
        // created before the (mocked) issue() call — not just skipped the
        // mismatched line.
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
    }
}
