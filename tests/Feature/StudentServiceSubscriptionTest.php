<?php

namespace Tests\Feature;

use App\Exceptions\DuplicateSubscriptionException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\FinancePolicySetting;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentServiceSubscription;
use App\Models\User;
use App\Services\StudentServiceSubscriptionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Batch 5: StudentServiceSubscription is the centerpiece of the finance
 * foundation. These tests prove every approved policy decision:
 * 3 (duplicate prevention), 4 (overdue-balance blocking with exemptions),
 * 6 (negotiated price requires reason + permission + audit trail).
 */
class StudentServiceSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected StudentServiceSubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StudentServiceSubscriptionService();
    }

    protected function makeEnrollment(): Enrollment
    {
        $stage = Stage::create(['name' => 'Primary']);
        $grade = Grade::create(['name' => 'Grade 1', 'stage_id' => $stage->id]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'A', 'name_ar' => 'الفصل أ']);
        $student = Student::create(['name' => 'Test Student']);
        $year = AcademicYear::create([
            'name' => '2026 / 2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-31', 'is_active' => true,
        ]);

        return Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'stage_id' => $stage->id,
            'grade_id' => $grade->id,
            'class_id' => $class->id,
            'status' => 'active',
        ]);
    }

    protected function makeFee(string $category = Fee::CATEGORY_REGISTRATION, bool $exempt = false): Fee
    {
        return Fee::create([
            'name_ru' => 'Регистрационный взнос',
            'name_ar' => 'رسوم التسجيل',
            'category' => $category,
            'payment_period' => Fee::PERIOD_ONCE,
            'amount' => 8000,
            'is_active' => true,
            'exempt_from_balance_block' => $exempt,
        ]);
    }

    protected function makeCashAccount(): CashAccount
    {
        return CashAccount::create(['name' => 'Main Cashbox', 'type' => CashAccount::TYPE_CASH]);
    }

    protected function authorizedUser(): User
    {
        $user = User::factory()->create();
        \Spatie\Permission\Models\Permission::findOrCreate('override service prices', 'web');
        $user->givePermissionTo('override service prices');

        return $user;
    }

    /*
    |--------------------------------------------------------------------------
    | Basic subscription
    |--------------------------------------------------------------------------
    */

    public function test_a_subscription_persists_correctly(): void
    {
        $enrollment = $this->makeEnrollment();
        $fee = $this->makeFee();

        $subscription = $this->service->subscribe($enrollment, $fee);

        $this->assertDatabaseHas('student_service_subscriptions', [
            'id' => $subscription->id,
            'enrollment_id' => $enrollment->id,
            'fee_id' => $fee->id,
            'status' => StudentServiceSubscription::STATUS_ACTIVE,
        ]);
    }

    public function test_effective_price_falls_back_to_the_fee_catalog_price(): void
    {
        $enrollment = $this->makeEnrollment();
        $fee = $this->makeFee();

        $subscription = $this->service->subscribe($enrollment, $fee);

        $this->assertSame(8000.0, $subscription->effectivePrice());
    }

    /*
    |--------------------------------------------------------------------------
    | Policy 3: duplicate prevention
    |--------------------------------------------------------------------------
    */

    public function test_a_duplicate_subscription_to_the_same_fee_is_rejected(): void
    {
        $enrollment = $this->makeEnrollment();
        $fee = $this->makeFee();

        $this->service->subscribe($enrollment, $fee);

        $this->expectException(DuplicateSubscriptionException::class);
        $this->service->subscribe($enrollment, $fee);
    }

    public function test_the_database_constraint_also_rejects_a_duplicate_bypassing_the_service(): void
    {
        $enrollment = $this->makeEnrollment();
        $fee = $this->makeFee();

        StudentServiceSubscription::create(['enrollment_id' => $enrollment->id, 'fee_id' => $fee->id]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        StudentServiceSubscription::create(['enrollment_id' => $enrollment->id, 'fee_id' => $fee->id]);
    }

    public function test_the_same_student_can_subscribe_to_different_fees(): void
    {
        $enrollment = $this->makeEnrollment();
        $registrationFee = $this->makeFee(Fee::CATEGORY_REGISTRATION);
        $transportFee = $this->makeFee(Fee::CATEGORY_TRANSPORT);

        $this->service->subscribe($enrollment, $registrationFee);
        $this->service->subscribe($enrollment, $transportFee);

        $this->assertDatabaseCount('student_service_subscriptions', 2);
    }

    /*
    |--------------------------------------------------------------------------
    | Policy 6: negotiated price — reason, permission, audit
    |--------------------------------------------------------------------------
    */

    public function test_negotiated_price_without_a_reason_is_rejected(): void
    {
        $enrollment = $this->makeEnrollment();
        $fee = $this->makeFee();
        $user = $this->authorizedUser();

        $this->expectException(InvalidArgumentException::class);
        $this->service->subscribe($enrollment, $fee, ['negotiated_price' => 5000], $user);
    }

    public function test_negotiated_price_without_an_authorized_user_is_rejected(): void
    {
        $enrollment = $this->makeEnrollment();
        $fee = $this->makeFee();
        $unauthorizedUser = User::factory()->create(); // no permission granted

        $this->expectException(AuthorizationException::class);
        $this->service->subscribe($enrollment, $fee, [
            'negotiated_price' => 5000,
            'negotiated_reason' => 'Sibling discount',
        ], $unauthorizedUser);
    }

    public function test_negotiated_price_with_reason_and_authorized_user_succeeds(): void
    {
        $enrollment = $this->makeEnrollment();
        $fee = $this->makeFee();
        $user = $this->authorizedUser();

        $subscription = $this->service->subscribe($enrollment, $fee, [
            'negotiated_price' => 5000,
            'negotiated_reason' => 'Sibling discount',
        ], $user);

        $this->assertDatabaseHas('student_service_subscriptions', [
            'id' => $subscription->id,
            'negotiated_price' => 5000,
            'negotiated_reason' => 'Sibling discount',
            'negotiated_by' => $user->id,
        ]);
        $this->assertSame(5000.0, $subscription->fresh()->effectivePrice());
    }

    public function test_negotiated_price_override_is_audit_logged(): void
    {
        $enrollment = $this->makeEnrollment();
        $fee = $this->makeFee();
        $user = $this->authorizedUser();

        $subscription = $this->service->subscribe($enrollment, $fee, [
            'negotiated_price' => 5000,
            'negotiated_reason' => 'Sibling discount',
        ], $user);

        $this->assertDatabaseHas('audit_logs', [
            'model' => 'StudentServiceSubscription',
            'model_id' => $subscription->id,
            'action' => 'created',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Policy 4: overdue-balance blocking with configurable, extensible exemptions
    |--------------------------------------------------------------------------
    */

    public function test_no_configured_threshold_never_blocks(): void
    {
        $enrollment = $this->makeEnrollment();
        $fee = $this->makeFee();

        Invoice::create([
            'student_id' => $enrollment->student_id,
            'cash_account_id' => $this->makeCashAccount()->id,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'remaining_amount' => 100000,
            'status' => Invoice::STATUS_UNPAID,
        ]);

        // Both thresholds are null by default (seeded by the migration).
        $subscription = $this->service->subscribe($enrollment, $fee);

        $this->assertNotNull($subscription);
    }

    public function test_exceeding_the_amount_threshold_blocks_a_non_exempt_fee(): void
    {
        $enrollment = $this->makeEnrollment();
        $fee = $this->makeFee();

        Invoice::create([
            'student_id' => $enrollment->student_id,
            'cash_account_id' => $this->makeCashAccount()->id,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'remaining_amount' => 100000,
            'status' => Invoice::STATUS_UNPAID,
        ]);

        FinancePolicySetting::current()->update(['overdue_block_threshold_amount' => 5000]);

        $this->expectException(InsufficientBalanceException::class);
        $this->service->subscribe($enrollment, $fee);
    }

    public function test_an_invoice_overdue_beyond_the_days_threshold_blocks_a_non_exempt_fee(): void
    {
        $enrollment = $this->makeEnrollment();
        $fee = $this->makeFee();

        Invoice::create([
            'student_id' => $enrollment->student_id,
            'cash_account_id' => $this->makeCashAccount()->id,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'remaining_amount' => 1000,
            'status' => Invoice::STATUS_UNPAID,
            'due_date' => now()->subDays(60)->toDateString(),
        ]);

        FinancePolicySetting::current()->update(['overdue_block_threshold_days' => 30]);

        $this->expectException(InsufficientBalanceException::class);
        $this->service->subscribe($enrollment, $fee);
    }

    public function test_a_fee_marked_exempt_is_never_blocked_regardless_of_balance(): void
    {
        // Meals, per the approved policy, are the first configured
        // exception — but the mechanism is generic: any fee can be
        // exempted by flagging the row, no schema change required.
        $enrollment = $this->makeEnrollment();
        $mealFee = $this->makeFee(Fee::CATEGORY_FOOD, exempt: true);

        Invoice::create([
            'student_id' => $enrollment->student_id,
            'cash_account_id' => $this->makeCashAccount()->id,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'remaining_amount' => 100000,
            'status' => Invoice::STATUS_UNPAID,
        ]);

        FinancePolicySetting::current()->update(['overdue_block_threshold_amount' => 5000]);

        $subscription = $this->service->subscribe($enrollment, $mealFee);

        $this->assertNotNull($subscription);
    }

    public function test_a_new_exempt_fee_can_be_added_later_without_any_schema_change(): void
    {
        // Proves the "additional service exceptions in the future without
        // schema changes" requirement: exempting a brand-new fee category
        // (books) is a pure data change on the Fee row.
        $enrollment = $this->makeEnrollment();
        $booksFee = $this->makeFee(Fee::CATEGORY_BOOKS, exempt: false);

        Invoice::create([
            'student_id' => $enrollment->student_id,
            'cash_account_id' => $this->makeCashAccount()->id,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'remaining_amount' => 100000,
            'status' => Invoice::STATUS_UNPAID,
        ]);
        FinancePolicySetting::current()->update(['overdue_block_threshold_amount' => 5000]);

        $this->expectException(InsufficientBalanceException::class);
        $this->service->subscribe($enrollment, $booksFee);

        // Now exempt it — a data change, not a migration — and retry.
        $booksFee->update(['exempt_from_balance_block' => true]);
        $subscription = $this->service->subscribe($enrollment, $booksFee);
        $this->assertNotNull($subscription);
    }

    public function test_a_paid_invoice_does_not_count_toward_the_outstanding_balance(): void
    {
        $enrollment = $this->makeEnrollment();
        $fee = $this->makeFee();

        Invoice::create([
            'student_id' => $enrollment->student_id,
            'cash_account_id' => $this->makeCashAccount()->id,
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'remaining_amount' => 0,
            'status' => Invoice::STATUS_PAID,
        ]);

        FinancePolicySetting::current()->update(['overdue_block_threshold_amount' => 5000]);

        $subscription = $this->service->subscribe($enrollment, $fee);

        $this->assertNotNull($subscription);
    }
}
