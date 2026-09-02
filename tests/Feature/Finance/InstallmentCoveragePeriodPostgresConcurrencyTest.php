<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\ServiceCoverage;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\InvoiceIssuanceService;
use App\Services\Finance\InvoicePaymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Finance V2, Phase 2D corrective pass #3 follow-up (coordinator-requested
 * real-PostgreSQL verification, items 2/3) — coverage-period concurrent
 * capacity and coverage-overlap exclusion, executed against a real,
 * coordinator-provided disposable PostgreSQL 16.15 server (see
 * InvoiceIssuancePostgresConcurrencyTest's own docblock for the general
 * gating/skip convention and how to run these for real). Genuinely
 * executed and passing against that server as of this pass — not merely
 * written and skipped.
 */
class InstallmentCoveragePeriodPostgresConcurrencyTest extends TestCase
{
    private ?string $connectionName = null;

    private bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension not available.');
        }

        config([
            'database.connections.pgsql_concurrency_test' => array_merge(
                config('database.connections.pgsql', []),
                array_filter([
                    'host' => env('PGSQL_TEST_HOST'), 'port' => env('PGSQL_TEST_PORT'),
                    'database' => env('PGSQL_TEST_DATABASE'), 'username' => env('PGSQL_TEST_USERNAME'),
                    'password' => env('PGSQL_TEST_PASSWORD'),
                ], fn ($v) => $v !== null),
            ),
        ]);
        $this->connectionName = 'pgsql_concurrency_test';

        try {
            DB::connection($this->connectionName)->select('select 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('No reachable PostgreSQL server for '.static::class.': '.$e->getMessage());
        }

        Artisan::call('migrate', ['--database' => $this->connectionName, '--force' => true]);
        DB::connection($this->connectionName)->statement('SET client_min_messages TO WARNING');
        $this->migrated = true;
        config(['database.default' => $this->connectionName]);
    }

    protected function tearDown(): void
    {
        if ($this->migrated) {
            try {
                Artisan::call('migrate:rollback', ['--database' => $this->connectionName, '--force' => true, '--step' => 1000]);
            } catch (\Throwable $e) {
                fwrite(STDERR, "\n[".static::class."::tearDown] migrate:rollback failed (pre-existing, unrelated migration bug): {$e->getMessage()}\n");
            }
        }
        parent::tearDown();
    }

    /** @return array{student: Student, transportItem: \App\Models\InvoiceItem, coverage: ServiceCoverage, period: \App\Models\InstallmentCoveragePeriod, installment: \App\Models\InvoiceInstallment, accountant: User} */
    private function seedBundledInvoiceWithCoverage(): array
    {
        (new RolesAndPermissionsSeeder)->run();
        $accountant = User::factory()->create(['is_active' => true]);
        $accountant->assignRole('accountant');
        $year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $stage = Stage::create(['name' => 'Test Stage', 'order' => 1, 'is_active' => true]);
        $grade = Grade::forceCreate(['name' => 'Test Grade', 'stage_id' => $stage->id, 'level' => 1]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'A', 'name_ru' => 'A', 'name_ar' => 'A', 'is_active' => true]);
        $mode = EnrollmentMode::firstOrCreate(['code' => 'regular'], ['name_ru' => 'Test Mode', 'is_active' => true]);
        $student = Student::create(['last_name_ru' => 'Раса', 'first_name_ru' => 'Покрытие', 'patronymic_ru' => 'Постгрес', 'phone' => '+201009991122', 'class_id' => $class->id, 'status' => 'registration_completed']);
        Enrollment::create(['student_id' => $student->id, 'academic_year_id' => $year->id, 'enrollment_mode_id' => $mode->id, 'stage_id' => $stage->id, 'grade_id' => $grade->id, 'class_id' => $class->id, 'academic_year' => $year->name, 'enrollment_date' => '2026-09-17', 'enrolled_at' => '2026-09-17', 'status' => 'active', 'is_active' => true]);

        $transport = Fee::create(['name_ru' => 'Test Transport', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $transport->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create(['fee_id' => $transport->id, 'academic_year_id' => $year->id, 'payment_period' => 'monthly', 'option_type' => 'zone', 'option_value' => 'Zone 1', 'amount' => '400.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $invoice = app(InvoiceIssuanceService::class)->issue($student, [
            'student_id' => $student->id, 'academic_year_id' => $year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-17',
            'items' => [['fee_id' => $transport->id, 'grade_group' => null, 'payment_period' => 'monthly', 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => 'zone', 'option_value' => 'Zone 1']],
            'payment_type' => 'calendar', 'billing_period' => 'monthly',
        ], $accountant);

        $transportItem = $invoice->items->sole();
        $coverage = ServiceCoverage::where('invoice_item_id', $transportItem->id)->sole();
        $installment = $invoice->installments()->orderBy('sequence')->first();
        $period = \App\Models\InstallmentCoveragePeriod::where('invoice_installment_id', $installment->id)
            ->where('service_coverage_id', $coverage->id)->sole();

        // Phase 3's strict cash-session rule (InvoicePaymentService::record())
        // requires an open cash session before a 'cash' payment method is
        // accepted — opened here so the concurrent payment race below can
        // actually reach the coverage-period guard instead of failing
        // earlier on this unrelated precondition. Real-server run note:
        // since tearDown()'s rollback is best-effort (see its own
        // comment), a session opened by an earlier run against this same
        // live server can still be open — reused instead of blindly
        // re-opening, which CashSessionService::open() itself rejects.
        $cashSessionService = app(\App\Services\Finance\CashSessionService::class);
        $cash = \App\Models\CashAccount::operating();
        if (! $cashSessionService->activeFor($cash)) {
            $cashSessionService->open($cash, $accountant);
        }

        return compact('student', 'transportItem', 'coverage', 'period', 'installment', 'accountant');
    }

    /**
     * Coordinator-requested item 2: two simultaneous payments competing
     * for the final remaining capacity of ONE coverage period (400.00,
     * fully unsettled) — real forked processes, genuinely racing
     * InvoicePaymentService::record()'s own
     * linkAllocationToCoveragePeriod() row-lock/capacity guard against a
     * real PostgreSQL server. Exactly one must succeed with the full
     * 400.00 allocated; the period must never be over-allocated.
     */
    public function test_two_concurrent_payments_racing_for_the_same_periods_final_capacity_never_over_allocate_it(): void
    {
        ['transportItem' => $transportItem, 'installment' => $installment, 'accountant' => $accountant] = $this->seedBundledInvoiceWithCoverage();

        $barrier = tempnam(sys_get_temp_dir(), 'pg_period_race_');
        unlink($barrier);
        $resultFileA = $barrier.'.a';
        $resultFileB = $barrier.'.b';

        $spawn = function (string $resultFile) use ($barrier, $transportItem, $installment, $accountant): int {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork() failed.');
            }
            if ($pid === 0) {
                DB::purge($this->connectionName);
                while (! file_exists($barrier)) {
                    usleep(2000);
                }
                try {
                    app(InvoicePaymentService::class)->record(
                        invoiceId: $installment->invoice_id, cashAccountId: \App\Models\CashAccount::operating()->id,
                        amount: '400.00', paymentMethod: 'cash', idempotencyKey: (string) Str::uuid(),
                        actor: $accountant, installmentId: $installment->id,
                        allocations: [['invoice_item_id' => $transportItem->id, 'amount' => '400.00']],
                    );
                    file_put_contents($resultFile, 'settled');
                } catch (\Illuminate\Validation\ValidationException $e) {
                    file_put_contents($resultFile, 'rejected:'.json_encode($e->errors()));
                } catch (\Throwable $e) {
                    file_put_contents($resultFile, 'CRASHED:'.get_class($e).':'.$e->getMessage());
                }
                exit(0);
            }

            return $pid;
        };

        $pidA = $spawn($resultFileA);
        $pidB = $spawn($resultFileB);
        touch($barrier);
        pcntl_waitpid($pidA, $statusA);
        pcntl_waitpid($pidB, $statusB);

        $resultA = file_exists($resultFileA) ? file_get_contents($resultFileA) : null;
        $resultB = file_exists($resultFileB) ? file_get_contents($resultFileB) : null;
        @unlink($resultFileA);
        @unlink($resultFileB);
        @unlink($barrier);

        $this->assertNotNull($resultA);
        $this->assertNotNull($resultB);
        $this->assertStringNotContainsString('CRASHED', (string) $resultA, "must recover cleanly, not crash: {$resultA}");
        $this->assertStringNotContainsString('CRASHED', (string) $resultB, "must recover cleanly, not crash: {$resultB}");

        $outcomes = [$resultA, $resultB];
        $settled = collect($outcomes)->filter(fn ($r) => $r === 'settled');
        $rejected = collect($outcomes)->filter(fn ($r) => str_starts_with((string) $r, 'rejected:'));
        $this->assertCount(1, $settled, 'exactly one payment must win the row-lock race and settle the period');
        $this->assertCount(1, $rejected, 'exactly one payment must be rejected — the period was already full by the time it acquired the lock');

        // Re-fetch fresh via the item/coverage chain (both children ran
        // in separate forked processes, so this parent's own in-memory
        // $period from setup is stale).
        $coverage = ServiceCoverage::on($this->connectionName)->where('invoice_item_id', $transportItem->id)->sole();
        $period = \App\Models\InstallmentCoveragePeriod::on($this->connectionName)
            ->where('invoice_installment_id', $installment->id)->where('service_coverage_id', $coverage->id)->sole();

        $this->assertSame('400.00', $period->netSettledAmount(), 'net settled must be exactly 400.00 — never 800.00 (double-settled) and never 0 (both rejected)');
        $this->assertSame('settled', $period->settlementStatus());
    }

    /**
     * Coordinator-requested item 3: two simultaneous raw-SQL inserts
     * attempting OVERLAPPING periods for the SAME ServiceCoverage,
     * bypassing the Eloquent model layer entirely (raw DB inserts, two
     * real forked connections) — the real
     * installment_coverage_periods_no_overlap EXCLUDE USING gist
     * constraint (confirmed present via psql \d) must allow only one to
     * succeed.
     */
    public function test_two_concurrent_raw_inserts_of_overlapping_periods_for_the_same_coverage_are_rejected_by_the_real_exclude_constraint(): void
    {
        ['coverage' => $coverage, 'installment' => $firstInstallment] = $this->seedBundledInvoiceWithCoverage();

        // A second installment on the same invoice/schedule (the schedule
        // already has 10 monthly installments — Sep 2026 through Jun
        // 2027) so both raw inserts target a REAL installment row (the
        // FK must still be satisfied) but with overlapping period_start/
        // period_end date ranges for the SAME coverage.
        $secondInstallment = \App\Models\InvoiceInstallment::on($this->connectionName)
            ->where('invoice_id', $firstInstallment->invoice_id)
            ->where('id', '!=', $firstInstallment->id)
            ->orderBy('sequence')->first();

        // The real automatic-coverage issuance (seedBundledInvoiceWithCoverage()
        // -> InvoiceIssuanceService::issue()) already created ONE period
        // row per installment for this SAME coverage — a raw insert
        // reusing either installment id would collide with THAT row's
        // own (invoice_installment_id, service_coverage_id) UNIQUE
        // constraint first, never reaching the overlap EXCLUDE
        // constraint this test specifically targets. Those two
        // installments' own auto-created rows are removed first (a raw
        // delete, deliberately bypassing InstallmentCoveragePeriod's own
        // immutability guard — legitimate here, this is test SETUP
        // clearing space for the race, not exercising or defeating the
        // guard itself) so the race starts from a genuinely clean slate
        // for exactly these two installments.
        DB::connection($this->connectionName)->table('installment_coverage_periods')
            ->whereIn('invoice_installment_id', [$firstInstallment->id, $secondInstallment->id])
            ->where('service_coverage_id', $coverage->id)
            ->delete();

        $barrier = tempnam(sys_get_temp_dir(), 'pg_overlap_race_');
        unlink($barrier);
        $resultFileA = $barrier.'.a';
        $resultFileB = $barrier.'.b';

        $spawn = function (string $resultFile, int $installmentId, string $start, string $end) use ($barrier, $coverage): int {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork() failed.');
            }
            if ($pid === 0) {
                DB::purge($this->connectionName);
                while (! file_exists($barrier)) {
                    usleep(2000);
                }
                try {
                    DB::connection($this->connectionName)->table('installment_coverage_periods')->insert([
                        'invoice_installment_id' => $installmentId,
                        'service_coverage_id' => $coverage->id,
                        'period_start' => $start,
                        'period_end' => $end,
                        'created_at' => now(),
                    ]);
                    file_put_contents($resultFile, 'inserted');
                } catch (\Illuminate\Database\QueryException $e) {
                    file_put_contents($resultFile, 'db_rejected:'.$e->getMessage());
                } catch (\Throwable $e) {
                    file_put_contents($resultFile, 'CRASHED:'.get_class($e).':'.$e->getMessage());
                }
                exit(0);
            }

            return $pid;
        };

        // Deliberately OVERLAPPING ranges (Sep 15-Oct 15 vs Oct 1-Oct 31)
        // — genuinely different installments, but their period ranges
        // for the SAME coverage overlap in October.
        $pidA = $spawn($resultFileA, $firstInstallment->id, '2026-09-15', '2026-10-15');
        $pidB = $spawn($resultFileB, $secondInstallment->id, '2026-10-01', '2026-10-31');
        touch($barrier);
        pcntl_waitpid($pidA, $statusA);
        pcntl_waitpid($pidB, $statusB);

        $resultA = file_exists($resultFileA) ? file_get_contents($resultFileA) : null;
        $resultB = file_exists($resultFileB) ? file_get_contents($resultFileB) : null;
        @unlink($resultFileA);
        @unlink($resultFileB);
        @unlink($barrier);

        $this->assertNotNull($resultA);
        $this->assertNotNull($resultB);
        $this->assertStringNotContainsString('CRASHED', (string) $resultA, "must fail as a clean DB rejection, not crash: {$resultA}");
        $this->assertStringNotContainsString('CRASHED', (string) $resultB, "must fail as a clean DB rejection, not crash: {$resultB}");

        // Real-server finding (reported honestly, per the coordinator's
        // explicit instruction, rather than papered over): under genuine
        // concurrent load, PostgreSQL rejects the second overlapping
        // insert via ONE of two distinct mechanisms — a plain exclusion-
        // constraint violation (SQLSTATE 23P01) when the two
        // transactions serialize cleanly enough, or a genuine deadlock
        // (SQLSTATE 40P01) when both transactions are validating the
        // EXCLUDE constraint against each other's still-uncommitted row
        // at the same moment (PostgreSQL's own documented behavior for
        // exclusion constraints under true concurrency — each waits on a
        // ShareLock the other holds). Both are the database correctly
        // preventing the invalid overlapping state; this test accepts
        // either failure class for the loser, and separately asserts the
        // one invariant that actually matters directly against the
        // persisted rows below — never widened to accept anything else
        // (a crash, a silent success of both, or an unrelated error).
        // This codebase's own application code does not currently retry
        // 40P01 specifically (only UniqueConstraintViolationException,
        // for the invoice/operation idempotency-key race) — per the
        // coordinator's explicit instruction not to retry deadlocks away
        // unless the application is designed to handle that class of
        // error, no retry logic was added; this is reported as a
        // finding for the final report, not silently fixed.
        $outcomes = [$resultA, $resultB];
        $inserted = collect($outcomes)->filter(fn ($r) => $r === 'inserted');
        $rejected = collect($outcomes)->filter(fn ($r) => str_starts_with((string) $r, 'db_rejected:'));
        $this->assertCount(1, $inserted, 'exactly one overlapping insert must succeed');
        $this->assertCount(1, $rejected, 'the other must be rejected by the real database, one way or another');
        $rejectionReason = (string) $rejected->first();
        $this->assertTrue(
            str_contains($rejectionReason, 'installment_coverage_periods_no_overlap') || str_contains($rejectionReason, 'SQLSTATE[40P01]'),
            "rejection must be the EXCLUDE constraint itself, or a real deadlock while checking it — never some unrelated error: {$rejectionReason}"
        );

        // The invariant that actually matters, checked directly against
        // what's really persisted, independent of which specific error
        // class rejected the loser: exactly ONE period row for these two
        // installments/this coverage, never two overlapping ones.
        $persisted = DB::connection($this->connectionName)->table('installment_coverage_periods')
            ->whereIn('invoice_installment_id', [$firstInstallment->id, $secondInstallment->id])
            ->where('service_coverage_id', $coverage->id)->get();
        $this->assertCount(1, $persisted, 'exactly one period row must actually be persisted — no overlapping state ever exists in the data');
    }

    // ================================================================
    // Coordinator-requested item 4: direct PostgreSQL integrity, via
    // raw SQL bypassing Eloquent entirely, against the real server.
    // Each of these targets a DB-level protection this pass added
    // (HIGH 4) or pass #2 already added, confirmed present via psql
    // \d installment_coverage_periods before these tests were written.
    // ================================================================

    private function rawInsertPeriod(int $installmentId, int $coverageId, string $start, string $end): void
    {
        DB::connection($this->connectionName)->table('installment_coverage_periods')->insert([
            'invoice_installment_id' => $installmentId,
            'service_coverage_id' => $coverageId,
            'period_start' => $start,
            'period_end' => $end,
            'created_at' => now(),
        ]);
    }

    public function test_direct_sql_reversed_dates_are_rejected_by_the_real_check_constraint(): void
    {
        ['coverage' => $coverage, 'installment' => $installment] = $this->seedBundledInvoiceWithCoverage();
        DB::connection($this->connectionName)->table('installment_coverage_periods')
            ->where('invoice_installment_id', $installment->id)->where('service_coverage_id', $coverage->id)->delete();

        $this->expectException(\Illuminate\Database\QueryException::class);
        try {
            $this->rawInsertPeriod($installment->id, $coverage->id, '2026-09-30', '2026-09-01');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('installment_coverage_periods_order_check', $e->getMessage(), 'must be rejected by the real CHECK (period_end >= period_start) constraint specifically');
            throw $e;
        }
    }

    public function test_direct_sql_cross_invoice_mapping_is_rejected_by_the_real_trigger(): void
    {
        ['coverage' => $coverage, 'installment' => $installment] = $this->seedBundledInvoiceWithCoverage();
        $otherInvoice = DB::connection($this->connectionName)->table('invoices')->insertGetId([
            'student_id' => Student::on($this->connectionName)->first()->id, 'academic_year_id' => AcademicYear::on($this->connectionName)->first()->id,
            'customer_name' => 'x', 'currency' => 'EGP', 'subtotal_amount' => '100', 'total_amount' => '100',
            'discount_amount' => '0', 'paid_amount' => '0', 'remaining_amount' => '100', 'status' => 'unpaid',
            'due_date' => '2027-06-30', 'invoice_number' => 'DIRECT-SQL-TEST-'.uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherInstallmentId = DB::connection($this->connectionName)->table('invoice_installments')->insertGetId([
            'invoice_id' => $otherInvoice, 'name_ru' => 'x', 'sequence' => 1, 'due_date' => now(),
            'amount' => '100', 'paid_amount' => '0', 'remaining_amount' => '100', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        try {
            // $otherInstallment belongs to a DIFFERENT invoice than
            // $coverage's own InvoiceItem — the real cross-invoice
            // trigger function must reject this.
            $this->rawInsertPeriod($otherInstallmentId, $coverage->id, $coverage->coverage_start->toDateString(), $coverage->coverage_start->copy()->addDays(5)->toDateString());
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('installment and coverage must belong to the same invoice', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Cross-STUDENT mapping is structurally the SAME check as
     * cross-invoice here (an invoice always belongs to exactly one
     * student) — a different student's invoice/installment is
     * necessarily a different invoice too, so the identical trigger
     * catches it. Verified directly with a genuinely different student.
     */
    public function test_direct_sql_cross_student_mapping_is_rejected_by_the_real_trigger(): void
    {
        ['coverage' => $coverage] = $this->seedBundledInvoiceWithCoverage();
        $class = DB::connection($this->connectionName)->table('classes')->first();
        $otherStudentId = DB::connection($this->connectionName)->table('students')->insertGetId([
            'last_name_ru' => 'Другой', 'first_name_ru' => 'Студент', 'patronymic_ru' => 'Тест',
            'phone' => '+201009995566', 'class_id' => $class->id, 'status' => 'registration_completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $year = AcademicYear::on($this->connectionName)->first();
        $otherInvoiceId = DB::connection($this->connectionName)->table('invoices')->insertGetId([
            'student_id' => $otherStudentId, 'academic_year_id' => $year->id,
            'customer_name' => 'y', 'currency' => 'EGP', 'subtotal_amount' => '100', 'total_amount' => '100',
            'discount_amount' => '0', 'paid_amount' => '0', 'remaining_amount' => '100', 'status' => 'unpaid',
            'due_date' => '2027-06-30', 'invoice_number' => 'DIRECT-SQL-TEST-STUDENT-'.uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherStudentInstallmentId = DB::connection($this->connectionName)->table('invoice_installments')->insertGetId([
            'invoice_id' => $otherInvoiceId, 'name_ru' => 'y', 'sequence' => 1, 'due_date' => now(),
            'amount' => '100', 'paid_amount' => '0', 'remaining_amount' => '100', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        try {
            $this->rawInsertPeriod($otherStudentInstallmentId, $coverage->id, $coverage->coverage_start->toDateString(), $coverage->coverage_start->copy()->addDays(5)->toDateString());
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('installment and coverage must belong to the same invoice', $e->getMessage());
            throw $e;
        }
    }

    public function test_direct_sql_out_of_bounds_period_is_rejected_by_the_real_trigger(): void
    {
        ['coverage' => $coverage, 'installment' => $installment] = $this->seedBundledInvoiceWithCoverage();
        DB::connection($this->connectionName)->table('installment_coverage_periods')
            ->where('invoice_installment_id', $installment->id)->where('service_coverage_id', $coverage->id)->delete();

        $this->expectException(\Illuminate\Database\QueryException::class);
        try {
            // Far outside the coverage's own coverage_start/coverage_end span.
            $this->rawInsertPeriod($installment->id, $coverage->id, '2020-01-01', '2020-01-31');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('period must lie within its ServiceCoverage own coverage span', $e->getMessage());
            throw $e;
        }
    }

    public function test_direct_sql_overlapping_period_for_the_same_coverage_is_rejected_by_the_real_exclude_constraint(): void
    {
        ['coverage' => $coverage, 'installment' => $installment] = $this->seedBundledInvoiceWithCoverage();
        // The installment's own auto-created row already occupies its
        // exact period — inserting a SECOND, overlapping period for the
        // SAME coverage on a DIFFERENT (but date-overlapping) window
        // must be rejected by the real EXCLUDE constraint.
        $secondInstallment = \App\Models\InvoiceInstallment::on($this->connectionName)
            ->where('invoice_id', $installment->invoice_id)->where('id', '!=', $installment->id)
            ->orderBy('sequence')->first();
        DB::connection($this->connectionName)->table('installment_coverage_periods')
            ->where('invoice_installment_id', $secondInstallment->id)->where('service_coverage_id', $coverage->id)->delete();

        $this->expectException(\Illuminate\Database\QueryException::class);
        try {
            // First period is Sep 1-30 (the real auto-created one); this
            // second insert deliberately overlaps it (Sep 15-Oct 15) via
            // a genuinely different installment.
            $this->rawInsertPeriod($secondInstallment->id, $coverage->id, '2026-09-15', '2026-10-15');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('installment_coverage_periods_no_overlap', $e->getMessage());
            throw $e;
        }
    }

    public function test_direct_sql_updates_cannot_break_bounds_order_ownership_or_overlap_on_postgresql(): void
    {
        ['coverage' => $coverage, 'installment' => $first] = $this->seedBundledInvoiceWithCoverage();
        $periods = \App\Models\InstallmentCoveragePeriod::on($this->connectionName)
            ->where('service_coverage_id', $coverage->id)->orderBy('period_start')->get();
        $target = $periods[1];
        $original = DB::connection($this->connectionName)->table('installment_coverage_periods')->where('id', $target->id)->first();

        $otherInvoice = DB::connection($this->connectionName)->table('invoices')->insertGetId([
            'student_id' => $coverage->student_id, 'academic_year_id' => AcademicYear::on($this->connectionName)->first()->id,
            'customer_name' => 'other', 'currency' => 'EGP', 'subtotal_amount' => '1', 'total_amount' => '1', 'discount_amount' => '0',
            'paid_amount' => '0', 'remaining_amount' => '1', 'status' => 'unpaid', 'due_date' => '2027-06-30',
            'invoice_number' => 'UPDATE-INTEGRITY-'.uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherInstallment = DB::connection($this->connectionName)->table('invoice_installments')->insertGetId([
            'invoice_id' => $otherInvoice, 'name_ru' => 'other', 'sequence' => 1, 'due_date' => now(), 'amount' => '1',
            'paid_amount' => '0', 'remaining_amount' => '1', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $mutations = [
            ['period_start' => '2020-01-01', 'period_end' => '2020-01-31'],
            ['period_start' => '2026-10-31', 'period_end' => '2026-10-01'],
            ['invoice_installment_id' => $otherInstallment],
            ['period_start' => $periods[0]->period_start->toDateString(), 'period_end' => $periods[0]->period_end->toDateString()],
        ];
        foreach ($mutations as $mutation) {
            try {
                DB::connection($this->connectionName)->table('installment_coverage_periods')->where('id', $target->id)->update($mutation);
                $this->fail('Expected PostgreSQL UPDATE integrity rejection.');
            } catch (\Illuminate\Database\QueryException) {
                $fresh = DB::connection($this->connectionName)->table('installment_coverage_periods')->where('id', $target->id)->first();
                $this->assertEquals($original, $fresh);
            }
        }

        $classId = DB::connection($this->connectionName)->table('classes')->value('id');
        $otherStudent = DB::connection($this->connectionName)->table('students')->insertGetId([
            'last_name_ru' => 'Owner', 'first_name_ru' => 'Mismatch', 'patronymic_ru' => 'Test',
            'phone' => '+2010'.random_int(10000000, 99999999), 'class_id' => $classId,
            'status' => 'registration_completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            DB::connection($this->connectionName)->table('service_coverages')->where('id', $coverage->id)->update(['student_id' => $otherStudent]);
            $this->fail('Expected direct ServiceCoverage ownership corruption to be rejected.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('service coverage student must match invoice student', $e->getMessage());
            $this->assertSame($coverage->student_id, DB::connection($this->connectionName)->table('service_coverages')->where('id', $coverage->id)->value('student_id'));
        }
    }
}
