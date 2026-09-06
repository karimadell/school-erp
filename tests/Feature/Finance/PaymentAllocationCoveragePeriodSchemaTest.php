<?php

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression test for a MySQL portability bug in
 * 2026_09_03_100000_create_payment_allocation_coverage_periods_table: this
 * table's own long name pushes EVERY one of Laravel's auto-generated
 * identifiers on it past MySQL's 64-character limit, not only the first
 * one MySQL happened to reach and fail on:
 *
 *   - payment_allocation_id's FK constraint:            65 chars
 *   - installment_coverage_period_id's FK constraint:   74 chars
 *   - the explicit index on installment_coverage_period_id: 72 chars
 *   - the composite unique (payment_allocation_id,
 *     installment_coverage_period_id):                  95 chars
 *
 * SQLite (this suite's own test connection) never enforces that 64-char
 * limit, so this bug was invisible to the existing automated suite and
 * only ever surfaced when actually migrating a real MySQL database. Where
 * SQLite's own schema introspection reports a real, meaningful name for
 * an object — every index, including the composite unique and the
 * explicit standalone index below — this test asserts that actual,
 * driver-reported name's length directly (verified to genuinely fail
 * without the fix, not merely assumed). SQLite does NOT, however, report
 * any name at all for foreign key constraints — Schema::getForeignKeys()
 * always returns an empty 'name' here (confirmed by direct inspection),
 * so a length assertion on that field would silently pass regardless of
 * what the migration actually specifies, giving false confidence rather
 * than real protection. The FK-related runtime test below therefore
 * verifies what SQLite CAN meaningfully confirm — that both foreign keys
 * exist with the correct columns and referenced tables — while
 * test_both_foreign_key_constraint_names_are_short_and_explicit() below
 * closes that gap the only portable way available: by reading the
 * migration's own source and verifying each constrained() call still
 * supplies an explicit, short third argument, independent of runtime
 * schema introspection entirely.
 */
class PaymentAllocationCoveragePeriodSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_foreign_keys_exist_with_correct_columns_and_targets(): void
    {
        $foreignKeys = collect(Schema::getForeignKeys('payment_allocation_coverage_periods'));

        $paymentAllocationFk = $foreignKeys->first(fn (array $fk) => $fk['columns'] === ['payment_allocation_id']
            && $fk['foreign_table'] === 'payment_allocations'
            && $fk['foreign_columns'] === ['id']);
        $this->assertNotNull($paymentAllocationFk, 'expected a foreign key from payment_allocation_id to payment_allocations.id');

        $installmentCoverageFk = $foreignKeys->first(fn (array $fk) => $fk['columns'] === ['installment_coverage_period_id']
            && $fk['foreign_table'] === 'installment_coverage_periods'
            && $fk['foreign_columns'] === ['id']);
        $this->assertNotNull($installmentCoverageFk, 'expected a foreign key from installment_coverage_period_id to installment_coverage_periods.id');

        // SQLite reports no name at all for FK constraints (always ''),
        // so their length cannot be structurally verified here — see the
        // class docblock. Deliberately not asserted on $fk['name'].
    }

    /**
     * Source-level portability guard for the original failure this
     * migration shipped with: an overlong AUTO-GENERATED foreign key
     * constraint name. Schema::getForeignKeys() cannot expose the actual
     * FK constraint name under this suite's SQLite connection (confirmed
     * above and in the class docblock — it is always ''), so no runtime
     * schema assertion can ever detect a regression here regardless of
     * which driver a future CI run might use. Reading the migration's own
     * source is the only portable way to guard this specific class of
     * defect: it verifies an EXPLICIT, non-empty third argument is still
     * supplied to constrained() for each foreign key, and that whatever
     * name is currently chosen (not any one hardcoded value — a future
     * legitimate short rename must still pass) stays within MySQL's
     * 64-character identifier limit. Targeted to the exact two
     * foreignId()->constrained() chains only, never asserting arbitrary
     * full-file text.
     */
    public function test_both_foreign_key_constraint_names_are_short_and_explicit(): void
    {
        $source = file_get_contents(database_path('migrations/2026_09_03_100000_create_payment_allocation_coverage_periods_table.php'));

        $paymentAllocationFkName = $this->explicitConstrainedName($source, 'payment_allocation_id', 'payment_allocations');
        $this->assertNotNull($paymentAllocationFkName,
            "payment_allocation_id's foreignId()->constrained('payment_allocations', 'id', <explicit name>) call must supply an explicit third (name) argument");
        $this->assertLessThanOrEqual(64, strlen($paymentAllocationFkName),
            "FK constraint name '{$paymentAllocationFkName}' exceeds MySQL's 64-character identifier limit");

        $installmentCoverageFkName = $this->explicitConstrainedName($source, 'installment_coverage_period_id', 'installment_coverage_periods');
        $this->assertNotNull($installmentCoverageFkName,
            "installment_coverage_period_id's foreignId()->constrained('installment_coverage_periods', 'id', <explicit name>) call must supply an explicit third (name) argument");
        $this->assertLessThanOrEqual(64, strlen($installmentCoverageFkName),
            "FK constraint name '{$installmentCoverageFkName}' exceeds MySQL's 64-character identifier limit");
    }

    /**
     * Extracts the explicit constraint name from a
     * ->foreignId('$column')->constrained('$table', 'id', '<name>') chain
     * in $source, or null if no explicit third argument is present (e.g.
     * a reverted ->constrained('$table') or ->constrained('$table', 'id')
     * call). Never asserts a specific replacement name itself — only that
     * one is present and short — so any future legitimate rename to a
     * different short name still passes.
     */
    private function explicitConstrainedName(string $source, string $column, string $table): ?string
    {
        $pattern = "/foreignId\\(\\s*'".preg_quote($column, '/')."'\\s*\\)\\s*->constrained\\(\\s*'".preg_quote($table, '/')."'\\s*,\\s*'id'\\s*,\\s*'([^']+)'\\s*\\)/s";

        return preg_match($pattern, $source, $matches) === 1 ? $matches[1] : null;
    }

    public function test_composite_unique_and_supporting_index_use_short_names(): void
    {
        $indexes = collect(Schema::getIndexes('payment_allocation_coverage_periods'));

        $compositeUnique = $indexes->first(fn (array $index) => $index['unique']
            && $index['columns'] === ['payment_allocation_id', 'installment_coverage_period_id']);
        $this->assertNotNull($compositeUnique, 'expected a unique index over (payment_allocation_id, installment_coverage_period_id)');
        $this->assertLessThanOrEqual(64, strlen($compositeUnique['name']),
            "index name '{$compositeUnique['name']}' exceeds MySQL's 64-character identifier limit");

        $autoGeneratedUniqueName = 'payment_allocation_coverage_periods_payment_allocation_id_installment_coverage_period_id_unique';
        $this->assertGreaterThan(64, strlen($autoGeneratedUniqueName),
            'sanity check: this is what the auto-generated composite unique name would have been, and why it is unsafe on MySQL');
        $this->assertNotSame($autoGeneratedUniqueName, $compositeUnique['name']);

        // installment_coverage_period_id also has an explicit standalone
        // ->index() call in this migration (unlike payment_allocation_id,
        // which only has its FK's implied coverage as the composite
        // unique's leading column) — a dedicated single-column index for
        // it must exist, and its name must also stay short.
        $standaloneIndex = $indexes->first(fn (array $index) => ! $index['unique']
            && $index['columns'] === ['installment_coverage_period_id']);
        $this->assertNotNull($standaloneIndex, 'expected a standalone index on installment_coverage_period_id');
        $this->assertLessThanOrEqual(64, strlen($standaloneIndex['name']),
            "index name '{$standaloneIndex['name']}' exceeds MySQL's 64-character identifier limit");

        $autoGeneratedIndexName = 'payment_allocation_coverage_periods_installment_coverage_period_id_index';
        $this->assertGreaterThan(64, strlen($autoGeneratedIndexName),
            'sanity check: this is what the auto-generated standalone index name would have been, and why it is unsafe on MySQL');
        $this->assertNotSame($autoGeneratedIndexName, $standaloneIndex['name']);
    }

    public function test_amount_and_created_at_columns_remain_unchanged(): void
    {
        $columns = collect(Schema::getColumns('payment_allocation_coverage_periods'))->keyBy('name');

        $this->assertFalse($columns['amount']['nullable']);
        $this->assertFalse($columns['created_at']['nullable']);
        $this->assertFalse($columns['payment_allocation_id']['nullable']);
        $this->assertFalse($columns['installment_coverage_period_id']['nullable']);
    }
}
