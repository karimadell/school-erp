<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FinanceCollection;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use App\Services\Admissions\StudentIdentityResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finance UAT corrective (P0) — Quick Registration must never silently
 * create a duplicate Student for someone who already exists (active,
 * inactive, graduated/suspended, or with only historical enrollments).
 * Every test here proves a candidate is presented for an explicit operator
 * decision — never auto-selected, never silently reused/merged — and that
 * no Enrollment/Invoice/InvoicePayment/FinanceCollection/CashTransaction is
 * ever created while identity resolution is unresolved.
 */
class QuickStudentRegistrationIdentityResolutionTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;
    private AcademicYear $year;
    private Stage $stage;
    private Grade $grade;
    private SchoolClass $class;
    private EnrollmentMode $mode;
    private CashAccount $account;
    private Fee $registrationFee;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder())->run();
        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->assignRole('accountant');
        $this->year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $this->stage = Stage::create(['name' => 'Начальная школа']);
        $this->grade = Grade::create(['name' => '1 класс', 'stage_id' => $this->stage->id]);
        $this->class = SchoolClass::create(['grade_id' => $this->grade->id, 'code' => '1-А', 'name_ar' => '1-A', 'name_ru' => '1-А', 'is_active' => true]);
        $this->mode = EnrollmentMode::create(['code' => 'regular', 'name_ru' => 'Очное обучение', 'is_active' => true]);
        $this->account = CashAccount::operating();
        app(\App\Services\Finance\CashSessionService::class)->open($this->account, $this->accountant);
        $this->registrationFee = Fee::create(['name_ru' => 'Регистрационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => '1000.00', 'is_active' => true]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'student_last_name_ru' => 'Иванов', 'student_first_name_ru' => 'Иван',
            'student_patronymic_ru' => 'Иванович', 'phone' => '01012345678',
            'academic_year_id' => $this->year->id, 'stage_id' => $this->stage->id,
            'grade_id' => $this->grade->id, 'class_id' => $this->class->id, 'enrollment_mode_id' => $this->mode->id,
            'registration_date' => '2026-08-02',
            'services' => [['fee_id' => $this->registrationFee->id, 'quantity' => 1, 'paid_now' => '0.00']],
            'cash_account_id' => $this->account->id, 'payment_method' => 'cash',
        ], $overrides);
    }

    // ----- 1. Genuinely new student -----

    public function test_genuinely_new_student_registers_normally(): void
    {
        $this->actingAs($this->accountant)
            ->post(route('dashboard.quick-registration.store'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard.quick-registration.create'));

        $this->assertSame(1, Student::query()->count());
        $this->assertNull(session('identity_candidates'));
    }

    // ----- 2. Existing exact normalized-name candidate -----

    public function test_existing_exact_name_candidate_blocks_creation_and_shows_resolution(): void
    {
        $existing = Student::create(['last_name_ru' => 'Иванов', 'first_name_ru' => 'Иван', 'patronymic_ru' => 'Иванович', 'name' => 'Иванов Иван Иванович']);

        $this->actingAs($this->accountant)
            ->post(route('dashboard.quick-registration.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $candidates = session('identity_candidates');
        $this->assertNotNull($candidates);
        $this->assertCount(1, $candidates);
        $this->assertSame($existing->id, $candidates[0]['student_id']);
        $this->assertNotNull(session('identity_confirmation_token'));

        // Exactly the pre-existing fixture Student — nothing new was created.
        $this->assertSame(1, Student::query()->count());
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_payments', 0);
        $this->assertDatabaseCount('finance_collections', 0);
        $this->assertDatabaseCount('cash_transactions', 0);
    }

    // ----- 3. Strong name+phone candidate still requires a decision -----

    public function test_strong_name_and_phone_match_still_requires_explicit_decision(): void
    {
        Student::create(['last_name_ru' => 'Иванов', 'first_name_ru' => 'Иван', 'patronymic_ru' => 'Иванович', 'name' => 'Иванов Иван Иванович', 'phone' => '01012345678']);

        $this->actingAs($this->accountant)
            ->post(route('dashboard.quick-registration.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $candidates = session('identity_candidates');
        $this->assertCount(1, $candidates);
        $this->assertSame(StudentIdentityResolver::STRENGTH_STRONG, $candidates[0]['strength']);
        // Still just a candidate — never auto-reused.
        $this->assertSame(1, Student::query()->count());
    }

    // ----- 4. Historical student with no current-year Enrollment is surfaced -----

    public function test_historical_student_without_current_year_enrollment_is_surfaced(): void
    {
        $oldYear = AcademicYear::create(['name' => '2023/2024', 'start_date' => '2023-08-01', 'end_date' => '2024-06-30', 'is_active' => false]);
        $existing = Student::create(['last_name_ru' => 'Иванов', 'first_name_ru' => 'Иван', 'patronymic_ru' => 'Иванович', 'name' => 'Иванов Иван Иванович']);
        // A closed/historical AcademicYear is locked against edits by
        // AcademicYearLockObserver — this fixture is deliberately writing
        // historical data directly (not through any live workflow), so it
        // uses the same explicit-unlock escape hatch other tests in this
        // codebase already use for the identical reason.
        \App\Support\AcademicYearLock::withoutLock(function () use ($existing, $oldYear) {
            Enrollment::create([
                'student_id' => $existing->id, 'academic_year_id' => $oldYear->id, 'enrollment_mode_id' => $this->mode->id,
                'stage_id' => $this->stage->id, 'grade_id' => $this->grade->id, 'class_id' => $this->class->id,
                'academic_year' => $oldYear->name, 'enrollment_date' => '2023-08-01', 'enrolled_at' => '2023-08-01',
                'status' => 'graduated', 'is_active' => false,
            ]);
        });

        $this->actingAs($this->accountant)
            ->post(route('dashboard.quick-registration.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $candidates = session('identity_candidates');
        $this->assertCount(1, $candidates);
        $this->assertSame($existing->id, $candidates[0]['student_id']);
        $this->assertSame('2023/2024', $candidates[0]['latest_enrollment_year']);
        $this->assertFalse($candidates[0]['has_current_year_enrollment']);
    }

    // ----- 5. Legacy Student.name-only candidate is surfaced -----

    public function test_legacy_name_only_candidate_is_surfaced(): void
    {
        $existing = Student::create(['name' => 'Иванов Иван Иванович']);

        $this->actingAs($this->accountant)
            ->post(route('dashboard.quick-registration.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $candidates = session('identity_candidates');
        $this->assertCount(1, $candidates);
        $this->assertSame($existing->id, $candidates[0]['student_id']);
        $this->assertSame('Иванов Иван Иванович', $candidates[0]['name']);
    }

    // ----- 6. Multiple exact-name candidates: all surfaced, none auto-selected -----

    public function test_multiple_matching_candidates_are_all_surfaced_none_auto_selected(): void
    {
        $first = Student::create(['name' => 'Иванов Иван Иванович']);
        $second = Student::create(['last_name_ru' => 'Иванов', 'first_name_ru' => 'Иван', 'patronymic_ru' => 'Иванович', 'name' => 'Иванов Иван Иванович']);

        $this->actingAs($this->accountant)
            ->post(route('dashboard.quick-registration.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $candidates = session('identity_candidates');
        $this->assertCount(2, $candidates);
        $ids = collect($candidates)->pluck('student_id')->sort()->values()->all();
        $expected = collect([$first->id, $second->id])->sort()->values()->all();
        $this->assertSame($expected, $ids);
        $this->assertSame(2, Student::query()->count(), 'No new Student may be created while multiple candidates are unresolved.');
    }

    // ----- 7. Same name, different phone: still POSSIBLE, never auto-created/reused -----

    public function test_same_name_different_phone_remains_possible_match(): void
    {
        Student::create(['last_name_ru' => 'Иванов', 'first_name_ru' => 'Иван', 'patronymic_ru' => 'Иванович', 'name' => 'Иванов Иван Иванович', 'phone' => '01099999999']);

        $this->actingAs($this->accountant)
            ->post(route('dashboard.quick-registration.store'), $this->payload(['phone' => '01012345678']))
            ->assertSessionHasNoErrors();

        $candidates = session('identity_candidates');
        $this->assertCount(1, $candidates);
        $this->assertSame(StudentIdentityResolver::STRENGTH_POSSIBLE, $candidates[0]['strength']);
        $this->assertSame(1, Student::query()->count());
    }

    // ----- 8. Explicit "use existing" hands off to the correct Student -----

    public function test_use_existing_candidate_link_targets_the_exact_selected_student(): void
    {
        $existing = Student::create(['last_name_ru' => 'Иванов', 'first_name_ru' => 'Иван', 'patronymic_ru' => 'Иванович', 'name' => 'Иванов Иван Иванович']);

        $this->actingAs($this->accountant)
            ->post(route('dashboard.quick-registration.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $candidates = session('identity_candidates');
        $this->assertSame($existing->id, $candidates[0]['student_id']);

        // The exact handoff route P0 identified — no new Student created by
        // simply visiting it.
        $this->actingAs($this->accountant)
            ->get(route('dashboard.students.unified-collection.create', $existing->id))
            ->assertOk();
        $this->assertSame(1, Student::query()->count());
    }

    // ----- 9. Explicit "continue as new" proceeds only with a valid confirmation -----

    public function test_continue_as_new_with_valid_confirmation_creates_the_student(): void
    {
        Student::create(['last_name_ru' => 'Иванов', 'first_name_ru' => 'Иван', 'patronymic_ru' => 'Иванович', 'name' => 'Иванов Иван Иванович']);

        $this->actingAs($this->accountant)
            ->post(route('dashboard.quick-registration.store'), $this->payload())
            ->assertSessionHasNoErrors();
        $token = session('identity_confirmation_token');
        $this->assertNotNull($token);

        $this->actingAs($this->accountant)
            ->post(route('dashboard.quick-registration.store'), $this->payload(['identity_resolution_token' => $token]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard.quick-registration.create'));

        // The pre-existing candidate plus exactly one genuinely new Student.
        $this->assertSame(2, Student::query()->count());
        $this->assertSame(1, Invoice::query()->count());
    }

    // ----- 10. Changed identity after confirmation cannot bypass the check -----

    public function test_changed_identity_after_confirmation_reruns_the_candidate_check(): void
    {
        Student::create(['last_name_ru' => 'Иванов', 'first_name_ru' => 'Иван', 'patronymic_ru' => 'Иванович', 'name' => 'Иванов Иван Иванович']);
        Student::create(['last_name_ru' => 'Петров', 'first_name_ru' => 'Пётр', 'patronymic_ru' => 'Петрович', 'name' => 'Петров Пётр Петрович']);

        $this->actingAs($this->accountant)
            ->post(route('dashboard.quick-registration.store'), $this->payload())
            ->assertSessionHasNoErrors();
        $tokenForIvanov = session('identity_confirmation_token');

        // Same token, but the submitted identity now matches a DIFFERENT
        // existing candidate (Петров) — the stale token must not bypass
        // this new match.
        $this->actingAs($this->accountant)
            ->post(route('dashboard.quick-registration.store'), $this->payload([
                'student_last_name_ru' => 'Петров', 'student_first_name_ru' => 'Пётр', 'student_patronymic_ru' => 'Петрович',
                'identity_resolution_token' => $tokenForIvanov,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNotNull(session('identity_candidates'), 'A stale confirmation token for a different identity must not bypass the new candidate check.');
        $this->assertSame(2, Student::query()->count(), 'Only the two pre-existing fixtures — nothing new created.');
    }

    // ----- 11. Invalid/tampered confirmation cannot bypass protection -----

    public function test_tampered_confirmation_token_cannot_bypass_protection(): void
    {
        Student::create(['last_name_ru' => 'Иванов', 'first_name_ru' => 'Иван', 'patronymic_ru' => 'Иванович', 'name' => 'Иванов Иван Иванович']);

        $this->actingAs($this->accountant)
            ->post(route('dashboard.quick-registration.store'), $this->payload(['identity_resolution_token' => 'not-a-real-token']))
            ->assertSessionHasNoErrors();

        $this->assertNotNull(session('identity_candidates'));
        $this->assertSame(1, Student::query()->count());
    }

    // ----- 12. Repeated/double submission remains idempotent -----

    public function test_repeated_confirmed_submission_remains_idempotent(): void
    {
        Student::create(['last_name_ru' => 'Иванов', 'first_name_ru' => 'Иван', 'patronymic_ru' => 'Иванович', 'name' => 'Иванов Иван Иванович']);

        $this->actingAs($this->accountant)
            ->post(route('dashboard.quick-registration.store'), $this->payload())
            ->assertSessionHasNoErrors();
        $token = session('identity_confirmation_token');

        $payload = $this->payload(['identity_resolution_token' => $token]);
        // Same idempotency_token on both submissions — a genuine double
        // submit/retry of the SAME confirmed attempt.
        $payload['idempotency_token'] = 'fixed-token-for-double-submit-test';

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $payload)->assertSessionHasNoErrors();
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $payload)->assertSessionHasNoErrors();

        // The pre-existing candidate plus exactly one new Student — the
        // second identical submission must not create a second one.
        $this->assertSame(2, Student::query()->count());
        $this->assertSame(1, Invoice::query()->count());
    }

    // ----- 13. Nothing is written while resolution is pending -----

    public function test_nothing_is_written_while_resolution_is_pending(): void
    {
        Student::create(['last_name_ru' => 'Иванов', 'first_name_ru' => 'Иван', 'patronymic_ru' => 'Иванович', 'name' => 'Иванов Иван Иванович']);

        $enrollmentsBefore = Enrollment::query()->count();
        $invoicesBefore = Invoice::query()->count();
        $paymentsBefore = InvoicePayment::query()->count();
        $collectionsBefore = FinanceCollection::query()->count();
        $cashTxBefore = CashTransaction::query()->count();

        $this->actingAs($this->accountant)
            ->post(route('dashboard.quick-registration.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertNotNull(session('identity_candidates'));
        $this->assertSame($enrollmentsBefore, Enrollment::query()->count());
        $this->assertSame($invoicesBefore, Invoice::query()->count());
        $this->assertSame($paymentsBefore, InvoicePayment::query()->count());
        $this->assertSame($collectionsBefore, FinanceCollection::query()->count());
        $this->assertSame($cashTxBefore, CashTransaction::query()->count());
    }
}
