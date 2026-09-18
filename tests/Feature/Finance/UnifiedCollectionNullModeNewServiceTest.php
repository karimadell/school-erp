<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\FinanceCollection;
use App\Models\Invoice;
use App\Support\AcademicYearLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * P1 corrective: ServiceSelectionNormalizer::normalize()'s EnrollmentMode
 * parameter is now nullable (?EnrollmentMode $mode, stamping
 * enrollment_mode_id => $mode?->id) — see that class's own docblock. This
 * exists solely so FinanceCollectionService::collectNewServices() no longer
 * raises a raw PHP TypeError when an existing student's exact-year
 * Enrollment has enrollment_mode_id = NULL (git-blamed discovery: this
 * Enrollment field predates mode-scoped Tuition pricing entirely).
 *
 * The corrective is intentionally inert for pricing: InvoiceIssuanceService::
 * issue() already unconditionally re-derives and re-stamps
 * enrollment_mode_id from its own freshly-locked, authoritative Enrollment
 * lookup before InvoiceCalculationService ever prices anything (proven in
 * ClassicInvoiceTuitionModeDerivationTest); PR #80's dimensionalCandidates()
 * guard is what actually decides whether a NULL mode is acceptable for the
 * EXACT pricing scope being resolved. These tests lock in that end-to-end
 * chain specifically for the Unified Collection new-service path, which
 * previously crashed before ever reaching either of those layers.
 */
class UnifiedCollectionNullModeNewServiceTest extends FinanceOperationsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The fixture Enrollment starts with a real (full_time) mode; every
        // test in this file specifically exercises the NULL-mode path
        // unless it explicitly restores a real mode (see the regression
        // tests below).
        $this->enrollment->update(['enrollment_mode_id' => null]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'idempotency_token' => (string) Str::uuid(),
            'academic_year_id' => $this->year->id,
            'payment_method' => 'cash',
            'cash_account_id' => $this->cash->id,
        ], $overrides);
    }

    private function modePrice(string $modeCode, string $amount): FeePrice
    {
        return FeePrice::create([
            'fee_id' => $this->fee->id, 'academic_year_id' => $this->year->id, 'grade_id' => $this->enrollment->grade_id,
            'payment_period' => 'yearly', 'option_type' => 'enrollment_mode', 'option_value' => $modeCode,
            'amount' => $amount, 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function financialSnapshot(): array
    {
        return [
            'finance_collections' => FinanceCollection::count(),
            'invoices' => Invoice::count(),
            'invoice_items' => DB::table('invoice_items')->count(),
            'invoice_installments' => DB::table('invoice_installments')->count(),
            'invoice_payments' => DB::table('invoice_payments')->count(),
            'cash_transactions' => DB::table('cash_transactions')->count(),
            'service_coverages' => DB::table('service_coverages')->count(),
        ];
    }

    // 1. Generic non-mode service (no mode-scoped pricing exists at all for
    // this Fee) — must succeed, exact amount, no TypeError.
    public function test_null_mode_generic_service_succeeds(): void
    {
        $fee = Fee::create(['name_ru' => 'Экскурсия', 'category' => Fee::CATEGORY_ACTIVITY, 'amount' => '500.00', 'is_active' => true]);

        $response = $this->actingAs($this->accountant)->post(
            route('dashboard.students.unified-collection.store', $this->student),
            $this->payload(['new_services' => [['fee_id' => $fee->id, 'quantity' => 1, 'receive_now_amount' => '500.00']]])
        );

        $collection = FinanceCollection::query()->sole();
        $response->assertRedirect(route('dashboard.collections.receipt', $collection));
        $invoice = $collection->linkedInvoices()->sole();
        $this->assertSame('500.00', (string) $invoice->items->sole()->amount);
    }

    // 2. Transport with a real zone dimension — unrelated to EnrollmentMode
    // entirely (option_type='zone', never in MODE_OPTION_TYPES).
    public function test_null_mode_transport_zone_pricing_succeeds(): void
    {
        $transportFee = Fee::create(['name_ru' => 'Транспорт', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '0.00', 'is_active' => true]);
        FeePrice::create([
            'fee_id' => $transportFee->id, 'academic_year_id' => $this->year->id, 'option_type' => 'zone', 'option_value' => 'Зона 1',
            'amount' => '800.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
        $routeId = DB::table('transport_routes')->insertGetId(['name' => 'Маршрут 1', 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->actingAs($this->accountant)->post(
            route('dashboard.students.unified-collection.store', $this->student),
            $this->payload(['new_services' => [[
                'fee_id' => $transportFee->id, 'quantity' => 1, 'receive_now_amount' => '800.00',
                'transport_area' => 'Зона 1', 'transport_route_id' => $routeId,
            ]]])
        );

        $collection = FinanceCollection::query()->sole();
        $response->assertRedirect(route('dashboard.collections.receipt', $collection));
        $this->assertSame('800.00', (string) $collection->linkedInvoices()->sole()->items->sole()->amount);
    }

    // 3. Uniform with real size/item dimensions — matched by size/item, not
    // by EnrollmentMode.
    public function test_null_mode_uniform_pricing_succeeds(): void
    {
        $uniformFee = Fee::create(['name_ru' => 'Школьная форма', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '0.00', 'is_active' => true]);
        $productId = DB::table('uniform_products')->insertGetId([
            'name_ru' => 'Майка', 'category' => 'garment', 'size' => '14', 'price' => '450.00', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        FeePrice::create([
            'fee_id' => $uniformFee->id, 'academic_year_id' => $this->year->id, 'item' => 'Майка', 'size' => '14',
            'amount' => '450.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);

        $response = $this->actingAs($this->accountant)->post(
            route('dashboard.students.unified-collection.store', $this->student),
            $this->payload(['new_services' => [[
                'fee_id' => $uniformFee->id, 'quantity' => 1, 'receive_now_amount' => '450.00',
                'uniform_items' => [['uniform_product_id' => $productId, 'quantity' => 1]],
            ]]])
        );

        $collection = FinanceCollection::query()->sole();
        $response->assertRedirect(route('dashboard.collections.receipt', $collection));
        $this->assertSame('450.00', (string) $collection->linkedInvoices()->sole()->items->sole()->amount);
    }

    // 4. Generic (non-mode-scoped) Tuition — the base fixture's own
    // FeePrice, today's actual production shape.
    public function test_null_mode_generic_tuition_succeeds(): void
    {
        $response = $this->actingAs($this->accountant)->post(
            route('dashboard.students.unified-collection.store', $this->student),
            $this->payload(['new_services' => [['fee_id' => $this->fee->id, 'quantity' => 1, 'receive_now_amount' => '1200.00', 'payment_period' => 'yearly']]])
        );

        $collection = FinanceCollection::query()->sole();
        $response->assertRedirect(route('dashboard.collections.receipt', $collection));
        $this->assertSame('1200.00', (string) $collection->linkedInvoices()->sole()->items->sole()->amount);
    }

    // 5. Mode-scoped Tuition with a NULL mode — no TypeError, reaches PR
    // #80's exact fail-loud guard, zero financial writes.
    public function test_null_mode_mode_scoped_tuition_fails_loudly_with_zero_writes(): void
    {
        $this->modePrice('full_time', '40500.00');
        $this->modePrice('external', '25600.00');

        $before = $this->financialSnapshot();

        $response = $this->actingAs($this->accountant)->post(
            route('dashboard.students.unified-collection.store', $this->student),
            $this->payload(['new_services' => [['fee_id' => $this->fee->id, 'quantity' => 1, 'receive_now_amount' => '40500.00', 'payment_period' => 'yearly']]])
        );

        $response->assertSessionHasErrors();
        $errors = collect(session('errors')->getBag('default')->getMessages())->flatten()->implode(' ');
        $this->assertStringContainsString('тариф зависит от формы обучения', $errors);
        $this->assertSame($before, $this->financialSnapshot());
    }

    // 6. Mixed collection atomicity: an existing-debt payment in the SAME
    // request as a failing NULL-mode mode-scoped Tuition charge must roll
    // back entirely — no partial collection.
    public function test_mixed_collection_rolls_back_entirely_on_mode_scoped_failure(): void
    {
        $existingFee = Fee::create(['name_ru' => 'Учебники', 'category' => Fee::CATEGORY_BOOKS, 'amount' => '1000.00', 'is_active' => true]);
        $existingInvoice = app(\App\Services\Finance\InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $existingFee->id, 'grade_group' => null, 'payment_period' => null, 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
            'payment_type' => 'one_time',
        ], $this->accountant);

        $this->modePrice('full_time', '40500.00');
        $this->modePrice('external', '25600.00');

        $before = $this->financialSnapshot();

        $response = $this->actingAs($this->accountant)->post(
            route('dashboard.students.unified-collection.store', $this->student),
            $this->payload([
                'existing_obligations' => [['invoice_id' => $existingInvoice->id, 'receive_now_amount' => '400.00']],
                'new_services' => [['fee_id' => $this->fee->id, 'quantity' => 1, 'receive_now_amount' => '40500.00', 'payment_period' => 'yearly']],
            ])
        );

        $response->assertSessionHasErrors();
        $this->assertSame($before, $this->financialSnapshot());
        $existingInvoice->refresh();
        $this->assertSame('0.00', (string) $existingInvoice->paid_amount);
        $this->assertSame('1000.00', (string) $existingInvoice->remaining_amount);
    }

    // 7 & 8. Non-null mode regression — each canonical mode still resolves
    // its OWN distinct price, unaffected by the nullable signature.
    public function test_full_time_mode_regression_unaffected(): void
    {
        $fullTime = EnrollmentMode::where('code', 'full_time')->firstOrFail();
        $this->enrollment->update(['enrollment_mode_id' => $fullTime->id]);
        $this->modePrice('full_time', '40500.00');
        $this->modePrice('external', '25600.00');

        $response = $this->actingAs($this->accountant)->post(
            route('dashboard.students.unified-collection.store', $this->student),
            $this->payload(['new_services' => [['fee_id' => $this->fee->id, 'quantity' => 1, 'receive_now_amount' => '40500.00', 'payment_period' => 'yearly']]])
        );

        $collection = FinanceCollection::query()->sole();
        $response->assertRedirect(route('dashboard.collections.receipt', $collection));
        $this->assertSame('40500.00', (string) $collection->linkedInvoices()->sole()->items->sole()->amount);
    }

    public function test_external_mode_regression_unaffected(): void
    {
        $external = EnrollmentMode::create(['code' => 'external', 'name_ru' => 'Экстернат', 'is_active' => false]);
        $this->enrollment->update(['enrollment_mode_id' => $external->id]);
        $this->modePrice('full_time', '40500.00');
        $this->modePrice('external', '25600.00');

        $response = $this->actingAs($this->accountant)->post(
            route('dashboard.students.unified-collection.store', $this->student),
            $this->payload(['new_services' => [['fee_id' => $this->fee->id, 'quantity' => 1, 'receive_now_amount' => '25600.00', 'payment_period' => 'yearly']]])
        );

        $collection = FinanceCollection::query()->sole();
        $response->assertRedirect(route('dashboard.collections.receipt', $collection));
        $this->assertSame('25600.00', (string) $collection->linkedInvoices()->sole()->items->sole()->amount);
    }

    // 9. Request tampering: enrollment_mode_id is not a validated field on
    // new_services.* at all — StoreUnifiedCollectionRequest::validated()
    // strips it before it ever reaches FinanceCollectionService. The real
    // Enrollment (external) must win, never a crafted one (full_time), and
    // the Enrollment itself must remain unchanged.
    public function test_crafted_enrollment_mode_id_in_new_services_is_ignored(): void
    {
        $external = EnrollmentMode::create(['code' => 'external', 'name_ru' => 'Экстернат', 'is_active' => false]);
        $this->enrollment->update(['enrollment_mode_id' => $external->id]);
        $this->modePrice('full_time', '40500.00');
        $this->modePrice('external', '25600.00');

        $response = $this->actingAs($this->accountant)->post(
            route('dashboard.students.unified-collection.store', $this->student),
            $this->payload(['new_services' => [[
                'fee_id' => $this->fee->id, 'quantity' => 1, 'receive_now_amount' => '25600.00', 'payment_period' => 'yearly',
                'enrollment_mode_id' => EnrollmentMode::where('code', 'full_time')->value('id'),
            ]]])
        );

        $collection = FinanceCollection::query()->sole();
        $response->assertRedirect(route('dashboard.collections.receipt', $collection));
        $this->assertSame('25600.00', (string) $collection->linkedInvoices()->sole()->items->sole()->amount);
        $this->assertSame($external->id, $this->enrollment->fresh()->enrollment_mode_id);
    }

    // 10. Cross-year safety: a NULL-mode Enrollment in the TARGET year must
    // never borrow a real mode from a DIFFERENT year's Enrollment for the
    // same student.
    public function test_cross_year_mode_is_never_borrowed_for_null_mode_target_year(): void
    {
        $otherYear = AcademicYear::create(['name' => '2025/2026', 'start_date' => '2025-08-01', 'end_date' => '2026-06-30', 'is_active' => false]);
        $external = EnrollmentMode::create(['code' => 'external', 'name_ru' => 'Экстернат', 'is_active' => false]);
        AcademicYearLock::withoutLock(fn () => Enrollment::create([
            'student_id' => $this->student->id, 'academic_year_id' => $otherYear->id, 'enrollment_mode_id' => $external->id,
            'stage_id' => $this->enrollment->stage_id, 'grade_id' => $this->enrollment->grade_id, 'class_id' => $this->enrollment->class_id,
            'academic_year' => $otherYear->name, 'enrollment_date' => '2025-08-01', 'enrolled_at' => '2025-08-01',
            'status' => 'active', 'is_active' => true,
        ]));
        $this->modePrice('full_time', '40500.00');
        $this->modePrice('external', '25600.00');

        $before = $this->financialSnapshot();

        // $this->enrollment (the TARGET year) still has enrollment_mode_id
        // = null (setUp()); the other year's real "external" mode must not
        // be used to resolve this charge.
        $response = $this->actingAs($this->accountant)->post(
            route('dashboard.students.unified-collection.store', $this->student),
            $this->payload(['new_services' => [['fee_id' => $this->fee->id, 'quantity' => 1, 'receive_now_amount' => '40500.00', 'payment_period' => 'yearly']]])
        );

        $response->assertSessionHasErrors();
        $errors = collect(session('errors')->getBag('default')->getMessages())->flatten()->implode(' ');
        $this->assertStringContainsString('тариф зависит от формы обучения', $errors);
        $this->assertSame($before, $this->financialSnapshot());
    }

    // 11. No matching Enrollment for the target year at all (and no
    // annual_registration submitted) — the pre-existing, unrelated
    // ValidationException fires; no TypeError, zero writes.
    public function test_no_matching_enrollment_fails_cleanly_without_type_error(): void
    {
        $this->enrollment->delete();

        $before = $this->financialSnapshot();

        $response = $this->actingAs($this->accountant)->post(
            route('dashboard.students.unified-collection.store', $this->student),
            $this->payload(['new_services' => [['fee_id' => $this->fee->id, 'quantity' => 1, 'receive_now_amount' => '1200.00']]])
        );

        $response->assertSessionHasErrors();
        $this->assertSame($before, $this->financialSnapshot());
    }
}
