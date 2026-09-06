<?php

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression test for a MySQL portability bug in
 * 2026_09_04_110000_create_credit_application_coverage_periods_table: this
 * table's own long name pushes THREE of Laravel's auto-generated
 * identifiers on it past MySQL's 64-character limit — not only the one
 * originally reported:
 *
 *   - student_credit_application_item_id's FK constraint: 78 chars (originally reported)
 *   - installment_coverage_period_id's FK constraint:     74 chars
 *   - the explicit index on installment_coverage_period_id: 72 chars
 *
 * The composite unique (student_credit_application_item_id,
 * installment_coverage_period_id) was already given an explicit short
 * name ('credit_app_cov_period_unique', 28 chars) by this migration's
 * original author and never needed a fix.
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
 * always returns an empty 'name' here (confirmed by direct inspection in
 * the prior migrations of this same corrective series), so a length
 * assertion on that field would silently pass regardless of what the
 * migration actually specifies, giving false confidence rather than real
 * protection. The FK-related runtime test below therefore verifies what
 * SQLite CAN meaningfully confirm — that both foreign keys exist with the
 * correct columns and referenced tables — while
 * test_both_foreign_key_constraint_names_are_short_and_explicit() below
 * closes that gap the only portable way available: by reading the
 * migration's own source and verifying each constrained() call still
 * supplies an explicit, short third argument, independent of runtime
 * schema introspection entirely.
 */
class CreditApplicationCoveragePeriodSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_foreign_keys_exist_with_correct_columns_and_targets(): void
    {
        $foreignKeys = collect(Schema::getForeignKeys('credit_application_coverage_periods'));

        $creditApplicationItemFk = $foreignKeys->first(fn (array $fk) => $fk['columns'] === ['student_credit_application_item_id']
            && $fk['foreign_table'] === 'student_credit_application_items'
            && $fk['foreign_columns'] === ['id']);
        $this->assertNotNull($creditApplicationItemFk, 'expected a foreign key from student_credit_application_item_id to student_credit_application_items.id');

        $installmentCoverageFk = $foreignKeys->first(fn (array $fk) => $fk['columns'] === ['installment_coverage_period_id']
            && $fk['foreign_table'] === 'installment_coverage_periods'
            && $fk['foreign_columns'] === ['id']);
        $this->assertNotNull($installmentCoverageFk, 'expected a foreign key from installment_coverage_period_id to installment_coverage_periods.id');

        // SQLite reports no name at all for FK constraints (always ''),
        // so their length cannot be structurally verified here — see the
        // class docblock. Deliberately not asserted on $fk['name'].
    }

    /**
     * Source-level portability guard for two of the three original
     * failures this migration shipped with: overlong AUTO-GENERATED
     * foreign key constraint names. Schema::getForeignKeys() cannot
     * expose the actual FK constraint name under this suite's SQLite
     * connection (confirmed above and in the class docblock — it is
     * always ''), so no runtime schema assertion can ever detect a
     * regression here regardless of which driver a future CI run might
     * use. Reading the migration's own source is the only portable way to
     * guard this specific class of defect: it verifies an EXPLICIT,
     * non-empty third argument is still supplied to constrained() for
     * each foreign key, and that whatever name is currently chosen (not
     * any one hardcoded value — a future legitimate short rename must
     * still pass) stays within MySQL's 64-character identifier limit.
     * Targeted to the exact two foreignId()->constrained() chains only
     * (each keyed to its own specific column AND table name), never
     * asserting arbitrary full-file text and never able to cross-match
     * the other FK's chain.
     */
    public function test_both_foreign_key_constraint_names_are_short_and_explicit(): void
    {
        $source = file_get_contents(database_path('migrations/2026_09_04_110000_create_credit_application_coverage_periods_table.php'));

        $creditApplicationItemFkName = $this->explicitConstrainedName($source, 'student_credit_application_item_id', 'student_credit_application_items');
        $this->assertNotNull($creditApplicationItemFkName,
            "student_credit_application_item_id's foreignId()->constrained('student_credit_application_items', 'id', <explicit name>) call must supply an explicit third (name) argument");
        $this->assertLessThanOrEqual(64, strlen($creditApplicationItemFkName),
            "FK constraint name '{$creditApplicationItemFkName}' exceeds MySQL's 64-character identifier limit");

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
        $indexes = collect(Schema::getIndexes('credit_application_coverage_periods'));

        $compositeUnique = $indexes->first(fn (array $index) => $index['unique']
            && $index['columns'] === ['student_credit_application_item_id', 'installment_coverage_period_id']);
        $this->assertNotNull($compositeUnique, 'expected a unique index over (student_credit_application_item_id, installment_coverage_period_id)');
        $this->assertLessThanOrEqual(64, strlen($compositeUnique['name']),
            "index name '{$compositeUnique['name']}' exceeds MySQL's 64-character identifier limit");

        // installment_coverage_period_id also has an explicit standalone
        // ->index() call in this migration (unlike student_credit_application_item_id,
        // which only has its FK's implied coverage as the composite
        // unique's leading column) — a dedicated single-column index for
        // it must exist, and its name must also stay short.
        $standaloneIndex = $indexes->first(fn (array $index) => ! $index['unique']
            && $index['columns'] === ['installment_coverage_period_id']);
        $this->assertNotNull($standaloneIndex, 'expected a standalone index on installment_coverage_period_id');
        $this->assertLessThanOrEqual(64, strlen($standaloneIndex['name']),
            "index name '{$standaloneIndex['name']}' exceeds MySQL's 64-character identifier limit");

        $autoGeneratedIndexName = 'credit_application_coverage_periods_installment_coverage_period_id_index';
        $this->assertGreaterThan(64, strlen($autoGeneratedIndexName),
            'sanity check: this is what the auto-generated standalone index name would have been, and why it is unsafe on MySQL');
        $this->assertNotSame($autoGeneratedIndexName, $standaloneIndex['name']);
    }

    public function test_original_auto_generated_fk_names_would_have_exceeded_the_limit(): void
    {
        // Documents exactly what broke for both FKs — Laravel's own
        // default naming convention for this table really does exceed
        // the limit in both cases, so explicit short names are not
        // optional cleanup for either one.
        $autoGeneratedCreditApplicationItemFk = 'credit_application_coverage_periods_student_credit_application_item_id_foreign';
        $this->assertGreaterThan(64, strlen($autoGeneratedCreditApplicationItemFk),
            'sanity check: this is what the auto-generated FK name would have been for student_credit_application_item_id, and why it is unsafe on MySQL');

        $autoGeneratedInstallmentCoverageFk = 'credit_application_coverage_periods_installment_coverage_period_id_foreign';
        $this->assertGreaterThan(64, strlen($autoGeneratedInstallmentCoverageFk),
            'sanity check: this is what the auto-generated FK name would have been for installment_coverage_period_id, and why it is unsafe on MySQL');
    }

    public function test_amount_and_created_at_columns_remain_unchanged(): void
    {
        $columns = collect(Schema::getColumns('credit_application_coverage_periods'))->keyBy('name');

        $this->assertFalse($columns['amount']['nullable']);
        $this->assertFalse($columns['created_at']['nullable']);
        $this->assertFalse($columns['student_credit_application_item_id']['nullable']);
        $this->assertFalse($columns['installment_coverage_period_id']['nullable']);
    }
}
