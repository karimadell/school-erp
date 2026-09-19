<?php

namespace Tests\Feature\Finance;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoiceInstallment;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\CashSessionService;
use App\Services\Finance\InvoicePaymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Business-rule correction: UAT reported a valid partial-payment Quick
 * Registration rejected with "Платёж не может превышать остаток по
 * выбранному этапу." — proven root cause: InvoiceIssuanceService::
 * issueMixedInstallmentsAndCoverage() computed the mixed-billing 'once'
 * bucket installment's total from $itemsByFeeId, a map holding only ONE
 * representative InvoiceItem per fee_id. Uniform's multi-item selection is
 * the one case that legitimately produces several InvoiceItem rows sharing
 * one fee_id — every sibling but the last was silently excluded from the
 * once-bucket total, understating it. A later, fully legitimate payment
 * covering the WHOLE (correctly-summed) selection then appeared to exceed
 * that artificially small installment balance.
 *
 * Fixed by summing from $feePivotRows instead — an array already built,
 * in memory, correctly summed across every sibling item per fee_id (used
 * for the invoice_fee pivot table) — zero extra queries.
 */
class QuickRegistrationMixedUniformPartialPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private AcademicYear $year;

    private Stage $stage;

    private Grade $grade;

    private SchoolClass $class;

    private EnrollmentMode $mode;

    private CashAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->accountant = User::factory()->create(['is_active' => true]);
        $this->accountant->assignRole('accountant');

        $this->year = AcademicYear::create(['name' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-06-30', 'is_active' => true]);
        $this->stage = Stage::create(['name' => 'Начальная школа', 'order' => 1, 'is_active' => true]);
        $this->grade = Grade::forceCreate(['name' => '1 класс', 'stage_id' => $this->stage->id, 'level' => 1]);
        $this->class = SchoolClass::create(['grade_id' => $this->grade->id, 'code' => 'А', 'name_ru' => 'А', 'name_ar' => 'A', 'is_active' => true]);
        $this->mode = EnrollmentMode::create(['code' => EnrollmentMode::FULL_TIME, 'name_ru' => 'Очная форма', 'is_active' => true]);
        $this->ensureCanonicalRegistrationModeCatalog();
        $this->account = CashAccount::operating();
        app(CashSessionService::class)->open($this->account, $this->accountant);
    }

    private function base(): array
    {
        return [
            'student_last_name_ru' => 'Петрова', 'student_first_name_ru' => 'Мария',
            'phone' => '+20 100 555 9911', 'registration_date' => '2026-08-01',
            'academic_year_id' => $this->year->id, 'stage_id' => $this->stage->id, 'grade_id' => $this->grade->id,
            'class_id' => $this->class->id, 'enrollment_mode_id' => $this->mode->id,
            'payment_type' => 'mixed', 'payment_method' => 'cash', 'cash_account_id' => $this->account->id,
        ];
    }

    private function registrationFee(string $amount): Fee
    {
        return Fee::create(['name_ru' => 'Организационный взнос', 'category' => Fee::CATEGORY_REGISTRATION, 'amount' => $amount, 'is_active' => true]);
    }

    /** @return array{Fee, int, int} [fee, productIdA, productIdB] */
    private function multiItemUniformFee(string $amountA, string $amountB): array
    {
        $fee = Fee::create(['name_ru' => 'Школьная форма', 'category' => Fee::CATEGORY_UNIFORM, 'amount' => '0.00', 'is_active' => true]);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => $amountA, 'currency' => 'EGP', 'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true, 'item' => 'Майка', 'size' => '14']);
        $productA = DB::table('uniform_products')->insertGetId(['name_ru' => 'Майка', 'category' => 'garment', 'size' => '14', 'price' => $amountA, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        FeePrice::create(['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => $amountB, 'currency' => 'EGP', 'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true, 'item' => 'Шорты', 'size' => '14']);
        $productB = DB::table('uniform_products')->insertGetId(['name_ru' => 'Шорты', 'category' => 'garment', 'size' => '14', 'price' => $amountB, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return [$fee, $productA, $productB];
    }

    /** Monthly Tuition over exactly 4 whole calendar months (Aug-Nov 2026). @return Fee */
    private function tuitionFee(string $monthlyUnit): Fee
    {
        $fee = Fee::create(['name_ru' => 'Обучение', 'category' => Fee::CATEGORY_TUITION, 'amount' => '0.00', 'is_active' => true]);
        $fee->billingPeriods()->create(['billing_period' => 'monthly']);
        FeePrice::create([
            'fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => $monthlyUnit, 'currency' => 'EGP',
            'start_date' => $this->year->start_date, 'end_date' => $this->year->end_date, 'is_active' => true,
            'payment_period' => 'monthly', 'grade_group' => '1–4 классы',
        ]);

        return $fee;
    }

    // ----- 1/2: full once-bucket payment across a multi-item Uniform ----

    public function test_full_once_bucket_payment_across_multi_item_uniform_succeeds_with_true_total(): void
    {
        $registration = $this->registrationFee('7000.00');
        [$uniform, $productA, $productB] = $this->multiItemUniformFee('500.00', '300.00');

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base() + [
            'services' => [
                ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '7000.00'],
                ['fee_id' => $uniform->id, 'paid_now' => '800.00', 'uniform_items' => [
                    ['uniform_product_id' => $productA, 'quantity' => 1],
                    ['uniform_product_id' => $productB, 'quantity' => 1],
                ]],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame('7800.00', $invoice->total_amount);
        $this->assertSame('7800.00', $invoice->paid_amount);
        $this->assertSame('0.00', $invoice->remaining_amount);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);

        // 2: the once-bucket installment's own total is the TRUE sum of
        // Registration (7000) + BOTH Uniform siblings (500 + 300 = 800).
        $onceInstallment = InvoiceInstallment::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('7800.00', $onceInstallment->amount);
        $this->assertSame('0.00', $onceInstallment->remaining_amount);

        $uniformItems = InvoiceItem::where('fee_id', $uniform->id)->get();
        $this->assertCount(2, $uniformItems);
        $this->assertSame(0, bccomp((string) $uniformItems->sum('amount'), '800.00', 2));
        $this->assertSame(0, bccomp((string) $uniformItems->sum('paid_amount'), '800.00', 2));
    }

    // ----- 3/4: same scenario, PARTIAL once-bucket payment -------------

    public function test_partial_once_bucket_payment_across_multi_item_uniform_succeeds(): void
    {
        $registration = $this->registrationFee('7000.00');
        [$uniform, $productA, $productB] = $this->multiItemUniformFee('500.00', '300.00');

        // Registration paid in full (7000); Uniform paid 500 of its own
        // 800 total (partial) — once-bucket total 7800, paid 7500,
        // remaining 300, entirely attributable to the Uniform items.
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base() + [
            'services' => [
                ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '7000.00'],
                ['fee_id' => $uniform->id, 'paid_now' => '500.00', 'uniform_items' => [
                    ['uniform_product_id' => $productA, 'quantity' => 1],
                    ['uniform_product_id' => $productB, 'quantity' => 1],
                ]],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame('7800.00', $invoice->total_amount);
        $this->assertSame('7500.00', $invoice->paid_amount);
        $this->assertSame('300.00', $invoice->remaining_amount);
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->status);

        $onceInstallment = InvoiceInstallment::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('7800.00', $onceInstallment->amount);
        $this->assertSame('300.00', $onceInstallment->remaining_amount);

        // Greedy distribution: first sibling (500) settled in full, second
        // sibling (300) entirely unpaid — never negative, never duplicated.
        $uniformItems = InvoiceItem::where('fee_id', $uniform->id)->orderBy('id')->get();
        $this->assertSame('500.00', $uniformItems[0]->paid_amount);
        $this->assertSame('0.00', $uniformItems[0]->remaining_amount);
        $this->assertSame('0.00', $uniformItems[1]->paid_amount);
        $this->assertSame('300.00', $uniformItems[1]->remaining_amount);
    }

    // ----- 5/6: the exact reported UAT figures, plus a later payment ---

    /** @return array{Invoice, InvoiceInstallment} */
    private function registerUatScenario(): array
    {
        // Once bucket: Registration 5000 + Uniform (400+250=650) = 5650, paid in full.
        $registration = $this->registrationFee('5000.00');
        [$uniform, $productA, $productB] = $this->multiItemUniformFee('400.00', '250.00');
        // Calendar bucket: Tuition 4000/month x 4 months (Aug-Nov 2026) = 16000.
        $this->year->forceFill(['end_date' => '2026-11-30'])->save();
        $tuition = $this->tuitionFee('4000.00');

        // Invoice total: 5650 + 16000 = 21650.
        // Paid now: 5650 (once, full) + 12000 (tuition, 3 of 4 months) = 17650.
        // Remaining: 21650 - 17650 = 4000, entirely the 4th Tuition month.
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base() + [
            'services' => [
                ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '5000.00'],
                ['fee_id' => $uniform->id, 'paid_now' => '650.00', 'uniform_items' => [
                    ['uniform_product_id' => $productA, 'quantity' => 1],
                    ['uniform_product_id' => $productB, 'quantity' => 1],
                ]],
                ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '12000.00', 'billing_strategy' => 'calendar', 'payment_period' => 'monthly', 'grade_group' => '1–4 классы'],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $outstandingInstallment = InvoiceInstallment::where('invoice_id', $invoice->id)->where('remaining_amount', '>', '0')->sole();

        return [$invoice, $outstandingInstallment];
    }

    public function test_exact_uat_reported_figures_complete_successfully(): void
    {
        [$invoice, $outstandingInstallment] = $this->registerUatScenario();

        $this->assertSame('21650.00', $invoice->total_amount);
        $this->assertSame('17650.00', $invoice->paid_amount);
        $this->assertSame('4000.00', $invoice->remaining_amount);
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->status);

        // Exactly one installment carries the outstanding balance, and it
        // is exactly 4000 — the 4th Tuition month, fully unpaid.
        $this->assertSame('4000.00', $outstandingInstallment->remaining_amount);
        $this->assertSame('4000.00', $outstandingInstallment->amount);
        $this->assertSame(1, InvoiceInstallment::where('invoice_id', $invoice->id)->where('remaining_amount', '>', '0')->count());
    }

    public function test_later_payment_clears_outstanding_debt_without_duplicating_charges(): void
    {
        [$invoice, $outstandingInstallment] = $this->registerUatScenario();

        $studentCountBefore = Student::count();
        $invoiceCountBefore = Invoice::count();
        $itemCountBefore = InvoiceItem::count();
        $paymentCountBefore = InvoicePayment::count();

        // Every prior payment on this invoice was explicitly attributed
        // (item-level for the once-bucket, coverage-period-level — which
        // itself resolves to item-level — for the Tuition bucket), so the
        // invoice is allocation-clean: Phase 1C/1E requires this payment
        // to keep supplying an explicit split too, exactly like
        // FinanceOperationsController::storePayment() does for a clean
        // multi-item invoice.
        $tuitionItem = InvoiceItem::where('invoice_id', $invoice->id)
            ->whereHas('fee', fn ($q) => $q->where('category', Fee::CATEGORY_TUITION))->sole();

        // The parent returns later with the remaining 4000 — recorded as a
        // brand new payment/receipt against the SAME existing invoice.
        $secondPayment = app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $this->account->id,
            amount: '4000.00',
            paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
            reference: 'Доплата по счёту '.$invoice->invoice_number,
            installmentId: $outstandingInstallment->id,
            allocations: [['invoice_item_id' => $tuitionItem->id, 'amount' => '4000.00']],
        );

        // No duplicate Student/Invoice/InvoiceItem — same counts as before,
        // only a new InvoicePayment (receipt) was created.
        $this->assertSame($studentCountBefore, Student::count());
        $this->assertSame($invoiceCountBefore, Invoice::count());
        $this->assertSame($itemCountBefore, InvoiceItem::count());
        $this->assertSame($paymentCountBefore + 1, InvoicePayment::count());
        // The initial mixed registration itself made 2 payment() calls
        // (once-bucket + calendar-periods bucket); this later payment is
        // the 3rd, and the only one made outside the original registration.
        $this->assertSame(3, InvoicePayment::where('invoice_id', $invoice->id)->count());
        $this->assertNotNull($secondPayment->payment_number);

        $invoice->refresh();
        $this->assertSame('21650.00', $invoice->paid_amount);
        $this->assertSame('0.00', $invoice->remaining_amount);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
    }

    // ----- 7: overpayment protection must remain intact -----------------

    public function test_overpayment_against_an_installment_is_still_rejected(): void
    {
        // A dedicated two-outstanding-installment scenario, so the amount
        // attempted below stays WITHIN the invoice's own total remaining
        // (proving the invoice-level cap isn't what rejects it) while
        // exceeding the SPECIFIC targeted installment's own remaining —
        // isolating exactly the check this fix must never weaken.
        $registration = $this->registrationFee('7000.00');
        $this->year->forceFill(['end_date' => '2026-09-30'])->save();
        $tuition = $this->tuitionFee('2000.00');

        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base() + [
            'services' => [
                ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '7000.00'],
                ['fee_id' => $tuition->id, 'quantity' => 1, 'paid_now' => '0.00', 'billing_strategy' => 'calendar', 'payment_period' => 'monthly', 'grade_group' => '1–4 классы'],
            ],
        ]);
        $response->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        // 7000 + 2000x2 = 11000 total, 7000 paid, 4000 remaining (both
        // Tuition months, 2000 each, fully outstanding).
        $this->assertSame('4000.00', $invoice->remaining_amount);
        $firstMonth = InvoiceInstallment::where('invoice_id', $invoice->id)->where('remaining_amount', '2000.00')->orderBy('sequence')->firstOrFail();
        $tuitionItem = InvoiceItem::where('invoice_id', $invoice->id)
            ->whereHas('fee', fn ($q) => $q->where('category', Fee::CATEGORY_TUITION))->sole();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->expectExceptionMessage('Платёж не может превышать остаток по выбранному этапу.');

        // 2001 stays within both the invoice's own 4000 total remaining
        // AND the Tuition item's own 4000 remaining capacity (so neither
        // of those broader caps is what rejects it), but exceeds THIS
        // installment's own 2000 remaining — must still fail.
        app(InvoicePaymentService::class)->record(
            invoiceId: $invoice->id,
            cashAccountId: $this->account->id,
            amount: '2001.00',
            paymentMethod: 'cash',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
            installmentId: $firstMonth->id,
            allocations: [['invoice_item_id' => $tuitionItem->id, 'amount' => '2001.00']],
        );
    }

    // ----- 8: rollback on a genuine validation failure is unchanged -----

    public function test_a_genuine_once_bucket_overpayment_rolls_back_completely(): void
    {
        $registration = $this->registrationFee('7000.00');
        [$uniform, $productA, $productB] = $this->multiItemUniformFee('500.00', '300.00');

        // paid_now for Uniform (900) exceeds its own resolved cost (800) —
        // rejected by the existing per-line cap, before ever reaching the
        // once-bucket installment check. Nothing may survive.
        $response = $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base() + [
            'services' => [
                ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '7000.00'],
                ['fee_id' => $uniform->id, 'paid_now' => '900.00', 'uniform_items' => [
                    ['uniform_product_id' => $productA, 'quantity' => 1],
                    ['uniform_product_id' => $productB, 'quantity' => 1],
                ]],
            ],
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, Student::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, InvoiceItem::count());
        $this->assertSame(0, InvoiceInstallment::count());
    }

    // ----- 9: debt wording on the Quick Registration success panel -----

    public function test_success_panel_shows_a_clear_debt_warning_when_outstanding(): void
    {
        $registration = $this->registrationFee('7000.00');
        [$uniform, $productA, $productB] = $this->multiItemUniformFee('500.00', '300.00');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base() + [
            'services' => [
                ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '7000.00'],
                ['fee_id' => $uniform->id, 'paid_now' => '500.00', 'uniform_items' => [
                    ['uniform_product_id' => $productA, 'quantity' => 1],
                    ['uniform_product_id' => $productB, 'quantity' => 1],
                ]],
            ],
        ])->assertSessionHasNoErrors();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'));

        $response->assertOk();
        $response->assertSee('Задолженность: 300.00 EGP');
    }

    public function test_success_panel_omits_the_debt_warning_when_fully_paid(): void
    {
        $registration = $this->registrationFee('7000.00');

        $this->actingAs($this->accountant)->post(route('dashboard.quick-registration.store'), $this->base() + [
            'services' => [
                ['fee_id' => $registration->id, 'quantity' => 1, 'paid_now' => '7000.00'],
            ],
        ])->assertSessionHasNoErrors();

        $response = $this->actingAs($this->accountant)->get(route('dashboard.quick-registration.create'));

        $response->assertOk();
        $response->assertDontSee('Задолженность:', false);
    }
}
