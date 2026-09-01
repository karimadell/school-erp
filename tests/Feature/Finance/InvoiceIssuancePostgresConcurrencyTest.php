<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\Invoice;
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
 * concurrent idempotency).
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
 * PostgreSQL. The pass-#2 fix lets the violation propagate out of
 * DB::transaction() (which then rolls back cleanly) and only recovers
 * the winning row in a fresh, non-aborted transaction/connection state
 * afterward.
 *
 * ============================================================
 * THIS TEST REQUIRES A REAL, REACHABLE POSTGRESQL SERVER.
 * ============================================================
 * This sandboxed development environment has no PostgreSQL server, no
 * `psql` client, and no Docker available to start one — this test was
 * therefore WRITTEN but has NOT been executed against a real PostgreSQL
 * instance, and that is being stated plainly rather than silently
 * omitted or quietly relabeled as equivalent to the sqlite coverage
 * above. It is written as carefully and correctly as possible from
 * direct reading of PostgreSQL's documented transaction-abort behavior,
 * but has not been verified to actually pass. Before relying on it, run
 * it for real and confirm.
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
 * simulated/sequential stand-in: the parent process begins a real
 * transaction, inserts the invoice row for a specific idempotency_key,
 * and deliberately holds it open (uncommitted) while a forked CHILD
 * process — a genuinely separate PHP process with its OWN PostgreSQL
 * connection — runs the REAL InvoiceIssuanceService::issue() against the
 * SAME key. The child's own INSERT blocks on PostgreSQL's row lock until
 * the parent commits, at which point the child's insert resolves as a
 * real unique-constraint violation raised by PostgreSQL itself, and the
 * child must then recover (return the parent's row, or throw a clean
 * idempotency_key ValidationException) rather than crash with an
 * unrecovered "current transaction is aborted" PDOException.
 */
class InvoiceIssuancePostgresConcurrencyTest extends TestCase
{
    private ?string $connectionName = null;

    private bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension not available — cannot fork a genuinely concurrent second process.');
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
    }

    protected function tearDown(): void
    {
        if ($this->migrated) {
            Artisan::call('migrate:rollback', ['--database' => $this->connectionName, '--force' => true, '--step' => 1000]);
        }
        parent::tearDown();
    }

    public function test_a_real_concurrent_duplicate_key_race_on_postgresql_recovers_cleanly_without_an_aborted_transaction_error(): void
    {
        config(['database.default' => $this->connectionName]);
        (new RolesAndPermissionsSeeder)->run();
        $accountant = User::factory()->create(['is_active' => true]);
        $accountant->assignRole('accountant');

        $year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $stage = Stage::create(['name' => 'Test Stage', 'order' => 1, 'is_active' => true]);
        $grade = Grade::forceCreate(['name' => 'Test Grade', 'stage_id' => $stage->id, 'level' => 1]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'A', 'name_ru' => 'A', 'name_ar' => 'A', 'is_active' => true]);
        $mode = EnrollmentMode::create(['code' => 'regular', 'name_ru' => 'Test Mode', 'is_active' => true]);
        $student = Student::factory()->create();
        $enrollment = Enrollment::create(['student_id' => $student->id, 'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'class_id' => $class->id, 'enrollment_mode_id' => $mode->id, 'is_active' => true]);
        $fee = Fee::create(['name_ru' => 'Test Fee', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1000.00', 'is_active' => true]);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        $key = (string) Str::uuid();
        $data = [
            'student_id' => $student->id, 'academic_year_id' => $year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'quantity' => 1]],
            'payment_type' => 'one_time',
        ];

        // A rendezvous file: the child signals "about to insert" so the
        // parent can hold its own transaction open until the child is
        // genuinely blocked on the row lock, then commit, releasing the
        // child into a real unique-violation.
        $barrier = tempnam(sys_get_temp_dir(), 'pg_race_');
        unlink($barrier);

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('pcntl_fork() failed — cannot run this test.');
        }

        if ($pid === 0) {
            // Child: waits for the parent's signal, then races issue()
            // against the same key on its own fresh connection.
            DB::purge($this->connectionName);
            while (! file_exists($barrier)) {
                usleep(5000);
            }
            try {
                $result = app(InvoiceIssuanceService::class)->issue($student, $data, $accountant, idempotencyKey: $key);
                file_put_contents($barrier.'.child_result', 'invoice:'.$result->id);
            } catch (\Illuminate\Validation\ValidationException $e) {
                file_put_contents($barrier.'.child_result', 'rejected:'.json_encode($e->errors()));
            } catch (\Throwable $e) {
                file_put_contents($barrier.'.child_result', 'CRASHED:'.get_class($e).':'.$e->getMessage());
            }
            exit(0);
        }

        // Parent: hold its own invoice insert open, signal the child,
        // give it time to block on the row, then commit.
        DB::connection($this->connectionName)->beginTransaction();
        $parentInvoice = DB::connection($this->connectionName)->table('invoices')->insertGetId([
            'idempotency_key' => $key, 'idempotency_hash' => hash('sha256', 'parent-wins'),
            'student_id' => $student->id, 'currency' => 'EGP',
            'subtotal_amount' => '1000.00', 'total_amount' => '1000.00', 'discount_amount' => '0.00',
            'paid_amount' => '0.00', 'remaining_amount' => '1000.00', 'status' => 'unpaid',
            'due_date' => '2027-06-30', 'created_at' => now(), 'updated_at' => now(),
        ]);
        touch($barrier);
        usleep(300000);
        DB::connection($this->connectionName)->commit();

        pcntl_waitpid($pid, $status);
        $resultFile = $barrier.'.child_result';
        $childResult = file_exists($resultFile) ? file_get_contents($resultFile) : null;
        @unlink($resultFile);

        $this->assertNotNull($childResult, 'child process produced no result at all — likely crashed before writing one');
        $this->assertStringNotContainsString('CRASHED', (string) $childResult, "child issue() call must recover cleanly, not crash: {$childResult}");
        $this->assertTrue(
            str_starts_with((string) $childResult, 'invoice:'.$parentInvoice) || str_starts_with((string) $childResult, 'rejected:'),
            "child must either return the parent's own winning invoice or cleanly reject — got: {$childResult}"
        );
        $this->assertSame(1, DB::connection($this->connectionName)->table('invoices')->where('idempotency_key', $key)->count(), 'exactly one invoice for this key, never two');
    }
}
