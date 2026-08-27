<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Services\Finance\MassBillingExecutionService;
use App\Services\Finance\MassBillingPreviewService;
use Illuminate\Support\Str;

/**
 * Phase 2, step 5 — cross-entry-point parity. Every live "normal business"
 * issuance path (as opposed to TariffAdjustmentService's narrow correction
 * postings, deliberately out of scope — see the Phase 2 audit, decision 3)
 * must satisfy the same invariants, because all of them now converge on
 * InvoiceIssuanceService. This does not force identical inputs through
 * paths whose business purpose differs (Quick Registration collects payment
 * inline, the Enrollment Wizard never does, Mass Billing bills many
 * students at once) — it tests only what must be true of every one of them.
 */
class InvoiceIssuanceParityTest extends MassBillingTestCase
{
    private function assertNormalIssuanceInvariants(Invoice $invoice): void
    {
        $this->assertGreaterThanOrEqual(1, $invoice->items()->count(), 'every normal invoice has at least one InvoiceItem');
        $this->assertGreaterThanOrEqual(1, $invoice->installments()->count(), 'every normal invoice has an installment row at issuance');
        $this->assertDatabaseHas('audit_logs', [
            'model' => 'Invoice', 'model_id' => $invoice->id, 'action' => 'created',
        ]);
    }

    private function registrationFee(): Fee
    {
        $fee = Fee::create(['name_ru' => 'Организационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => '1.00', 'is_active' => true]);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => '500.00', 'currency' => 'EGP', 'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'payment_period' => 'yearly', 'is_active' => true]);

        return $fee;
    }

    public function test_classic_invoice_create_satisfies_the_shared_invariants_and_the_registration_guard(): void
    {
        $student = $this->enrolledStudent(suffix: 'Classic');
        $registration = $this->registrationFee();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.invoices.store'), [
            'student_id' => $student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-01-01', 'fees' => [$registration->id],
        ]);
        $response->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        // Pricing came from the resolver (500.00), not a client-supplied or hardcoded value.
        $this->assertSame('500.00', $invoice->total_amount);
        $this->assertNormalIssuanceInvariants($invoice);

        $second = $this->actingAs($this->accountant)->post(route('dashboard.invoices.store'), [
            'student_id' => $student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-01-01', 'fees' => [$registration->id],
        ]);
        $second->assertSessionHasErrors('fees');
        $this->assertSame(1, Invoice::count());
    }

    public function test_modern_per_student_invoice_create_satisfies_the_shared_invariants_and_the_registration_guard(): void
    {
        $student = $this->enrolledStudent(suffix: 'Modern');
        $registration = $this->registrationFee();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $student), [
            'student_id' => $student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-01-01', 'pricing_date' => '2026-09-01',
            'fees' => [$registration->id], 'payment_type' => 'one_time',
        ]);
        $response->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame('500.00', $invoice->total_amount);
        $this->assertNormalIssuanceInvariants($invoice);

        $second = $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $student), [
            'student_id' => $student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-01-01', 'pricing_date' => '2026-09-01',
            'fees' => [$registration->id], 'payment_type' => 'one_time',
        ]);
        $second->assertSessionHasErrors('fees');
        $this->assertSame(1, Invoice::count());
    }

    public function test_quick_registration_satisfies_the_shared_invariants_and_the_registration_guard(): void
    {
        $registration = $this->registrationFee();
        $second = Fee::create(['name_ru' => 'Другой регистрационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => '1.00', 'is_active' => true]);
        FeePrice::create(['fee_id' => $second->id, 'academic_year_id' => $this->year->id, 'amount' => '750.00', 'currency' => 'EGP', 'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'payment_period' => 'yearly', 'is_active' => true]);
        $class = $this->classA;

        $payload = [
            'student_last_name_ru' => 'Сидоров', 'student_first_name_ru' => 'Пётр', 'phone' => '+20 100 111 2233',
            'registration_date' => '2026-09-01', 'academic_year_id' => $this->year->id,
            'stage_id' => $this->stage->id, 'grade_id' => $this->grade->id, 'class_id' => $class->id,
            'enrollment_mode_id' => $this->mode->id,
            'services' => [['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '0.00']],
        ];
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $payload);
        $response->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame('500.00', $invoice->total_amount);
        $this->assertNormalIssuanceInvariants($invoice);

        // The request-level "only one registration fee per submission" guard —
        // still active, untouched by Phase 2 (see architecture Q4/Q5).
        $duplicatePayload = $payload;
        $duplicatePayload['student_last_name_ru'] = 'Кузнецов';
        $duplicatePayload['services'] = [
            ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '0.00'],
            ['fee_id' => $second->id, 'quantity' => 1, 'paid_now' => '0.00'],
        ];
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $duplicatePayload)
            ->assertSessionHasErrors('services');
        $this->assertSame(1, Invoice::count());
    }

    public function test_charge_and_collect_satisfies_the_shared_invariants_and_the_registration_guard(): void
    {
        $student = $this->enrolledStudent(suffix: 'Charge');
        $registration = $this->registrationFee();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.students.charge.store', $student), [
            'academic_year_id' => $this->year->id, 'fee_id' => $registration->id, 'quantity' => 1,
            'due_date' => '2027-01-01', 'pricing_date' => '2026-09-01',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $response->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame('500.00', $invoice->total_amount);
        $this->assertNormalIssuanceInvariants($invoice);

        $second = $this->actingAs($this->accountant)->post(route('dashboard.students.charge.store', $student), [
            'academic_year_id' => $this->year->id, 'fee_id' => $registration->id, 'quantity' => 1,
            'due_date' => '2027-01-01', 'pricing_date' => '2026-09-01',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        // ChargeAndCollectService's own duplicate-open-invoice guard fires
        // first here (same-service open invoice), which is a stricter,
        // earlier check than InvoiceIssuanceService's registration guard —
        // both ultimately prevent the same double-charge outcome.
        $second->assertSessionHasErrors('fee_id');
        $this->assertSame(1, Invoice::count());
    }

    public function test_enrollment_wizard_satisfies_the_shared_invariants(): void
    {
        $registration = $this->registrationFee();
        // SchoolEnrollmentController is gated by 'manage students', not
        // 'manage invoices' like every sibling issuance endpoint (see the
        // Phase 2 audit's P1 finding) — the accountant fixture deliberately
        // lacks it, so this specific path needs an admin actor.
        $admin = \App\Models\User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->post(route('dashboard.school-enrollment.store'), [
            'student_name_ru' => 'Николаев Николай', 'gender' => 'male', 'birth_date' => '2018-01-01',
            'father_name' => 'Николаев Отец', 'father_phone' => '+20 100 222 3344',
            'academic_year_id' => $this->year->id, 'enrollment_mode_id' => $this->mode->id,
            'stage_id' => $this->stage->id, 'grade_id' => $this->grade->id, 'class_id' => $this->classA->id,
            'fee_price_ids' => [$registration->prices()->sole()->id],
        ]);
        $response->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame('500.00', $invoice->total_amount);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertSame('0.00', $invoice->paid_amount);
        $this->assertNormalIssuanceInvariants($invoice);
        // Registration-duplicate inheritance from InvoiceIssuanceService is
        // structurally unreachable through this path (it always creates a
        // brand-new student), so it is not re-tested here — see the Phase 2
        // report §J for why, and InvoiceIssuanceServiceTest/InvoiceCreationTest
        // for direct coverage of the guard itself.
    }

    public function test_mass_billing_satisfies_the_shared_invariants(): void
    {
        $this->enrolledStudent(suffix: 'MassA');
        $this->enrolledStudent(suffix: 'MassB');
        $batch = $this->makeBatch(classIds: [$this->classA->id]);
        app(MassBillingPreviewService::class)->preview($batch);
        $batch->refresh();

        app(MassBillingExecutionService::class)->execute($batch, $this->accountant, '127.0.0.1', 'PHPUnit');

        $this->assertSame(2, Invoice::count());
        foreach (Invoice::all() as $invoice) {
            $this->assertSame('1200.00', $invoice->total_amount);
            $this->assertNormalIssuanceInvariants($invoice);
        }
        // Registration-fee-already-billed-is-skipped is already covered by
        // MassBillingExecutionTest::test_registration_fee_already_billed_is_skipped_at_execution.
    }
}
