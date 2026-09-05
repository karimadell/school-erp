<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\CashSessionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * UAT corrective pass — Quick Registration Uniform selection.
 *
 * Root cause this feature fixes: Quick Registration's "services" array is
 * one entry per Fee (services.*.fee_id is `distinct`), so an employee could
 * previously select only ONE Uniform item+size per registration — the
 * compact functional requirement is to select SEVERAL distinct items (each
 * its own exact size), each becoming its OWN independent invoice line
 * (never collapsed into one opaque total), so finance:uniform-procurement-
 * report can still aggregate by item + exact size + quantity.
 *
 * Request shape: services.*.uniform_items = [{uniform_product_id,
 * quantity}, ...] — a nested array on the SAME single Uniform services[]
 * entry (fee_id stays distinct at the request level; only Uniform ever
 * produces more than one invoice line per submitted entry). Size/item are
 * never trusted from the client as free text — only uniform_product_id is
 * read, and item/size/price are always resolved from that product's own
 * row and the canonical active FeePrice it maps to.
 */
class QuickRegistrationMultiUniformTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;
    private AcademicYear $year;
    private array $base;
    private Fee $uniform;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder())->run();
        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->assignRole('accountant');

        $this->year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $stage = Stage::create(['name' => 'Начальная школа', 'order' => 1, 'is_active' => true]);
        $grade = Grade::forceCreate(['name' => '1 класс', 'stage_id' => $stage->id, 'level' => 1]);
        $class = SchoolClass::create(['grade_id' => $grade->id, 'code' => 'А', 'name_ru' => 'А', 'name_ar' => 'A', 'is_active' => true]);
        $mode = EnrollmentMode::create(['code' => 'regular', 'name_ru' => 'Очная форма', 'is_active' => true]);

        $this->base = [
            'student_last_name_ru' => 'Иванова', 'student_first_name_ru' => 'Анна',
            'phone' => '+20 100 555 7788', 'registration_date' => '2026-08-15',
            'academic_year_id' => $this->year->id, 'stage_id' => $stage->id, 'grade_id' => $grade->id,
            'class_id' => $class->id, 'enrollment_mode_id' => $mode->id,
        ];

        $this->uniform = Fee::create(['name_ru' => 'Школьная форма', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '0.00', 'is_active' => true]);
    }

    /** Seeds an active canonical FeePrice + matching active uniform_products row — the only combination Quick Registration should ever expose or accept. */
    private function sellableUniformItem(string $item, string $size, string $amount): int
    {
        FeePrice::create([
            'fee_id' => $this->uniform->id, 'academic_year_id' => $this->year->id, 'amount' => $amount, 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
            'item' => $item, 'size' => $size,
        ]);

        return DB::table('uniform_products')->insertGetId([
            'name_ru' => $item, 'category' => 'garment', 'size' => $size, 'price' => $amount,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function payload(array $services, array $overrides = []): array
    {
        return array_replace($this->base + ['services' => $services], $overrides);
    }

    /** Opens a cash drawer session and returns the operating CashAccount — needed only by tests that submit paid_now > 0. */
    private function openCashSession(): CashAccount
    {
        $account = CashAccount::operating();
        app(CashSessionService::class)->open($account, $this->accountant);

        return $account;
    }

    private function uniformService(array $items, string $paidNow = '0.00'): array
    {
        return ['fee_id' => $this->uniform->id, 'paid_now' => $paidNow, 'uniform_items' => $items];
    }

    /** Minimal ASCII-table parser matching finance:uniform-procurement-report's own `|`-delimited rows. */
    private function tableQuantities(string $output): array
    {
        $rows = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_starts_with($line, '|')) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, '|')));
            if (count($cells) < 3 || $cells[0] === 'item') {
                continue;
            }
            $rows[$cells[0].'|'.$cells[1]] = $cells[2];
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // A. Select one Uniform item + exact size.
    // ------------------------------------------------------------------
    public function test_single_uniform_item_selection_creates_one_correct_invoice_line(): void
    {
        $productId = $this->sellableUniformItem('Майка', '14', '500.00');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->uniformService([['uniform_product_id' => $productId, 'quantity' => 1]]),
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('invoice_items', 1);
        $item = InvoiceItem::sole();
        $this->assertSame(1, $item->quantity);
        $this->assertSame('500.00', $item->unit_price);
        $this->assertSame('500.00', $item->amount);
        $this->assertSame('Майка', $item->metadata['item']);
        $this->assertSame('14', $item->metadata['size']);
        $this->assertSame($productId, $item->metadata['uniform_product_id']);
    }

    // ------------------------------------------------------------------
    // B & J. Three different items, three different sizes — three
    // independent invoice lines, each with its own exact metadata.
    // ------------------------------------------------------------------
    public function test_three_uniform_items_create_three_independent_invoice_lines(): void
    {
        $majka = $this->sellableUniformItem('Майка', '14', '500.00');
        $polo = $this->sellableUniformItem('Поло', '16', '700.00');
        $tolstovka = $this->sellableUniformItem('Толстовка', 'S', '1500.00');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->uniformService([
                ['uniform_product_id' => $majka, 'quantity' => 1],
                ['uniform_product_id' => $polo, 'quantity' => 1],
                ['uniform_product_id' => $tolstovka, 'quantity' => 1],
            ]),
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('invoice_items', 3);
        $items = InvoiceItem::where('fee_id', $this->uniform->id)->get()->keyBy(fn (InvoiceItem $i) => $i->metadata['item']);

        $this->assertSame('14', $items['Майка']->metadata['size']);
        $this->assertSame('500.00', $items['Майка']->amount);
        $this->assertSame($majka, $items['Майка']->metadata['uniform_product_id']);

        $this->assertSame('16', $items['Поло']->metadata['size']);
        $this->assertSame('700.00', $items['Поло']->amount);
        $this->assertSame($polo, $items['Поло']->metadata['uniform_product_id']);

        $this->assertSame('S', $items['Толстовка']->metadata['size']);
        $this->assertSame('1500.00', $items['Толстовка']->amount);
        $this->assertSame($tolstovka, $items['Толстовка']->metadata['uniform_product_id']);
    }

    // ------------------------------------------------------------------
    // C. Uniform grand total equals the sum of line totals.
    // ------------------------------------------------------------------
    public function test_uniform_grand_total_equals_sum_of_line_totals(): void
    {
        $majka = $this->sellableUniformItem('Майка', '14', '500.00');
        $polo = $this->sellableUniformItem('Поло', '16', '700.00');
        $tolstovka = $this->sellableUniformItem('Толстовка', 'S', '1500.00');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->uniformService([
                ['uniform_product_id' => $majka, 'quantity' => 1],
                ['uniform_product_id' => $polo, 'quantity' => 1],
                ['uniform_product_id' => $tolstovka, 'quantity' => 1],
            ]),
        ]))->assertSessionHasNoErrors();

        // 500 + 700 + 1500 = 2700 — the exact worked example this feature was specified against.
        $this->assertSame('2700.00', Invoice::sole()->total_amount);
        // invoice_fee is a UNIQUE(invoice_id, fee_id) compatibility summary
        // (one row per Fee, never per line) — exactly one Uniform Fee is on
        // this invoice, and its summed pivot amount must still equal the
        // same grand total (real per-line detail lives on invoice_items,
        // asserted separately above/elsewhere).
        $invoice = Invoice::with('fees')->sole();
        $this->assertCount(1, $invoice->fees);
        $this->assertSame(0, bccomp((string) $invoice->fees->first()->pivot->amount, '2700.00', 2));
    }

    // ------------------------------------------------------------------
    // P1 regression (independent review) — paid_now for the single
    // Uniform services[] entry covers the ENTIRE selection (there is no
    // per-item payment field in the UI), so the server must deterministically
    // distribute it across the sibling InvoiceItem lines it expands into.
    // Documents the CURRENT, intended allocation: greedy, in submission
    // order, each line capped at its own resolved amount — never a line
    // receiving more than its own amount, never silently dropping or
    // duplicating payment across siblings.
    //
    // Full payment: paid_now equals the exact grand total (2700 = 500 +
    // 700 + 1500) — every line must be fully settled.
    // ------------------------------------------------------------------
    public function test_full_payment_settles_all_three_uniform_lines(): void
    {
        $account = $this->openCashSession();
        $majka = $this->sellableUniformItem('Майка', '14', '500.00');
        $polo = $this->sellableUniformItem('Поло', '16', '700.00');
        $tolstovka = $this->sellableUniformItem('Толстовка', 'S', '1500.00');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->uniformService([
                ['uniform_product_id' => $majka, 'quantity' => 1],
                ['uniform_product_id' => $polo, 'quantity' => 1],
                ['uniform_product_id' => $tolstovka, 'quantity' => 1],
            ], '2700.00'),
        ], ['payment_method' => 'cash', 'cash_account_id' => $account->id]))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('invoice_items', 3);
        $items = InvoiceItem::where('fee_id', $this->uniform->id)->get()->keyBy(fn (InvoiceItem $i) => $i->metadata['item']);

        // Every line fully settled — none paid more than its own canonical amount.
        foreach (['Майка' => '500.00', 'Поло' => '700.00', 'Толстовка' => '1500.00'] as $name => $amount) {
            $this->assertSame($amount, $items[$name]->amount, "{$name} amount drifted from canonical price");
            $this->assertSame($amount, $items[$name]->paid_amount, "{$name} must be fully paid");
            $this->assertSame('0.00', $items[$name]->remaining_amount, "{$name} must have zero remaining");
            $this->assertSame(0, bccomp($items[$name]->paid_amount, $items[$name]->amount, 2), "{$name} paid must never exceed its own canonical amount");
        }

        $invoice = Invoice::sole();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame('0.00', $invoice->remaining_amount);
        // One atomic payment record for the whole registration, auditable
        // against the invoice it settled — not one payment per line.
        $payment = InvoicePayment::sole();
        $this->assertSame('2700.00', $payment->amount);
        $this->assertSame($invoice->id, $payment->invoice_id);
    }

    // ------------------------------------------------------------------
    // Partial payment: paid_now (1200.00) exactly covers Майка(500) +
    // Поло(700) in submission order, leaving nothing for Толстовка(1500)
    // — documents the deterministic greedy/submission-order allocation,
    // never an even split and never over-allocating a later line.
    // ------------------------------------------------------------------
    public function test_partial_payment_covers_first_two_lines_in_submission_order(): void
    {
        $account = $this->openCashSession();
        $majka = $this->sellableUniformItem('Майка', '14', '500.00');
        $polo = $this->sellableUniformItem('Поло', '16', '700.00');
        $tolstovka = $this->sellableUniformItem('Толстовка', 'S', '1500.00');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->uniformService([
                ['uniform_product_id' => $majka, 'quantity' => 1],
                ['uniform_product_id' => $polo, 'quantity' => 1],
                ['uniform_product_id' => $tolstovka, 'quantity' => 1],
            ], '1200.00'),
        ], ['payment_method' => 'cash', 'cash_account_id' => $account->id]))->assertSessionHasNoErrors();

        $items = InvoiceItem::where('fee_id', $this->uniform->id)->get()->keyBy(fn (InvoiceItem $i) => $i->metadata['item']);
        $this->assertSame('500.00', $items['Майка']->paid_amount, 'first submitted line must be fully paid first');
        $this->assertSame('0.00', $items['Майка']->remaining_amount);
        $this->assertSame('700.00', $items['Поло']->paid_amount, 'second submitted line must be fully paid next');
        $this->assertSame('0.00', $items['Поло']->remaining_amount);
        $this->assertSame('0.00', $items['Толстовка']->paid_amount, 'third submitted line must receive nothing once the payment is exhausted');
        $this->assertSame('1500.00', $items['Толстовка']->remaining_amount);

        // No line ever allocated more than its own canonical amount.
        foreach ($items as $item) {
            $this->assertLessThanOrEqual(0, bccomp($item->paid_amount, $item->amount, 2), "{$item->metadata['item']} paid must never exceed its own amount");
        }

        $invoice = Invoice::sole();
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->status);
        $this->assertSame('1500.00', $invoice->remaining_amount);
        // The allocation is auditable directly from persisted records: one
        // payment for the exact submitted amount, and the per-line
        // paid/remaining split above accounts for all of it with nothing
        // left unaccounted for.
        $payment = InvoicePayment::sole();
        $this->assertSame('1200.00', $payment->amount);
        $this->assertSame(
            0,
            bccomp($payment->amount, (string) $items->sum('paid_amount'), 2),
            'the recorded payment must exactly equal the sum of what was actually allocated per line'
        );
    }

    // ------------------------------------------------------------------
    // D. Quantity > 1 — unit price stays canonical, line total scales,
    // and the procurement report sees the real quantity.
    // ------------------------------------------------------------------
    public function test_quantity_greater_than_one_scales_line_total_and_procurement_quantity(): void
    {
        $productId = $this->sellableUniformItem('Майка', '14', '500.00');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->uniformService([['uniform_product_id' => $productId, 'quantity' => 2]]),
        ]))->assertSessionHasNoErrors();

        $item = InvoiceItem::sole();
        $this->assertSame(2, $item->quantity);
        $this->assertSame('500.00', $item->unit_price);
        $this->assertSame('1000.00', $item->amount);

        $exitCode = Artisan::call('finance:uniform-procurement-report', ['--year' => $this->year->name]);
        $output = Artisan::output();
        $this->assertSame(0, $exitCode);
        $this->assertSame('2', $this->tableQuantities($output)['Майка|14'] ?? null, "expected quantity 2 in report output:\n{$output}");
    }

    // ------------------------------------------------------------------
    // E. Unselected Uniform item creates no invoice line — disabled
    // inputs never submit, so an item simply never appears here.
    // ------------------------------------------------------------------
    public function test_unselected_uniform_item_creates_no_invoice_line(): void
    {
        $majka = $this->sellableUniformItem('Майка', '14', '500.00');
        $this->sellableUniformItem('Поло', '16', '700.00'); // seeded/sellable, but never included below.

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->uniformService([['uniform_product_id' => $majka, 'quantity' => 1]]),
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('invoice_items', 1);
        $this->assertSame('Майка', InvoiceItem::sole()->metadata['item']);
    }

    // ------------------------------------------------------------------
    // F. A uniform_product_id that does not exist is rejected server-side.
    // ------------------------------------------------------------------
    public function test_invalid_uniform_product_id_is_rejected_server_side(): void
    {
        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->uniformService([['uniform_product_id' => 999999, 'quantity' => 1]]),
        ]))->assertSessionHasErrors('services.0.uniform_items.0.uniform_product_id');

        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('invoice_items', 0);
    }

    // ------------------------------------------------------------------
    // P1 regression (independent review) — the same uniform_product_id
    // submitted twice inside one uniform_items array must be rejected at
    // the request boundary (services.*.uniform_items.*.uniform_product_id
    // carries a `distinct` rule), never silently deduplicated, doubled, or
    // allowed through to create ambiguous invoice lines. No Student,
    // Invoice, InvoiceItem, or payment side effect may exist afterward —
    // the whole registration must fail before the transaction opens.
    // ------------------------------------------------------------------
    public function test_duplicate_uniform_product_id_submission_is_rejected(): void
    {
        $productId = $this->sellableUniformItem('Майка', '14', '500.00');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->uniformService([
                ['uniform_product_id' => $productId, 'quantity' => 1],
                ['uniform_product_id' => $productId, 'quantity' => 2],
            ]),
        ]))
            ->assertSessionHasErrors([
                'services.0.uniform_items.0.uniform_product_id',
                'services.0.uniform_items.1.uniform_product_id',
            ]);

        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('invoice_payments', 0);
    }

    // ------------------------------------------------------------------
    // G. Browser-submitted manipulated price is ignored; canonical
    // FeePrice wins — the request payload never even has a price field
    // read from for a Uniform line, only uniform_product_id.
    // ------------------------------------------------------------------
    public function test_browser_submitted_price_is_ignored_canonical_price_wins(): void
    {
        $productId = $this->sellableUniformItem('Майка', '14', '500.00');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->uniformService([[
                'uniform_product_id' => $productId, 'quantity' => 1,
                // Tampered fields an attacker might inject — never read anywhere server-side.
                'unit_price' => '1.00', 'price' => '1.00', 'amount' => '1.00', 'line_total' => '1.00',
            ]]),
        ]))->assertSessionHasNoErrors();

        $item = InvoiceItem::sole();
        $this->assertSame('500.00', $item->unit_price, 'canonical FeePrice amount must win over any client-submitted price field');
        $this->assertSame('500.00', $item->amount);
    }

    // ------------------------------------------------------------------
    // H. Inactive FeePrice combination cannot be purchased, even though
    // its uniform_products row is active.
    // ------------------------------------------------------------------
    public function test_inactive_fee_price_combination_cannot_be_purchased(): void
    {
        FeePrice::create([
            'fee_id' => $this->uniform->id, 'academic_year_id' => $this->year->id, 'amount' => '900.00', 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => false,
            'item' => 'Толстовка', 'size' => 'XL',
        ]);
        $productId = DB::table('uniform_products')->insertGetId([
            'name_ru' => 'Толстовка', 'category' => 'garment', 'size' => 'XL', 'price' => '900.00',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->uniformService([['uniform_product_id' => $productId, 'quantity' => 1]]),
        ]))->assertSessionHasErrors();

        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('invoice_items', 0);
    }

    // ------------------------------------------------------------------
    // I. A legacy grouped size (6–10 / 12–16 / от S) cannot be purchased
    // through Quick Registration — even a stray active uniform_products
    // row for it cannot resolve a canonical price once the legacy
    // FeePrice itself is inactive (SchoolPriceListImportService's own
    // completeness gate — see PR #17).
    // ------------------------------------------------------------------
    public function test_legacy_grouped_size_cannot_be_purchased_through_quick_registration(): void
    {
        FeePrice::create([
            'fee_id' => $this->uniform->id, 'academic_year_id' => $this->year->id, 'amount' => '2000.00', 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => false,
            'item' => 'Комплект', 'size' => '6–10',
        ]);
        // A stale uniform_products row for the legacy tier, left active —
        // UatMasterDataRepair never deletes/deactivates existing rows.
        $legacyProductId = DB::table('uniform_products')->insertGetId([
            'name_ru' => 'Комплект', 'category' => 'garment', 'size' => '6–10', 'price' => '2000.00',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->uniformService([['uniform_product_id' => $legacyProductId, 'quantity' => 1]]),
        ]))->assertSessionHasErrors();

        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('invoice_items', 0);
    }

    // ------------------------------------------------------------------
    // K. Procurement report sees each selected item+size correctly and
    // quantities aggregate correctly across the whole registration.
    // ------------------------------------------------------------------
    public function test_procurement_report_aggregates_multiple_uniform_selections_correctly(): void
    {
        $majka = $this->sellableUniformItem('Майка', '14', '500.00');
        $polo = $this->sellableUniformItem('Поло', '16', '700.00');
        $tolstovka = $this->sellableUniformItem('Толстовка', 'S', '1500.00');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->uniformService([
                ['uniform_product_id' => $majka, 'quantity' => 1],
                ['uniform_product_id' => $polo, 'quantity' => 1],
                ['uniform_product_id' => $tolstovka, 'quantity' => 1],
            ]),
        ]))->assertSessionHasNoErrors();

        $exitCode = Artisan::call('finance:uniform-procurement-report', ['--year' => $this->year->name]);
        $output = Artisan::output();
        $rows = $this->tableQuantities($output);

        $this->assertSame(0, $exitCode);
        $this->assertSame('1', $rows['Майка|14'] ?? null, "Майка|14 row missing/wrong in report output:\n{$output}");
        $this->assertSame('1', $rows['Поло|16'] ?? null, "Поло|16 row missing/wrong in report output:\n{$output}");
        $this->assertSame('1', $rows['Толстовка|S'] ?? null, "Толстовка|S row missing/wrong in report output:\n{$output}");
    }

    // ------------------------------------------------------------------
    // L. Registration remains atomic if one of several Uniform selections
    // is invalid — no partial Student/Invoice/InvoiceItem creation.
    // ------------------------------------------------------------------
    public function test_registration_remains_atomic_when_one_uniform_selection_is_invalid(): void
    {
        $majka = $this->sellableUniformItem('Майка', '14', '500.00');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->uniformService([
                ['uniform_product_id' => $majka, 'quantity' => 1],
                ['uniform_product_id' => 999999, 'quantity' => 1], // invalid — does not exist.
            ]),
        ]))->assertSessionHasErrors();

        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
    }

    // ------------------------------------------------------------------
    // M. Existing non-Uniform Quick Registration flows remain unchanged —
    // a Registration-fee-only submission still works exactly as before,
    // completely unaffected by the Uniform multi-item machinery.
    // ------------------------------------------------------------------
    public function test_non_uniform_registration_flow_is_unaffected(): void
    {
        $registrationFee = Fee::create(['name_ru' => 'Регистрационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => '1000.00', 'is_active' => true]);

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            ['fee_id' => $registrationFee->id, 'quantity' => 1, 'paid_now' => '0.00'],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(Student::STATUS_PRE_REGISTERED, Student::sole()->status);
        $this->assertSame('1000.00', Invoice::sole()->total_amount);
        $this->assertDatabaseCount('invoice_items', 1);
    }

    // ------------------------------------------------------------------
    // O. Комплект remains a single sellable item — never decomposed into
    // component garments, exactly like any other Uniform item.
    // ------------------------------------------------------------------
    public function test_komplekt_remains_single_item_and_is_not_decomposed(): void
    {
        $komplektId = $this->sellableUniformItem('Комплект', 'M', '2500.00');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->payload([
            $this->uniformService([['uniform_product_id' => $komplektId, 'quantity' => 1]]),
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('invoice_items', 1);
        $item = InvoiceItem::sole();
        $this->assertSame('Комплект', $item->metadata['item']);
        $this->assertSame('M', $item->metadata['size']);
        $this->assertSame('2500.00', $item->amount);
    }

    // ------------------------------------------------------------------
    // UI render test — exactly one compact row per Uniform item, a
    // compact size dropdown, a quantity control, unit price, line total,
    // and a Uniform subtotal. Never 40 rows, never all sizes at once.
    // ------------------------------------------------------------------
    public function test_quick_registration_page_renders_one_compact_row_per_uniform_item(): void
    {
        foreach (['Комплект', 'Майка', 'Поло', 'Толстовка'] as $item) {
            foreach (['6', '8', '10', '12', '14', '16', 'S', 'M', 'L', 'XL'] as $size) {
                $this->sellableUniformItem($item, $size, '500.00');
            }
        }

        $page = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'));
        $page->assertOk();
        $html = $page->getContent();

        // Exactly one compact row per canonical item — never one per the
        // 40 underlying item+size combinations.
        $this->assertSame(4, substr_count($html, 'data-uniform-item="'), 'expected exactly 4 compact Uniform item rows (one per item, not per item+size)');
        $this->assertStringContainsString('uniform-item-toggle', $html);
        $this->assertStringContainsString('uniform-item-size', $html);
        $this->assertStringContainsString('uniform-item-qty', $html);
        $this->assertStringContainsString('uniform-item-unit', $html);
        $this->assertStringContainsString('uniform-item-total', $html);
        // The old single mega-dropdown markup must be fully gone.
        $this->assertStringNotContainsString('uniform-product"', $html);
        $this->assertStringNotContainsString('<option value="">Выберите изделие</option>', $html);
    }
}
