<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Support\AcademicYearLock;
use Illuminate\Support\Str;

/**
 * Discovery correction, recorded here rather than in a changed production
 * file: InvoiceIssuanceService::issue() already resolves the authoritative
 * Enrollment for (student_id, academic_year_id, is_active=true) and already
 * unconditionally stamps enrollment_mode_id from it onto every item (see
 * issue()'s own body — the Enrollment lookup and the
 * `$item['enrollment_mode_id'] = $enrollment->enrollment_mode_id;`
 * assignment predate this phase, git-blamed to 2026-08-08). Both Classic
 * Invoice entry points (InvoiceController::store() and
 * StudentInvoiceController::store()) already call issue() and were
 * therefore already server-authoritative and already immune to
 * enrollment_mode_id tampering before this test file was written — no
 * production code change was needed or made for this PR.
 *
 * These tests exist to LOCK IN that already-correct behavior against future
 * regression, end-to-end through the real HTTP routes and the real
 * PR #80 guard, since no test previously exercised this exact path.
 */
class ClassicInvoiceTuitionModeDerivationTest extends FinanceOperationsTestCase
{
    private function modePrice(string $modeCode, string $amount): FeePrice
    {
        return FeePrice::create([
            'fee_id' => $this->fee->id, 'academic_year_id' => $this->year->id, 'grade_id' => $this->enrollment->grade_id,
            'payment_period' => 'yearly', 'option_type' => 'enrollment_mode', 'option_value' => $modeCode,
            'amount' => $amount, 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id, 'due_date' => '2027-01-01',
            'fees' => [$this->fee->id], 'payment_period' => ['yearly'], 'grade_group' => [null],
            'initial_payment_amount' => '0', 'idempotency_key' => (string) Str::uuid(),
        ], $overrides);
    }

    /**
     * @return array<string, int>
     */
    private function financialSnapshot(): array
    {
        return [
            'invoices' => Invoice::count(),
            'invoice_items' => \DB::table('invoice_items')->count(),
            'invoice_installments' => \DB::table('invoice_installments')->count(),
            'service_coverages' => \DB::table('service_coverages')->count(),
            'invoice_payments' => \DB::table('invoice_payments')->count(),
            'cash_transactions' => \DB::table('cash_transactions')->count(),
        ];
    }

    // 1-4: each canonical mode resolves its OWN price, never another's.
    public function test_full_time_enrollment_resolves_full_time_mode_price(): void
    {
        $this->modePrice('full_time', '40500.00');
        $this->modePrice('external', '25600.00');

        $response = $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student), $this->payload());

        $response->assertRedirect();
        $this->assertDatabaseHas('invoices', ['student_id' => $this->student->id, 'total_amount' => '40500.00']);
    }

    public function test_external_enrollment_resolves_external_mode_price(): void
    {
        $external = EnrollmentMode::create(['code' => 'external', 'name_ru' => 'Экстернат', 'is_active' => false]);
        $this->enrollment->update(['enrollment_mode_id' => $external->id]);
        $this->modePrice('full_time', '40500.00');
        $this->modePrice('external', '25600.00');

        $response = $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student), $this->payload());

        $response->assertRedirect();
        $this->assertDatabaseHas('invoices', ['student_id' => $this->student->id, 'total_amount' => '25600.00']);
    }

    public function test_family_enrollment_resolves_family_mode_price(): void
    {
        $family = EnrollmentMode::create(['code' => 'family', 'name_ru' => 'Семейная форма обучения', 'is_active' => false]);
        $this->enrollment->update(['enrollment_mode_id' => $family->id]);
        $this->modePrice('full_time', '40500.00');
        $this->modePrice('family', '20000.00');

        $response = $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student), $this->payload());

        $response->assertRedirect();
        $this->assertDatabaseHas('invoices', ['student_id' => $this->student->id, 'total_amount' => '20000.00']);
    }

    public function test_no_enrollment_mode_code_resolves_its_own_price(): void
    {
        // "no_enrollment" here is the canonical MODE CODE (Без зачисления),
        // not the absence of an Enrollment row — the fixture's Enrollment
        // still exists, just carries this specific mode.
        $noEnrollmentMode = EnrollmentMode::create(['code' => 'no_enrollment', 'name_ru' => 'Без зачисления', 'is_active' => false]);
        $this->enrollment->update(['enrollment_mode_id' => $noEnrollmentMode->id]);
        $this->modePrice('full_time', '40500.00');
        $this->modePrice('no_enrollment', '5000.00');

        $response = $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student), $this->payload());

        $response->assertRedirect();
        $this->assertDatabaseHas('invoices', ['student_id' => $this->student->id, 'total_amount' => '5000.00']);
    }

    // 6: a crafted enrollment_mode_id anywhere in the request is structurally
    // incapable of reaching pricing — StoreInvoiceRequest::prepareForValidation()
    // rebuilds items from a fixed key set that never includes it, and
    // issue() unconditionally overwrites enrollment_mode_id from the
    // authoritative Enrollment regardless.
    public function test_crafted_enrollment_mode_id_cannot_override_authoritative_mode(): void
    {
        $external = EnrollmentMode::create(['code' => 'external', 'name_ru' => 'Экстернат', 'is_active' => false]);
        $this->modePrice('full_time', '40500.00');
        $this->modePrice('external', '25600.00');

        $response = $this->actingAs($this->accountant)->post(
            route('dashboard.students.invoices.store', $this->student),
            $this->payload(['enrollment_mode_id' => [$this->fee->id => $external->id], 'items' => [['fee_id' => $this->fee->id, 'enrollment_mode_id' => $external->id]]])
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('invoices', ['student_id' => $this->student->id, 'total_amount' => '40500.00']);
    }

    // 7 & 8: the invoice's own academic_year_id determines which Enrollment
    // is used — a different year's Enrollment/mode is never borrowed.
    public function test_another_academic_years_enrollment_mode_is_never_borrowed(): void
    {
        $otherYear = AcademicYear::create(['name' => '2025/2026', 'start_date' => '2025-08-01', 'end_date' => '2026-06-30', 'is_active' => false]);
        $external = EnrollmentMode::create(['code' => 'external', 'name_ru' => 'Экстернат', 'is_active' => false]);
        AcademicYearLock::withoutLock(fn () => \App\Models\Enrollment::create([
            'student_id' => $this->student->id, 'academic_year_id' => $otherYear->id, 'enrollment_mode_id' => $external->id,
            'stage_id' => $this->enrollment->stage_id, 'grade_id' => $this->enrollment->grade_id, 'class_id' => $this->enrollment->class_id,
            'academic_year' => $otherYear->name, 'enrollment_date' => '2025-08-01', 'enrolled_at' => '2025-08-01',
            'status' => 'active', 'is_active' => true,
        ]));
        $this->modePrice('full_time', '40500.00');
        $this->modePrice('external', '25600.00');

        // Invoice targets $this->year (full_time), not $otherYear (external).
        $response = $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student), $this->payload());

        $response->assertRedirect();
        $this->assertDatabaseHas('invoices', ['student_id' => $this->student->id, 'academic_year_id' => $this->year->id, 'total_amount' => '40500.00']);
    }

    // 9: Enrollment exists for the target year but its mode is NULL, and
    // mode-scoped pricing is applicable — PR #80's guard must fire before
    // any financial write, proven with a full value-level snapshot, not a
    // single-table count.
    public function test_null_mode_enrollment_fails_loudly_with_zero_financial_writes(): void
    {
        $this->enrollment->update(['enrollment_mode_id' => null]);
        $this->modePrice('full_time', '40500.00');
        $this->modePrice('external', '25600.00');

        $before = $this->financialSnapshot();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student), $this->payload());

        $response->assertSessionHasErrors();
        $errors = collect(session('errors')->getBag('default')->getMessages())->flatten()->implode(' ');
        $this->assertStringContainsString('тариф зависит от формы обучения', $errors);
        $this->assertSame($before, $this->financialSnapshot());
    }

    // 10: no matching Enrollment for the target year at all — fails safely
    // (via the pre-existing, unrelated Enrollment-existence validation),
    // zero financial writes.
    public function test_no_matching_enrollment_for_target_year_fails_safely_with_zero_writes(): void
    {
        $this->enrollment->delete();
        $this->modePrice('full_time', '40500.00');

        $before = $this->financialSnapshot();

        $response = $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student), $this->payload());

        $response->assertSessionHasErrors();
        $this->assertSame($before, $this->financialSnapshot());
    }

    // 11: generic-only Tuition (today's actual production state) is unaffected.
    public function test_generic_tuition_pricing_remains_compatible(): void
    {
        // $this->fee already has one generic FeePrice from the base fixture.
        $response = $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student), $this->payload());

        $response->assertRedirect();
        $this->assertDatabaseHas('invoices', ['student_id' => $this->student->id, 'total_amount' => '1200.00']);
    }

    // 12: Registration (non-Tuition, non-dimensional) is unaffected by the
    // Enrollment-mode stamping.
    public function test_registration_fee_remains_compatible(): void
    {
        $registrationFee = Fee::create(['name_ru' => 'Регистрация', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => '500.00', 'is_active' => true]);
        FeePrice::create([
            'fee_id' => $registrationFee->id, 'academic_year_id' => $this->year->id, 'payment_period' => 'once',
            'amount' => '500.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);

        $response = $this->actingAs($this->accountant)->post(
            route('dashboard.students.invoices.store', $this->student),
            $this->payload(['fees' => [$registrationFee->id], 'payment_period' => [null], 'grade_group' => [null]])
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('invoices', ['student_id' => $this->student->id, 'total_amount' => '500.00']);
    }

    // 13: Transport (dimensional via option_type='zone', never in
    // MODE_OPTION_TYPES) is unaffected.
    public function test_transport_fee_remains_compatible(): void
    {
        $transportFee = Fee::create(['name_ru' => 'Транспорт', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '0.00', 'is_active' => true]);
        FeePrice::create([
            'fee_id' => $transportFee->id, 'academic_year_id' => $this->year->id, 'option_type' => 'zone', 'option_value' => 'Зона 1',
            'amount' => '1500.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);

        $response = $this->actingAs($this->accountant)->post(
            route('dashboard.students.invoices.store', $this->student),
            $this->payload(['fees' => [$transportFee->id], 'payment_period' => [null], 'grade_group' => [null], 'option_type' => [$transportFee->id => 'zone'], 'option_value' => [$transportFee->id => 'Зона 1']])
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('invoices', ['student_id' => $this->student->id, 'total_amount' => '1500.00']);
    }

    public function test_legacy_tuition_external_is_hidden_and_rejected_for_new_invoices_but_history_remains_readable(): void
    {
        $externalFee = Fee::create(['name_ru' => 'Экстернат', 'category' => Fee::CATEGORY_TUITION_EXTERNAL, 'amount' => '0.00', 'is_active' => true]);
        FeePrice::create([
            'fee_id' => $externalFee->id, 'academic_year_id' => $this->year->id, 'grade_id' => $this->enrollment->grade_id,
            'payment_period' => 'yearly', 'amount' => '25600.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);

        $historical = $this->invoice('25600.00');
        $historicalItem = $historical->items()->firstOrFail();
        $historicalItem->update(['fee_id' => $externalFee->id]);
        $before = $historicalItem->fresh()->toArray();

        $this->actingAs($this->accountant)->get(route('dashboard.invoices.create'))
            ->assertOk()->assertDontSee('Экстернат');
        $this->actingAs($this->accountant)->get(route('dashboard.students.invoices.create', $this->student))
            ->assertOk()->assertDontSee('Экстернат');

        $beforeCount = Invoice::count();
        $response = $this->actingAs($this->accountant)->post(
            route('dashboard.students.invoices.store', $this->student),
            $this->payload(['fees' => [$externalFee->id]])
        );

        $response->assertSessionHasErrors('fees');
        $this->assertSame($beforeCount, Invoice::count());
        $this->assertSame($before, $historicalItem->fresh()->toArray());

        $this->actingAs($this->accountant)->post(
            route('dashboard.invoices.store'),
            $this->payload(['fees' => [$externalFee->id], 'idempotency_key' => null])
        )->assertSessionHasErrors('fees');
        $this->assertSame($beforeCount, Invoice::count());
    }

    // 15: BOTH Classic Invoice entry points derive the same authoritative
    // mode — InvoiceController::store() (dashboard.invoices.store) is a
    // second, distinct entry point from StudentInvoiceController::store()
    // (dashboard.students.invoices.store), and both call the exact same
    // InvoiceIssuanceService::issue().
    public function test_both_classic_invoice_entry_points_derive_the_same_authoritative_mode(): void
    {
        $external = EnrollmentMode::create(['code' => 'external', 'name_ru' => 'Экстернат', 'is_active' => false]);
        $this->modePrice('full_time', '40500.00');
        $this->modePrice('external', '25600.00');

        $response = $this->actingAs($this->accountant)->post(
            route('dashboard.invoices.store'),
            $this->payload(['enrollment_mode_id' => [$this->fee->id => $external->id]])
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('invoices', ['student_id' => $this->student->id, 'total_amount' => '40500.00']);
    }
}
