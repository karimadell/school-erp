<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\InvoiceIssuanceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Finance V2, Phase 2D corrective pass #2 (HIGH 1 — PostgreSQL-safe
 * concurrent idempotency), strengthened in corrective pass #3 (HIGH 2).
 *
 * The bug this test targets is PostgreSQL-specific and structurally
 * cannot be reproduced on SQLite (see InvoiceIssuanceIdempotencyTest's
 * own sequential test and its docblock for why): once ANY statement
 * inside a PostgreSQL transaction raises an error — a unique-violation on
 * invoices.idempotency_key included — the ENTIRE transaction enters an
 * aborted state, and every subsequent statement in that same transaction
 * fails with "current transaction is aborted, commands ignored until end
 * of transaction block," even a harmless SELECT. InvoiceIssuanceService::
 * issue()'s pass-#1 shape caught the unique-violation and re-queried
 * INSIDE the same transaction — safe on SQLite, broken on real
 * PostgreSQL. The pass-#2 fix (unchanged in this pass — no bug found in
 * it) lets the violation propagate out of DB::transaction() (which then
 * rolls back cleanly) and only recovers the winning row in a fresh,
 * non-aborted transaction/connection state afterward.
 *
 * ============================================================
 * THIS TEST REQUIRES A REAL, REACHABLE POSTGRESQL SERVER.
 * ============================================================
 * This sandboxed development environment has no PostgreSQL server, no
 * `psql` client, and no Docker available to start one — this test was
 * therefore WRITTEN but has NOT been executed against a real PostgreSQL
 * instance, and that is being stated plainly rather than silently
 * omitted or quietly relabeled as equivalent to the sqlite coverage
 * elsewhere in this suite. Before relying on it, run it for real and
 * confirm.
 *
 * To run it for real:
 *   1. Have a real PostgreSQL server reachable (e.g. `docker run -d -p 5432:5432
 *      -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=school_erp_pgsql_test postgres:16`).
 *   2. Point this test at it via environment variables (falls back to
 *      config('database.connections.pgsql') otherwise):
 *      PGSQL_TEST_HOST, PGSQL_TEST_PORT, PGSQL_TEST_DATABASE,
 *      PGSQL_TEST_USERNAME, PGSQL_TEST_PASSWORD.
 *   3. php artisan test --filter=InvoiceIssuancePostgresConcurrencyTest
 *
 * If the connection cannot be established, every test in this file
 * SKIPS (not fails, not silently passes) with a clear reason — verified
 * in this environment, since no PostgreSQL server is reachable here.
 *
 * Concurrency is real OS-level concurrency (pcntl_fork()), not a
 * simulated/sequential stand-in, and — corrective pass #3's specific
 * strengthening — genuinely SYMMETRIC: TWO forked children, each a
 * separate PHP process with its OWN PostgreSQL connection, both racing
 * the REAL InvoiceIssuanceService::issue() call against the SAME key at
 * (as close as OS scheduling allows) the same moment, synchronized via a
 * shared barrier file. Pass #2's own version of this test raced a real
 * child against a hand-rolled raw-SQL "parent" row carrying a fabricated
 * hash — which could only ever end in rejection (the fabricated hash can
 * never match a real payload's canonical hash), so it had to accept
 * "replay OR rejection" as if either were an equally valid outcome. That
 * is no longer true here: since both racers submit byte-identical real
 * payloads, the LOSER's outcome is deterministic — a genuine successful
 * replay (the exact same Invoice id the winner got), never a rejection —
 * and this test asserts exactly that, not merely "one of them didn't
 * error."
 */
class InvoiceIssuancePostgresConcurrencyTest extends TestCase
{
    private ?string $connectionName = null;

    private bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension not available — cannot fork genuinely concurrent processes.');
        }

        config([
            'database.connections.pgsql_concurrency_test' => array_merge(
                config('database.connections.pgsql', []),
                array_filter([
                    'host' => env('PGSQL_TEST_HOST'),
                    'port' => env('PGSQL_TEST_PORT'),
                    'database' => env('PGSQL_TEST_DATABASE'),
                    'username' => env('PGSQL_TEST_USERNAME'),
                    'password' => env('PGSQL_TEST_PASSWORD'),
                ], fn ($v) => $v !== null),
            ),
        ]);
        $this->connectionName = 'pgsql_concurrency_test';

        try {
            DB::connection($this->connectionName)->select('select 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('No reachable PostgreSQL server for '.static::class.' — set PGSQL_TEST_* env vars or config(database.connections.pgsql). Underlying error: '.$e->getMessage());
        }

        Artisan::call('migrate', ['--database' => $this->connectionName, '--force' => true]);
        DB::connection($this->connectionName)->statement('SET client_min_messages TO WARNING');
        $this->migrated = true;
        config(['database.default' => $this->connectionName]);
    }

    protected function tearDown(): void
    {
        if ($this->migrated) {
            // A real Postgres run surfaced that migrate:rollback here can
            // throw — 2026_08_09_110100_add_session_and_actor_to_cash_transactions.php's
            // own down() calls Blueprint::dropConstrainedForeignKey(), a
            // method that does not exist on this Laravel version. That
            // migration predates this Phase 2D work entirely (2026-08-09
            // vs this pass's own 2026-09-04 migrations) and is unrelated
            // to it — out of scope to fix here, and PHPUnit would
            // otherwise mark an otherwise-fully-passing test as failed
            // for a teardown-only exception thrown AFTER every real
            // assertion in the test body already ran. Caught and
            // reported (never silently swallowed) so cleanup best-effort
            // failing never masks the test's own real result.
            try {
                Artisan::call('migrate:rollback', ['--database' => $this->connectionName, '--force' => true, '--step' => 1000]);
            } catch (\Throwable $e) {
                fwrite(STDERR, "\n[".static::class."::tearDown] migrate:rollback failed (pre-existing, unrelated migration bug — see comment above): {$e->getMessage()}\n");
            }
        }
        parent::tearDown();
    }

    /** @return array{student: Student, fee: Fee, year: AcademicYear, grade: Grade} */
    private function seedStudentAndFee(): array
    {
        (new RolesAndPermissionsSeeder)->run();
        $year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $stage = Stage::create(['name' => 'Test Stage', 'order' => 1, 'is_active' => true]);
        $grade = Grade::forceCreate(['name' => 'Test Grade', 'stage_id' => $stage->id, 'level' => 1]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'A', 'name_ru' => 'A', 'name_ar' => 'A', 'is_active' => true]);
        // Coordinator-supplied fixes (real PostgreSQL run surfaced both):
        // Student has no HasFactory trait / registered factory anywhere
        // in this codebase — Student::factory() was never going to work,
        // it only never got exercised because every prior SQLite run
        // self-gated before reaching this line. Create directly, same
        // shape as FinanceOperationsTestCase's own fixture (that file,
        // line ~46). EnrollmentMode::create() with a fixed code='regular'
        // collides on a real, strictly-enforced Postgres unique
        // constraint the moment more than one test in this file (or a
        // forked child re-running setup) hits it — SQLite's weaker/
        // shared-connection behavior let this slide; firstOrCreate() is
        // idempotent regardless of how many times this runs.
        $mode = EnrollmentMode::firstOrCreate(['code' => 'regular'], ['name_ru' => 'Test Mode', 'is_active' => true]);
        $student = Student::create(['last_name_ru' => 'Раса', 'first_name_ru' => 'Тестовая', 'patronymic_ru' => 'Постгрес', 'phone' => '+201009998877', 'class_id' => $class->id, 'status' => 'registration_completed']);
        // A real Postgres run surfaced a THIRD bug here too: this
        // Enrollment::create() call was missing several NOT NULL
        // columns (stage_id, academic_year, enrollment_date, enrolled_at,
        // status) that SQLite silently tolerated but PostgreSQL
        // correctly rejects — matched to FinanceOperationsTestCase's own
        // full fixture shape (that file, line ~47).
        Enrollment::create(['student_id' => $student->id, 'academic_year_id' => $year->id, 'enrollment_mode_id' => $mode->id, 'stage_id' => $stage->id, 'grade_id' => $grade->id, 'class_id' => $class->id, 'academic_year' => $year->name, 'enrollment_date' => '2026-09-01', 'enrolled_at' => '2026-09-01', 'status' => 'active', 'is_active' => true]);
        $fee = Fee::create(['name_ru' => 'Test Fee', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1000.00', 'is_active' => true]);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        return compact('student', 'fee', 'year', 'grade');
    }

    /**
     * Forks two children, both racing InvoiceIssuanceService::issue()
     * against the SAME key with $dataA/$dataB (byte-identical for the
     * same-payload race, deliberately different for the conflicting-
     * payload race), synchronized on a shared barrier file so both
     * attempt their submission at (as close as OS scheduling allows) the
     * same moment. Returns both children's raw result strings.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function runConcurrentRace(Student $student, array $dataA, array $dataB, string $key, User $actor): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 'pg_race_');
        unlink($barrier);
        $resultFileA = $barrier.'.a';
        $resultFileB = $barrier.'.b';

        $spawn = function (array $data, string $resultFile) use ($barrier, $student, $key, $actor): int {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork() failed — cannot run this test.');
            }
            if ($pid === 0) {
                DB::purge($this->connectionName);
                while (! file_exists($barrier)) {
                    usleep(2000);
                }
                try {
                    $result = app(InvoiceIssuanceService::class)->issue($student, $data, $actor, idempotencyKey: $key);
                    file_put_contents($resultFile, 'invoice:'.$result->id);
                } catch (\Illuminate\Validation\ValidationException $e) {
                    file_put_contents($resultFile, 'rejected:'.json_encode($e->errors()));
                } catch (\Throwable $e) {
                    file_put_contents($resultFile, 'CRASHED:'.get_class($e).':'.$e->getMessage());
                }
                exit(0);
            }

            return $pid;
        };

        $pidA = $spawn($dataA, $resultFileA);
        $pidB = $spawn($dataB, $resultFileB);
        touch($barrier); // release both children at once

        pcntl_waitpid($pidA, $statusA);
        pcntl_waitpid($pidB, $statusB);

        $resultA = file_exists($resultFileA) ? file_get_contents($resultFileA) : null;
        $resultB = file_exists($resultFileB) ? file_get_contents($resultFileB) : null;
        @unlink($resultFileA);
        @unlink($resultFileB);
        @unlink($barrier);

        return [$resultA, $resultB];
    }

    public function test_two_genuinely_concurrent_same_key_same_payload_submissions_converge_on_exactly_one_invoice_graph(): void
    {
        ['student' => $student, 'fee' => $fee, 'year' => $year] = $this->seedStudentAndFee();
        $accountant = User::factory()->create(['is_active' => true]);
        $accountant->assignRole('accountant');
        $key = (string) Str::uuid();
        $data = [
            'student_id' => $student->id, 'academic_year_id' => $year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'quantity' => 1]],
            'payment_type' => 'one_time',
        ];

        [$resultA, $resultB] = $this->runConcurrentRace($student, $data, $data, $key, $accountant);

        $this->assertNotNull($resultA, 'first racer produced no result at all — likely crashed before writing one');
        $this->assertNotNull($resultB, 'second racer produced no result at all — likely crashed before writing one');
        $this->assertStringNotContainsString('CRASHED', (string) $resultA, "first racer must recover cleanly, not crash: {$resultA}");
        $this->assertStringNotContainsString('CRASHED', (string) $resultB, "second racer must recover cleanly, not crash: {$resultB}");

        // Both racers submitted byte-identical payloads — the loser's
        // outcome is deterministic: a genuine successful replay of the
        // winner's own Invoice id, never a rejection.
        $this->assertStringStartsWith('invoice:', (string) $resultA);
        $this->assertStringStartsWith('invoice:', (string) $resultB);
        $this->assertSame($resultA, $resultB, 'both racers must resolve to the exact same winning Invoice id');

        $invoiceId = (int) str_replace('invoice:', '', (string) $resultA);
        $this->assertSame(1, DB::connection($this->connectionName)->table('invoices')->where('idempotency_key', $key)->count(), 'exactly one invoice row for this key, never two');
        $this->assertSame(1, DB::connection($this->connectionName)->table('invoice_items')->where('invoice_id', $invoiceId)->count(), 'exactly one item set, never a duplicated line');
        $this->assertSame(1, DB::connection($this->connectionName)->table('invoice_installments')->where('invoice_id', $invoiceId)->count(), 'exactly one schedule, never duplicated');
    }

    public function test_two_genuinely_concurrent_same_key_conflicting_payload_submissions_produce_one_winner_and_one_deterministic_rejection(): void
    {
        ['student' => $student, 'fee' => $fee, 'year' => $year, 'grade' => $grade] = $this->seedStudentAndFee();
        $otherFee = Fee::create(['name_ru' => 'Other Fee', 'category' => Fee::CATEGORY_TUITION, 'amount' => '500.00', 'is_active' => true]);
        FeePrice::create(['fee_id' => $otherFee->id, 'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'amount' => '500.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $accountant = User::factory()->create(['is_active' => true]);
        $accountant->assignRole('accountant');
        $key = (string) Str::uuid();

        $dataA = [
            'student_id' => $student->id, 'academic_year_id' => $year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'quantity' => 1]], 'payment_type' => 'one_time',
        ];
        $dataB = [
            'student_id' => $student->id, 'academic_year_id' => $year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $otherFee->id, 'quantity' => 1]], 'payment_type' => 'one_time',
        ];

        [$resultA, $resultB] = $this->runConcurrentRace($student, $dataA, $dataB, $key, $accountant);

        $this->assertNotNull($resultA);
        $this->assertNotNull($resultB);
        $this->assertStringNotContainsString('CRASHED', (string) $resultA, "must recover cleanly, not crash: {$resultA}");
        $this->assertStringNotContainsString('CRASHED', (string) $resultB, "must recover cleanly, not crash: {$resultB}");

        // Materially different payloads racing for the same key: exactly
        // ONE succeeds (whichever wins the row-lock race), the OTHER
        // deterministically rejects with an idempotency_key hash
        // mismatch — never both succeeding, never both silently
        // rejecting, never a raw unrecovered DB error.
        $outcomes = [$resultA, $resultB];
        $winners = collect($outcomes)->filter(fn ($r) => str_starts_with((string) $r, 'invoice:'));
        $rejections = collect($outcomes)->filter(fn ($r) => str_starts_with((string) $r, 'rejected:'));
        $this->assertCount(1, $winners, 'exactly one racer must win and create the invoice');
        $this->assertCount(1, $rejections, 'exactly one racer must be deterministically rejected');
        $this->assertSame(1, DB::connection($this->connectionName)->table('invoices')->where('idempotency_key', $key)->count());
    }
}
