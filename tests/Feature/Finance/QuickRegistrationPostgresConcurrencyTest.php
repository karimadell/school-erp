<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\QuickRegistrationOperation;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\User;
use App\Services\Admissions\QuickStudentRegistrationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Finance V2, Phase 2D corrective pass #3 (HIGH 2 — PostgreSQL
 * concurrency coverage for QuickStudentRegistrationService's own
 * operation-level idempotency, added in this same pass's HIGH 2/P0
 * Blocker work). Same rigor and the same honest gating as
 * InvoiceIssuancePostgresConcurrencyTest — see that file's own docblock
 * for the full explanation of why SQLite cannot exercise this, and
 * exactly how to run this for real.
 *
 * ============================================================
 * THIS TEST REQUIRES A REAL, REACHABLE POSTGRESQL SERVER.
 * ============================================================
 * Not executed in this sandboxed environment (no PostgreSQL server, no
 * `psql`, no Docker) — written but unverified here; every test SKIPS
 * with a clear reason, confirmed in this environment.
 *
 * Two forked children race register() with the SAME idempotency_token
 * and byte-identical payloads. Since register() creates its
 * quick_registration_operations row BEFORE Student creation, the loser
 * must resolve to the exact same Student/Enrollment/Invoice graph the
 * winner created — never a duplicate Student, never a raw DB error.
 */
class QuickRegistrationPostgresConcurrencyTest extends TestCase
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
            // See InvoiceIssuancePostgresConcurrencyTest::tearDown()'s own
            // comment — 2026_08_09_110100_add_session_and_actor_to_cash_transactions.php's
            // down() can throw on this Laravel version; unrelated to
            // Phase 2D, out of scope to fix here. Caught and reported so
            // it never masks this test's own already-completed real
            // result.
            try {
                Artisan::call('migrate:rollback', ['--database' => $this->connectionName, '--force' => true, '--step' => 1000]);
            } catch (\Throwable $e) {
                fwrite(STDERR, "\n[".static::class."::tearDown] migrate:rollback failed (pre-existing, unrelated migration bug): {$e->getMessage()}\n");
            }
        }
        parent::tearDown();
    }

    public function test_two_genuinely_concurrent_same_token_same_payload_registrations_converge_on_exactly_one_operation_graph(): void
    {
        (new RolesAndPermissionsSeeder)->run();
        $accountant = User::factory()->create(['is_active' => true]);
        $accountant->assignRole('accountant');
        $year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $stage = Stage::create(['name' => 'Test Stage', 'order' => 1, 'is_active' => true]);
        $grade = Grade::forceCreate(['name' => 'Test Grade', 'stage_id' => $stage->id, 'level' => 1]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'A', 'name_ru' => 'A', 'name_ar' => 'A', 'is_active' => true]);
        // Coordinator-supplied fix (real PostgreSQL run surfaced this):
        // a fixed code='regular' created via plain create() collides on
        // a real, strictly-enforced Postgres unique constraint the
        // moment any residual/repeated state exists — firstOrCreate()
        // is idempotent regardless.
        $mode = EnrollmentMode::firstOrCreate(['code' => 'regular'], ['name_ru' => 'Test Mode', 'is_active' => true]);
        $fee = Fee::create(['name_ru' => 'Test Fee', 'category' => Fee::CATEGORY_TUITION, 'amount' => '1000.00', 'is_active' => true]);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $year->id, 'grade_id' => $grade->id, 'amount' => '1000.00', 'currency' => 'EGP', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);

        // Real-server run note: migrate:rollback's teardown is best-
        // effort (see tearDown()'s own comment) — residual rows from
        // earlier runs against this same live server can persist, so
        // this test asserts DELTAS (before -> after), never a bare
        // absolute table count, which would be wrong the moment more
        // than one run has ever touched this database.
        $studentsBefore = DB::connection($this->connectionName)->table('students')->count();
        $enrollmentsBefore = DB::connection($this->connectionName)->table('enrollments')->count();
        $invoicesBefore = DB::connection($this->connectionName)->table('invoices')->count();

        $token = 'pg-race-token-'.uniqid();
        $data = [
            'student_last_name_ru' => 'Раса', 'student_first_name_ru' => 'Конкурентная',
            'phone' => '+20 100 000 0000', 'registration_date' => '2026-09-01',
            'academic_year_id' => $year->id, 'stage_id' => $stage->id, 'grade_id' => $grade->id,
            'class_id' => $class->id, 'enrollment_mode_id' => $mode->id,
            'services' => [['fee_id' => $fee->id, 'quantity' => 1, 'paid_now' => '0.00']],
            'payment_type' => 'one_time', 'idempotency_token' => $token,
        ];

        $barrier = tempnam(sys_get_temp_dir(), 'pg_reg_race_');
        unlink($barrier);
        $resultFileA = $barrier.'.a';
        $resultFileB = $barrier.'.b';

        $spawn = function (string $resultFile) use ($barrier, $data, $accountant): int {
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
                    $result = app(QuickStudentRegistrationService::class)->register($data, $accountant);
                    file_put_contents($resultFile, 'student:'.$result['student']->id.',invoice:'.$result['invoice']->id);
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
        $this->assertStringNotContainsString('CRASHED', (string) $resultA, "must recover cleanly: {$resultA}");
        $this->assertStringNotContainsString('CRASHED', (string) $resultB, "must recover cleanly: {$resultB}");
        $this->assertStringStartsWith('student:', (string) $resultA);
        $this->assertStringStartsWith('student:', (string) $resultB);
        $this->assertSame($resultA, $resultB, 'both racers must resolve to the exact same Student+Invoice graph, never a duplicate Student');

        $this->assertSame($studentsBefore + 1, DB::connection($this->connectionName)->table('students')->count(), 'exactly one NEW Student created, never two');
        $this->assertSame($enrollmentsBefore + 1, DB::connection($this->connectionName)->table('enrollments')->count());
        $this->assertSame($invoicesBefore + 1, DB::connection($this->connectionName)->table('invoices')->count());

        // The operation row's key is derived deterministically from the
        // token (Uuid::uuid5) inside register() itself — computed the
        // same way here for a token-scoped lookup, never a bare ::sole()
        // that would break the moment more than one operation row exists
        // in this shared live database.
        $operationKey = (string) \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, "quick-registration-operation:{$token}");
        $operation = QuickRegistrationOperation::on($this->connectionName)->where('idempotency_key', $operationKey)->first();
        $this->assertNotNull($operation, 'a completed operation row for this specific token must exist');
        $this->assertSame('completed', $operation->status);
    }
}
