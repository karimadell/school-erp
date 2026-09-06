<?php

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression test for a MySQL portability bug in
 * 2026_09_04_100000_create_student_credit_application_items_table: this
 * table's own long name pushes TWO of Laravel's auto-generated identifiers
 * on it past MySQL's 64-character limit — not only the one originally
 * reported:
 *
 *   - student_credit_application_id's FK constraint: 70 chars (originally reported)
 *   - the explicit index on student_credit_application_id: 68 chars (never
 *     previously reported — the migration failed on the FK statement above
 *     it before this index statement ever ran)
 *
 * invoice_item_id's FK constraint (56 chars) and its standalone index
 * (54 chars) are already safe and were left with their auto-generated
 * names.
 *
 * SQLite (this suite's own test connection) never enforces that 64-char
 * limit, so this bug was invisible to the existing automated suite and
 * only ever surfaced when actually migrating a real MySQL database. Where
 * SQLite's own schema introspection reports a real, meaningful name for an
 * object — every index, including both standalone indexes below — this
 * test asserts that actual, driver-reported name's length directly
 * (verified to genuinely fail without the fix, not merely assumed). SQLite
 * does NOT, however, report any name at all for foreign key constraints —
 * Schema::getForeignKeys() always returns an empty 'name' here (confirmed
 * by direct inspection in the prior migrations of this same corrective
 * series), so a length assertion on that field would silently pass
 * regardless of what the migration actually specifies, giving false
 * confidence rather than real protection. The FK-related runtime test
 * below therefore verifies what SQLite CAN meaningfully confirm — that
 * both foreign keys exist with the correct columns and referenced tables —
 * while test_student_credit_application_fk_constraint_name_is_short_and_explicit()
 * below closes that gap the only portable way available: by reading the
 * migration's own source and verifying the constrained() call still
 * supplies an explicit, short third argument, independent of runtime
 * schema introspection entirely.
 */
class StudentCreditApplicationItemSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_foreign_keys_exist_with_correct_columns_and_targets(): void
    {
        $foreignKeys = collect(Schema::getForeignKeys('student_credit_application_items'));

        $creditApplicationFk = $foreignKeys->first(fn (array $fk) => $fk['columns'] === ['student_credit_application_id']
            && $fk['foreign_table'] === 'student_credit_applications'
            && $fk['foreign_columns'] === ['id']);
        $this->assertNotNull($creditApplicationFk, 'expected a foreign key from student_credit_application_id to student_credit_applications.id');

        $invoiceItemFk = $foreignKeys->first(fn (array $fk) => $fk['columns'] === ['invoice_item_id']
            && $fk['foreign_table'] === 'invoice_items'
            && $fk['foreign_columns'] === ['id']);
        $this->assertNotNull($invoiceItemFk, 'expected a foreign key from invoice_item_id to invoice_items.id');

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
     * supplied to constrained() for this foreign key, and that whatever
     * name is currently chosen (not any one hardcoded value — a future
     * legitimate short rename must still pass) stays within MySQL's
     * 64-character identifier limit. Targeted to the exact
     * foreignId()->constrained() chain only, never asserting arbitrary
     * full-file text.
     */
    public function test_student_credit_application_fk_constraint_name_is_short_and_explicit(): void
    {
        $source = file_get_contents(database_path('migrations/2026_09_04_100000_create_student_credit_application_items_table.php'));

        $creditApplicationFkName = $this->explicitConstrainedName($source, 'student_credit_application_id', 'student_credit_applications');
        $this->assertNotNull($creditApplicationFkName,
            "student_credit_application_id's foreignId()->constrained('student_credit_applications', 'id', <explicit name>) call must supply an explicit third (name) argument");
        $this->assertLessThanOrEqual(64, strlen($creditApplicationFkName),
            "FK constraint name '{$creditApplicationFkName}' exceeds MySQL's 64-character identifier limit");
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

    public function test_both_standalone_indexes_use_short_names(): void
    {
        $indexes = collect(Schema::getIndexes('student_credit_application_items'));

        $creditApplicationIndex = $indexes->first(fn (array $index) => ! $index['unique']
            && $index['columns'] === ['student_credit_application_id']);
        $this->assertNotNull($creditApplicationIndex, 'expected a standalone index on student_credit_application_id');
        $this->assertLessThanOrEqual(64, strlen($creditApplicationIndex['name']),
            "index name '{$creditApplicationIndex['name']}' exceeds MySQL's 64-character identifier limit");

        $autoGeneratedCreditApplicationIndexName = 'student_credit_application_items_student_credit_application_id_index';
        $this->assertGreaterThan(64, strlen($autoGeneratedCreditApplicationIndexName),
            'sanity check: this is what the auto-generated index name would have been for student_credit_application_id, and why it is unsafe on MySQL');
        $this->assertNotSame($autoGeneratedCreditApplicationIndexName, $creditApplicationIndex['name']);

        $invoiceItemIndex = $indexes->first(fn (array $index) => ! $index['unique']
            && $index['columns'] === ['invoice_item_id']);
        $this->assertNotNull($invoiceItemIndex, 'expected a standalone index on invoice_item_id');
        $this->assertLessThanOrEqual(64, strlen($invoiceItemIndex['name']),
            "index name '{$invoiceItemIndex['name']}' exceeds MySQL's 64-character identifier limit");
    }

    public function test_original_auto_generated_identifiers_would_have_exceeded_the_limit(): void
    {
        // Documents exactly what broke, and what would have broken next —
        // Laravel's own default naming convention for this table really
        // does exceed the limit for both the FK and its supporting index,
        // so an explicit short name is not optional cleanup for either one.
        $autoGeneratedFk = 'student_credit_application_items_student_credit_application_id_foreign';
        $this->assertGreaterThan(64, strlen($autoGeneratedFk),
            'sanity check: this is what the auto-generated FK name would have been for student_credit_application_id, and why it is unsafe on MySQL');

        $autoGeneratedIndex = 'student_credit_application_items_student_credit_application_id_index';
        $this->assertGreaterThan(64, strlen($autoGeneratedIndex),
            'sanity check: this is what the auto-generated index name would have been for student_credit_application_id, and why it is unsafe on MySQL');

        // invoice_item_id's auto-generated identifiers are already safe and
        // were deliberately left unchanged by this migration's fix.
        $autoGeneratedInvoiceItemFk = 'student_credit_application_items_invoice_item_id_foreign';
        $this->assertLessThanOrEqual(64, strlen($autoGeneratedInvoiceItemFk),
            'sanity check: invoice_item_id\'s auto-generated FK name is already safe on MySQL and needed no fix');

        $autoGeneratedInvoiceItemIndex = 'student_credit_application_items_invoice_item_id_index';
        $this->assertLessThanOrEqual(64, strlen($autoGeneratedInvoiceItemIndex),
            'sanity check: invoice_item_id\'s auto-generated index name is already safe on MySQL and needed no fix');
    }

    public function test_amount_and_created_at_columns_remain_unchanged(): void
    {
        $columns = collect(Schema::getColumns('student_credit_application_items'))->keyBy('name');

        $this->assertFalse($columns['amount']['nullable']);
        $this->assertFalse($columns['created_at']['nullable']);
        $this->assertFalse($columns['student_credit_application_id']['nullable']);
        $this->assertFalse($columns['invoice_item_id']['nullable']);
    }
}
