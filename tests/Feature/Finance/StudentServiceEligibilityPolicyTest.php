<?php

namespace Tests\Feature\Finance;

use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\FinanceCollection;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\QuickRegistrationFeePolicy;
use App\Services\Finance\StudentServiceEligibilityPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * PR 1 — student service eligibility. StudentServiceEligibilityPolicy is the
 * single authority for which Fees may be newly sold to a student, with two
 * explicit catalogs (additionalService / yearSetup), applied to both the
 * listing and the submitted fee ids of every student sale entry point.
 *
 * $this->fee (FinanceOperationsTestCase) is canonical tuition priced 1200
 * yearly for the fixture grade — reused here as "the" canonical tuition Fee.
 */
class StudentServiceEligibilityPolicyTest extends FinanceOperationsTestCase
{
    private StudentServiceEligibilityPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = app(StudentServiceEligibilityPolicy::class);
    }

    private function fee(string $name, string $category, array $attributes = []): Fee
    {
        return Fee::create(array_merge([
            'name_ru' => $name, 'category' => $category, 'amount' => '100.00', 'is_active' => true,
        ], $attributes));
    }

    private function price(Fee $fee, string $amount = '1200.00', string $period = 'yearly'): FeePrice
    {
        return FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'payment_period' => $period,
            'amount' => $amount, 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true,
        ]);
    }

    /**
     * Every category/flag the frozen matrix names, keyed by role.
     *
     * @return array<string, Fee>
     */
    private function catalog(): array
    {
        return [
            'tuition' => $this->fee,
            'tuition_regular' => $this->fee('Обучение (обычное)', Fee::CATEGORY_TUITION_REGULAR),
            'tuition_family' => $this->fee('Обучение (семейное)', Fee::CATEGORY_TUITION_FAMILY),
            'tuition_external' => $this->fee('Экстернат (старый)', Fee::CATEGORY_TUITION_EXTERNAL),
            'registration' => $this->fee('Организационный взнос', Fee::CATEGORY_REGISTRATION),
            'activity' => $this->fee('Экскурсия в аквариум', Fee::CATEGORY_ACTIVITY),
            'food' => $this->fee('Питание', Fee::CATEGORY_FOOD),
            'transport' => $this->fee('Трансфер', Fee::CATEGORY_TRANSPORT),
            'uniform' => $this->fee('Школьная форма', Fee::CATEGORY_UNIFORM),
            'books' => $this->fee('Учебники', Fee::CATEGORY_BOOKS),
            'extra_classes' => $this->fee('Кружок', Fee::CATEGORY_EXTRA_CLASSES),
            'other' => $this->fee('Прочая услуга', Fee::CATEGORY_OTHER),
            'test' => $this->fee('UAT — тестовая услуга', Fee::CATEGORY_OTHER, ['is_test_data' => true]),
            'inactive' => $this->fee('Неактивная услуга', Fee::CATEGORY_OTHER, ['is_active' => false]),
        ];
    }

    /**
     * @param  array<string, Fee>  $catalog
     * @return array<int, string>
     */
    private function listedKeys(array $catalog, string $context): array
    {
        $ids = $this->policy->apply(Fee::query(), $context)->pluck('id')->all();

        return collect($catalog)->filter(fn (Fee $fee) => in_array($fee->id, $ids, true))->keys()->sort()->values()->all();
    }

    /** @return array<string, int> */
    private function financialSnapshot(): array
    {
        return [
            'finance_collections' => FinanceCollection::count(),
            'invoices' => Invoice::count(),
            'invoice_items' => DB::table('invoice_items')->count(),
            'service_coverages' => DB::table('service_coverages')->count(),
            'invoice_payments' => DB::table('invoice_payments')->count(),
            'cash_transactions' => DB::table('cash_transactions')->count(),
        ];
    }

    // ----- 1. Policy matrix --------------------------------------------------

    public function test_additional_service_catalog_matches_the_frozen_matrix(): void
    {
        $catalog = $this->catalog();

        $this->assertSame(
            ['books', 'extra_classes', 'food', 'other', 'transport', 'uniform'],
            $this->listedKeys($catalog, StudentServiceEligibilityPolicy::CONTEXT_ADDITIONAL_SERVICE),
        );

        foreach ($catalog as $key => $fee) {
            $this->assertSame(
                in_array($key, ['books', 'extra_classes', 'food', 'other', 'transport', 'uniform'], true),
                $this->policy->isEligible($fee, StudentServiceEligibilityPolicy::CONTEXT_ADDITIONAL_SERVICE),
                "additionalService eligibility mismatch for [{$key}]",
            );
        }
    }

    public function test_year_setup_catalog_matches_the_frozen_matrix(): void
    {
        $catalog = $this->catalog();

        $this->assertSame(
            ['books', 'extra_classes', 'food', 'other', 'registration', 'transport', 'tuition', 'uniform'],
            $this->listedKeys($catalog, StudentServiceEligibilityPolicy::CONTEXT_YEAR_SETUP),
        );

        foreach ($catalog as $key => $fee) {
            $this->assertSame(
                in_array($key, ['books', 'extra_classes', 'food', 'other', 'registration', 'transport', 'tuition', 'uniform'], true),
                $this->policy->isEligible($fee, StudentServiceEligibilityPolicy::CONTEXT_YEAR_SETUP),
                "yearSetup eligibility mismatch for [{$key}]",
            );
        }
    }

    public function test_server_side_assertion_rejects_every_excluded_fee_per_context(): void
    {
        $catalog = $this->catalog();
        $rejected = [
            StudentServiceEligibilityPolicy::CONTEXT_ADDITIONAL_SERVICE => ['tuition', 'tuition_regular', 'tuition_family', 'tuition_external', 'registration', 'activity', 'test', 'inactive'],
            StudentServiceEligibilityPolicy::CONTEXT_YEAR_SETUP => ['tuition_regular', 'tuition_family', 'tuition_external', 'activity', 'test', 'inactive'],
        ];

        foreach ($rejected as $context => $keys) {
            foreach ($keys as $key) {
                try {
                    $this->policy->assertEligibleIds([$catalog[$key]->id], $context, 'fees');
                    $this->fail("[{$key}] must be rejected in [{$context}]");
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('fees', $exception->errors());
                }
            }
        }
    }

    public function test_quick_registration_policy_is_exactly_the_year_setup_catalog(): void
    {
        $this->catalog();

        $this->assertSame(
            $this->policy->applyYearSetup(Fee::query())->orderBy('id')->pluck('id')->all(),
            app(QuickRegistrationFeePolicy::class)->apply(Fee::query())->orderBy('id')->pluck('id')->all(),
        );
        $this->assertContains($this->fee->id, app(QuickRegistrationFeePolicy::class)->apply(Fee::query())->pluck('id')->all());
    }

    public function test_eligibility_is_keyed_on_category_never_on_the_display_name(): void
    {
        $ordinaryNamedLikeAnExcursion = $this->fee('Экскурсия в аквариум', Fee::CATEGORY_OTHER);
        $activityWithAnOrdinaryName = $this->fee('Кружок рисования', Fee::CATEGORY_ACTIVITY);

        $this->assertTrue($this->policy->isEligible($ordinaryNamedLikeAnExcursion, StudentServiceEligibilityPolicy::CONTEXT_ADDITIONAL_SERVICE));
        $this->assertFalse($this->policy->isEligible($activityWithAnOrdinaryName, StudentServiceEligibilityPolicy::CONTEXT_ADDITIONAL_SERVICE));
        $this->assertFalse($this->policy->isEligible($activityWithAnOrdinaryName, StudentServiceEligibilityPolicy::CONTEXT_YEAR_SETUP));
    }

    public function test_a_null_category_fee_is_ineligible_in_both_contexts_on_query_and_assertion(): void
    {
        $uncategorized = $this->fee('Без категории', Fee::CATEGORY_OTHER);
        $uncategorized->forceFill(['category' => null])->save();
        $this->assertNull($uncategorized->fresh()->category);
        $this->assertTrue($uncategorized->is_active);
        $this->assertFalse((bool) $uncategorized->fresh()->is_test_data);

        foreach ([StudentServiceEligibilityPolicy::CONTEXT_ADDITIONAL_SERVICE, StudentServiceEligibilityPolicy::CONTEXT_YEAR_SETUP] as $context) {
            $this->assertNotContains($uncategorized->id, $this->policy->apply(Fee::query(), $context)->pluck('id')->all(), "query [{$context}]");
            $this->assertFalse($this->policy->isEligible($uncategorized->fresh(), $context), "isEligible [{$context}]");

            try {
                $this->policy->assertEligible($uncategorized->fresh(), $context, 'fees');
                $this->fail("assertEligible must reject a null category in [{$context}]");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('fees', $exception->errors());
            }

            try {
                $this->policy->assertEligibleIds([$uncategorized->id], $context, 'fees');
                $this->fail("assertEligibleIds must reject a null category in [{$context}]");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('fees', $exception->errors());
            }
        }
    }

    public function test_crafted_null_category_sales_are_rejected_at_every_boundary_with_zero_writes(): void
    {
        $uncategorized = $this->fee('Без категории', Fee::CATEGORY_OTHER);
        $this->price($uncategorized, '500.00');
        $uncategorized->forceFill(['category' => null])->save();

        // additionalService — Unified Collection (enrolled student).
        $before = $this->financialSnapshot();
        $this->actingAs($this->accountant)->post(route('dashboard.students.unified-collection.store', $this->student), [
            'idempotency_token' => (string) Str::uuid(),
            'academic_year_id' => $this->year->id, 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'new_services' => [['fee_id' => $uncategorized->id, 'quantity' => 1, 'receive_now_amount' => '500.00', 'payment_period' => 'yearly']],
        ])->assertSessionHasErrors('new_services');
        $this->assertSame($before, $this->financialSnapshot());

        // additionalService — Charge & Collect.
        $this->actingAs($this->accountant)->post(route('dashboard.students.charge.store', $this->student), [
            'academic_year_id' => $this->year->id, 'fee_id' => $uncategorized->id, 'quantity' => 1,
            'payment_period' => 'yearly', 'due_date' => '2027-01-01', 'pricing_date' => '2026-09-01',
            'idempotency_key' => (string) Str::uuid(),
            'collect_amount' => '500.00', 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
        ])->assertSessionHasErrors(['fee_id' => 'У услуги не указана категория — она недоступна для новых начислений.']);
        $this->assertSame($before, $this->financialSnapshot());

        // yearSetup — Classic Student Invoice.
        $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student), [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'pricing_date' => '2026-09-01', 'due_date' => '2027-06-30',
            'fees' => [$uncategorized->id], 'payment_type' => 'one_time',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('fees');
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_unknown_context_fails_loudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policy->isEligible($this->fee, 'everything');
    }

    // ----- 2. Unified Collection --------------------------------------------

    public function test_unified_collection_lists_only_additional_services_for_an_enrolled_student(): void
    {
        $this->fee->update(['name_ru' => 'Обучение-канон']);
        $this->fee('Организационный взнос-канон', Fee::CATEGORY_REGISTRATION);
        $this->fee('Экскурсия в аквариум', Fee::CATEGORY_ACTIVITY);
        $this->fee('UAT — тестовая услуга', Fee::CATEGORY_OTHER, ['is_test_data' => true]);
        $this->fee('Кружок шахмат', Fee::CATEGORY_OTHER);

        $this->actingAs($this->accountant)
            ->get(route('dashboard.students.unified-collection.create', $this->student))
            ->assertOk()
            ->assertSee('Кружок шахмат')
            ->assertDontSee('Обучение-канон')
            ->assertDontSee('Организационный взнос-канон')
            ->assertDontSee('Экскурсия в аквариум')
            ->assertDontSee('UAT — тестовая услуга');
    }

    public function test_unified_collection_rejects_crafted_new_sales_of_ineligible_fees_with_zero_writes(): void
    {
        $registration = $this->fee('Организационный взнос', Fee::CATEGORY_REGISTRATION);
        $this->price($registration, '500.00');
        $ineligible = [
            'canonical tuition' => $this->fee,
            'registration' => $registration,
            'activity' => $this->fee('Экскурсия в аквариум', Fee::CATEGORY_ACTIVITY),
            'test fee' => $this->fee('UAT — тестовая услуга', Fee::CATEGORY_OTHER, ['is_test_data' => true]),
            'legacy tuition' => $this->fee('Экстернат (старый)', Fee::CATEGORY_TUITION_EXTERNAL),
        ];

        foreach ($ineligible as $label => $fee) {
            $before = $this->financialSnapshot();

            $this->actingAs($this->accountant)->post(route('dashboard.students.unified-collection.store', $this->student), [
                'idempotency_token' => (string) Str::uuid(),
                'academic_year_id' => $this->year->id, 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
                'new_services' => [['fee_id' => $fee->id, 'quantity' => 1, 'receive_now_amount' => '100.00', 'payment_period' => 'yearly']],
            ])->assertSessionHasErrors('new_services');

            $this->assertSame($before, $this->financialSnapshot(), "{$label} must leave zero financial writes");
        }
    }

    public function test_a_crafted_annual_registration_cannot_unlock_tuition_for_an_already_enrolled_student(): void
    {
        $before = $this->financialSnapshot();
        $enrollments = Enrollment::count();

        $this->actingAs($this->accountant)->post(route('dashboard.students.unified-collection.store', $this->student), [
            'idempotency_token' => (string) Str::uuid(),
            'academic_year_id' => $this->year->id, 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'annual_registration' => [
                'enrollment_mode_id' => $this->enrollment->enrollment_mode_id,
                'stage_id' => $this->enrollment->stage_id,
                'grade_id' => $this->enrollment->grade_id,
                'class_id' => $this->enrollment->class_id,
            ],
            'new_services' => [['fee_id' => $this->fee->id, 'quantity' => 1, 'receive_now_amount' => '1200.00', 'payment_period' => 'yearly']],
        ])->assertSessionHasErrors('new_services');

        $this->assertSame($before, $this->financialSnapshot());
        $this->assertSame($enrollments, Enrollment::count());
    }

    public function test_annual_registration_keeps_canonical_tuition_available(): void
    {
        $this->ensureCanonicalRegistrationModeCatalog();
        $returning = Student::create([
            'last_name_ru' => 'Сидоров', 'first_name_ru' => 'Пётр', 'patronymic_ru' => null,
            'phone' => '+201009998877', 'class_id' => $this->enrollment->class_id, 'status' => 'registration_completed',
        ]);
        $this->fee->update(['name_ru' => 'Обучение-канон']);

        $this->actingAs($this->accountant)
            ->get(route('dashboard.students.unified-collection.create', $returning))
            ->assertOk()
            ->assertSee('Обучение-канон');

        $response = $this->actingAs($this->accountant)->post(route('dashboard.students.unified-collection.store', $returning), [
            'idempotency_token' => (string) Str::uuid(),
            'academic_year_id' => $this->year->id, 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'annual_registration' => [
                'enrollment_mode_id' => $this->enrollment->enrollment_mode_id,
                'stage_id' => $this->enrollment->stage_id,
                'grade_id' => $this->enrollment->grade_id,
                'class_id' => $this->enrollment->class_id,
            ],
            'new_services' => [['fee_id' => $this->fee->id, 'quantity' => 1, 'receive_now_amount' => '1200.00', 'payment_period' => 'yearly']],
        ]);

        $response->assertSessionHasNoErrors();
        $collection = FinanceCollection::query()->sole();
        $response->assertRedirect(route('dashboard.collections.receipt', $collection));
        $item = $collection->linkedInvoices()->sole()->items()->sole();
        $this->assertSame($this->fee->id, $item->fee_id);
        $this->assertSame('1200.00', (string) $item->amount);
    }

    // ----- 3. Charge & Collect ---------------------------------------------

    public function test_charge_and_collect_lists_only_additional_services(): void
    {
        $this->fee->update(['name_ru' => 'Обучение-канон']);
        $this->fee('Организационный взнос-канон', Fee::CATEGORY_REGISTRATION);
        $this->fee('Экскурсия в аквариум', Fee::CATEGORY_ACTIVITY);
        $this->fee('UAT — тестовая услуга', Fee::CATEGORY_OTHER, ['is_test_data' => true]);
        $this->fee('Кружок шахмат', Fee::CATEGORY_OTHER);

        $this->actingAs($this->accountant)
            ->get(route('dashboard.students.charge.create', $this->student))
            ->assertOk()
            ->assertSee('Кружок шахмат')
            ->assertDontSee('Обучение-канон')
            ->assertDontSee('Организационный взнос-канон')
            ->assertDontSee('Экскурсия в аквариум')
            ->assertDontSee('UAT — тестовая услуга');
    }

    public function test_charge_and_collect_rejects_crafted_ineligible_fees_with_zero_writes(): void
    {
        $registration = $this->fee('Организационный взнос', Fee::CATEGORY_REGISTRATION);
        $this->price($registration, '500.00');
        $activity = $this->fee('Экскурсия в аквариум', Fee::CATEGORY_ACTIVITY);
        $this->price($activity, '500.00');
        $test = $this->fee('UAT — тестовая услуга', Fee::CATEGORY_OTHER, ['is_test_data' => true]);
        $this->price($test, '500.00');
        $ineligible = [
            'canonical tuition' => $this->fee,
            'registration' => $registration,
            'activity' => $activity,
            'test fee' => $test,
        ];

        foreach ($ineligible as $label => $fee) {
            $before = $this->financialSnapshot();

            $this->actingAs($this->accountant)->post(route('dashboard.students.charge.store', $this->student), [
                'academic_year_id' => $this->year->id, 'fee_id' => $fee->id, 'quantity' => 1,
                'payment_period' => 'yearly', 'due_date' => '2027-01-01', 'pricing_date' => '2026-09-01',
                'idempotency_key' => (string) Str::uuid(),
                'collect_amount' => '500.00', 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            ])->assertSessionHasErrors('fee_id');

            $this->assertSame($before, $this->financialSnapshot(), "{$label} must leave zero financial writes");
        }
    }

    public function test_charge_and_collect_still_sells_an_ordinary_service(): void
    {
        $service = $this->makeAdditionalServiceFee('Кружок шахмат', '500.00');

        $this->actingAs($this->accountant)->post(route('dashboard.students.charge.store', $this->student), [
            'academic_year_id' => $this->year->id, 'fee_id' => $service->id, 'quantity' => 1,
            'payment_period' => 'yearly', 'due_date' => '2027-01-01', 'pricing_date' => '2026-09-01',
            'idempotency_key' => (string) Str::uuid(), 'collect_amount' => '0',
        ])->assertSessionHasNoErrors();

        $this->assertSame($service->id, Invoice::sole()->items()->sole()->fee_id);
    }

    // ----- 4. Classic Student Invoice / legacy create (year setup) ----------

    public function test_classic_student_invoice_lists_canonical_tuition_and_registration_but_not_activity_or_test(): void
    {
        $this->fee->update(['name_ru' => 'Обучение-канон']);
        $this->fee('Организационный взнос-канон', Fee::CATEGORY_REGISTRATION);
        $this->fee('Экскурсия в аквариум', Fee::CATEGORY_ACTIVITY);
        $this->fee('UAT — тестовая услуга', Fee::CATEGORY_OTHER, ['is_test_data' => true]);

        $this->actingAs($this->accountant)
            ->get(route('dashboard.students.invoices.create', $this->student))
            ->assertOk()
            ->assertSee('Обучение-канон')
            ->assertSee('Организационный взнос-канон')
            ->assertDontSee('Экскурсия в аквариум')
            ->assertDontSee('UAT — тестовая услуга');
    }

    public function test_classic_student_invoice_rejects_activity_and_test_fees_with_zero_writes(): void
    {
        $activity = $this->fee('Экскурсия в аквариум', Fee::CATEGORY_ACTIVITY);
        $this->price($activity, '500.00');
        $test = $this->fee('UAT — тестовая услуга', Fee::CATEGORY_OTHER, ['is_test_data' => true]);
        $this->price($test, '500.00');

        foreach (['activity' => $activity, 'test fee' => $test] as $label => $fee) {
            $before = $this->financialSnapshot();

            $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student), [
                'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
                'pricing_date' => '2026-09-01', 'due_date' => '2027-06-30',
                'fees' => [$fee->id], 'payment_type' => 'one_time',
                'idempotency_key' => (string) Str::uuid(),
            ])->assertSessionHasErrors('fees');

            $this->assertSame($before, $this->financialSnapshot(), "{$label} must leave zero financial writes");
        }
    }

    public function test_classic_student_invoice_keeps_registration_subject_to_the_once_per_year_guard(): void
    {
        $registration = $this->fee('Организационный взнос', Fee::CATEGORY_REGISTRATION, ['type' => 'service']);
        $this->price($registration, '500.00');
        $payload = fn () => [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'pricing_date' => '2026-09-01', 'due_date' => '2027-06-30',
            'fees' => [$registration->id], 'payment_type' => 'one_time',
            'idempotency_key' => (string) Str::uuid(),
        ];

        $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student), $payload())
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Invoice::count());

        $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store', $this->student), $payload())
            ->assertSessionHasErrors();
        $this->assertSame(1, Invoice::count());
    }

    public function test_legacy_invoice_create_lists_the_year_setup_catalog(): void
    {
        $this->fee->update(['name_ru' => 'Обучение-канон']);
        $this->fee('Экскурсия в аквариум', Fee::CATEGORY_ACTIVITY);
        $this->fee('UAT — тестовая услуга', Fee::CATEGORY_OTHER, ['is_test_data' => true]);

        $this->actingAs($this->accountant)
            ->get(route('dashboard.invoices.create'))
            ->assertOk()
            ->assertSee('Обучение-канон')
            ->assertDontSee('Экскурсия в аквариум')
            ->assertDontSee('UAT — тестовая услуга');
    }

    // ----- 5. School Enrollment (year setup) -------------------------------

    public function test_school_enrollment_hides_and_rejects_activity_tariffs(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $activity = $this->fee('Экскурсия в аквариум', Fee::CATEGORY_ACTIVITY);
        $activityPrice = $this->price($activity, '500.00', 'once');

        $this->actingAs($admin)
            ->get(route('dashboard.school-enrollment.create'))
            ->assertOk()
            ->assertDontSee('Экскурсия в аквариум');

        $before = $this->financialSnapshot();
        $students = Student::count();

        $this->actingAs($admin)->post(route('dashboard.school-enrollment.store'), [
            'student_name_ru' => 'Николаев Николай', 'gender' => 'male', 'birth_date' => '2018-01-01',
            'father_name' => 'Николаев Отец', 'father_phone' => '+20 100 222 3344',
            'academic_year_id' => $this->year->id, 'enrollment_mode_id' => $this->enrollment->enrollment_mode_id,
            'stage_id' => $this->enrollment->stage_id, 'grade_id' => $this->enrollment->grade_id, 'class_id' => $this->enrollment->class_id,
            'fee_price_ids' => [$activityPrice->id],
        ])->assertSessionHasErrors('fee_price_ids');

        $this->assertSame($before, $this->financialSnapshot());
        $this->assertSame($students, Student::count());
    }
}
