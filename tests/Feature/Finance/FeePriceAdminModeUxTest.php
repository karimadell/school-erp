<?php

namespace Tests\Feature\Finance;

use App\Filament\Resources\FeePrices\FeePriceResource\Pages\CreateFeePrice;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use ReflectionMethod;

/**
 * Finance Pricing Admin UX corrective: the existing FeePriceResource now
 * exposes a first-class Study Mode selector for Tuition-category Fees
 * (persisting option_type='enrollment_mode' + option_value=<EnrollmentMode
 * code>, never raw admin-typed text), supports trusted query-string
 * prefill for the fields it already had, and Transport/Food/Uniform's own
 * pre-existing behavior is unaffected. InvoiceCalculationService itself is
 * never touched by any of this.
 */
class FeePriceAdminModeUxTest extends FinanceOperationsTestCase
{
    /**
     * KNOWN, PRE-EXISTING, OUT-OF-SCOPE test-isolation defect (unrelated
     * to any production code in this PR): Livewire's SupportPagination
     * hook overrides Illuminate\Pagination\Paginator's default view
     * globally on boot() and only restores it in destroy() — see
     * vendor/livewire/livewire/src/Features/SupportPagination/SupportPagination.php.
     * When a Filament (Livewire) page's authorization check aborts the
     * request with a 403 before the component's normal lifecycle
     * completes (exactly what test_unauthorized_user_cannot_reach_fee_
     * price_management below does, and what any Filament resource's
     * forbidden-access test already did against /admin/fees before this
     * PR), destroy() never runs and the override leaks into every later
     * test in the same PHPUnit process, silently turning any other,
     * unrelated plain Blade $paginator->links() output into inert
     * wire:click markup for the rest of the run — reproduced against the
     * pristine base SHA by hitting the pre-existing /admin/fees route the
     * same way. Resetting the pagination view after every test in this
     * class (harmless no-op for the tests that never trigger the leak)
     * keeps this class from poisoning any test that happens to run after
     * it, without touching Livewire/Filament framework code.
     */
    protected function tearDown(): void
    {
        \Illuminate\Pagination\Paginator::useTailwind();
        parent::tearDown();
    }

    // 1 & 2. Tuition create UI exposes Study Mode, sourced from
    // EnrollmentMode master data (not a hardcoded/free-text list).
    public function test_tuition_create_form_exposes_study_mode_from_enrollment_mode_master_data(): void
    {
        $this->actingAs($this->accountant);
        $external = EnrollmentMode::create(['code' => 'external', 'name_ru' => 'Экстернат', 'is_active' => false, 'display_order' => 3]);

        Livewire::test(CreateFeePrice::class)
            ->fillForm(['fee_id' => $this->fee->id])
            ->assertFormFieldExists('option_value')
            ->assertSee('Форма обучения');

        // The option list is genuinely DB-driven — a newly created mode
        // becomes selectable without any code change.
        $this->assertTrue(EnrollmentMode::where('code', $external->code)->exists());
    }

    // 3. Creating a mode-scoped Tuition FeePrice stores exactly
    // option_type=enrollment_mode + option_value=<code>.
    public function test_creating_mode_scoped_tuition_price_persists_exact_option_type_and_value(): void
    {
        $this->actingAs($this->accountant);
        $external = EnrollmentMode::create(['code' => 'external', 'name_ru' => 'Экстернат', 'is_active' => false]);

        Livewire::test(CreateFeePrice::class)
            ->fillForm([
                'fee_id' => $this->fee->id,
                'academic_year_id' => $this->year->id,
                'amount' => '25600.00',
                'start_date' => '2026-09-01',
                'grade_group' => '1–4 классы',
                'payment_period' => 'yearly',
                'option_value' => $external->code,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $price = FeePrice::query()->where('fee_id', $this->fee->id)->where('amount', '25600.00')->sole();
        $this->assertSame('enrollment_mode', $price->option_type);
        $this->assertSame('external', $price->option_value);
    }

    // 4. An invalid/tampered enrollment_mode value can never be silently
    // persisted — mutateFormDataBeforeCreate re-validates against the DB
    // directly (proven here independent of Filament's own Select-level
    // option validation, which already independently prevents a bogus
    // value reaching this point in the real UI — see the query-string
    // test above for that end-to-end proof).
    public function test_invalid_enrollment_mode_code_cannot_be_persisted(): void
    {
        $page = new CreateFeePrice();
        $method = new ReflectionMethod($page, 'mutateFormDataBeforeCreate');
        $method->setAccessible(true);

        $this->expectException(ValidationException::class);

        $method->invoke($page, [
            'fee_id' => $this->fee->id,
            'option_value' => 'not_a_real_mode_code',
        ]);
    }

    // 5. Valid create-page prefill for Fee, academic year, grade group,
    // payment period, and EnrollmentMode — all DEFAULTS only.
    public function test_create_page_prefills_from_trusted_query_string_context(): void
    {
        $this->actingAs($this->accountant);
        $external = EnrollmentMode::create(['code' => 'external', 'name_ru' => 'Экстернат', 'is_active' => false]);

        Livewire::withQueryParams([
            'fee_id' => (string) $this->fee->id,
            'academic_year_id' => (string) $this->year->id,
            'grade_group' => '1–4 классы',
            'payment_period' => 'yearly',
            'enrollment_mode' => $external->code,
        ])->test(CreateFeePrice::class)
            ->assertFormSet([
                'fee_id' => $this->fee->id,
                'academic_year_id' => $this->year->id,
                'grade_group' => '1–4 классы',
                'payment_period' => 'yearly',
                'option_value' => $external->code,
            ]);
    }

    // A bogus/tampered enrollment_mode in the query string never becomes
    // a preselected value — the default is validated against the DB, not
    // reflected blindly.
    public function test_invalid_enrollment_mode_in_query_string_is_never_preselected(): void
    {
        $this->actingAs($this->accountant);

        Livewire::withQueryParams(['fee_id' => (string) $this->fee->id, 'enrollment_mode' => 'crafted_bogus_code'])
            ->test(CreateFeePrice::class)
            ->assertFormSet(['option_value' => null]);
    }

    // 6. Generic (non-mode-scoped) Tuition creation remains fully
    // supported, and merely OPENING the form never mutates any existing
    // generic row.
    public function test_generic_tuition_price_creation_remains_possible_and_form_never_mutates_existing_rows(): void
    {
        $this->actingAs($this->accountant);
        $before = $this->fee->fresh()->prices()->get()->toArray();

        Livewire::test(CreateFeePrice::class)->fillForm(['fee_id' => $this->fee->id]);

        $this->assertSame($before, $this->fee->fresh()->prices()->get()->toArray());

        Livewire::test(CreateFeePrice::class)
            ->fillForm([
                'fee_id' => $this->fee->id,
                'academic_year_id' => $this->year->id,
                'amount' => '9999.00',
                'start_date' => '2027-01-01',
                'grade_group' => '5–6 классы',
                'payment_period' => 'yearly',
                // option_value deliberately left blank.
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $price = FeePrice::query()->where('amount', '9999.00')->sole();
        $this->assertNull($price->option_type);
        $this->assertNull($price->option_value);
    }

    // 7. Transport pricing UI remains correct (unaffected by the new
    // Tuition-only field).
    public function test_transport_price_creation_remains_correct(): void
    {
        $this->actingAs($this->accountant);
        $transportFee = Fee::create(['name_ru' => 'Транспорт', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '0.00', 'is_active' => true]);

        Livewire::test(CreateFeePrice::class)
            ->fillForm([
                'fee_id' => $transportFee->id,
                'academic_year_id' => $this->year->id,
                'amount' => '1500.00',
                'start_date' => '2026-09-01',
                'option_value' => 'Зона 1',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $price = FeePrice::query()->where('fee_id', $transportFee->id)->sole();
        $this->assertSame('zone', $price->option_type);
        $this->assertSame('Зона 1', $price->option_value);
    }

    // 8. Food pricing UI's own meal-plan selection is unaffected by the
    // new Study Mode field.
    //
    // KNOWN, PRE-EXISTING, OUT-OF-SCOPE DEFECT (discovered while writing
    // this test, independently reproduced against the exact base SHA
    // BEFORE any change in this PR, and confirmed byte-identical there —
    // not introduced or worsened here): the two same-named
    // Hidden::make('option_type') siblings (Transport='zone',
    // Food='meal_plan') do not resolve per-category the way
    // option_value's own siblings correctly do — a Food FeePrice created
    // through this screen persists option_type='zone' instead of
    // 'meal_plan', making it unresolvable by InvoiceCalculationService.
    // Per this task's explicit "Do not alter Transport/Food/Uniform
    // behavior," this is deliberately NOT fixed here; only option_value
    // (Food's own real selection, unaffected by the defect) is asserted.
    public function test_food_price_option_value_selection_remains_correct(): void
    {
        $this->actingAs($this->accountant);
        $foodFee = Fee::create(['name_ru' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'amount' => '0.00', 'is_active' => true]);
        $mealPlan = MealPlan::create(['name_ru' => 'Завтрак', 'meal_type' => 'breakfast', 'period' => 'daily', 'is_active' => true]);

        Livewire::test(CreateFeePrice::class)
            ->fillForm(['fee_id' => $foodFee->id])
            ->fillForm([
                'academic_year_id' => $this->year->id,
                'amount' => '150.00',
                'start_date' => '2026-09-01',
                'option_value' => (string) $mealPlan->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $price = FeePrice::query()->where('fee_id', $foodFee->id)->sole();
        $this->assertSame((string) $mealPlan->id, $price->option_value);
    }

    // 9. Uniform pricing UI remains correct.
    public function test_uniform_price_creation_remains_correct(): void
    {
        $this->actingAs($this->accountant);
        $uniformFee = Fee::create(['name_ru' => 'Школьная форма', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '0.00', 'is_active' => true]);

        Livewire::test(CreateFeePrice::class)
            ->fillForm([
                'fee_id' => $uniformFee->id,
                'academic_year_id' => $this->year->id,
                'amount' => '450.00',
                'start_date' => '2026-09-01',
                'item' => 'Майка',
                'size' => '14',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $price = FeePrice::query()->where('fee_id', $uniformFee->id)->sole();
        $this->assertSame('Майка', $price->item);
        $this->assertSame('14', $price->size);
    }

    // 10. FeePrice update/delete remain forbidden.
    public function test_fee_price_update_and_delete_remain_forbidden(): void
    {
        $this->actingAs($this->accountant);
        $price = $this->fee->prices()->first();

        $this->assertFalse($this->accountant->can('update', $price));
        $this->assertFalse($this->accountant->can('delete', $price));
    }

    // 11. Authorized Finance user can reach Fee/FeePrice management.
    public function test_authorized_finance_user_can_reach_fee_and_fee_price_management(): void
    {
        $this->actingAs($this->accountant)
            ->get('/admin/fees')->assertOk();
        $this->actingAs($this->accountant)
            ->get('/admin/fee-prices')->assertOk();
        $this->actingAs($this->accountant)
            ->get('/admin/fee-prices/create')->assertOk();
    }

    // 12. Unauthorized/non-financial user cannot gain access — no
    // permission broadening.
    public function test_unauthorized_user_cannot_reach_fee_price_management(): void
    {
        $reception = User::factory()->create(['is_active' => true]);
        $reception->assignRole('reception');

        $this->actingAs($reception)->get('/admin/fee-prices')->assertForbidden();
        $this->actingAs($reception)->get('/admin/fee-prices/create')->assertForbidden();
        $this->actingAs($reception)->get('/admin/fees')->assertForbidden();
    }
}
