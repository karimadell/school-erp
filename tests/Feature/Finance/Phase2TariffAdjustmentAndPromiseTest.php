<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\PromiseToPay;
use App\Models\ServiceCoverage;
use App\Models\Student;
use App\Models\StudentCredit;
use App\Models\TariffAdjustment;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\PromiseToPayService;
use App\Services\Finance\ServiceCoverageService;
use App\Services\Finance\StudentCreditService;
use App\Services\Finance\StudentFinanceSummaryService;
use App\Services\Finance\TariffAdjustmentService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

class Phase2TariffAdjustmentAndPromiseTest extends FinanceOperationsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Permission::findOrCreate('approve tariff adjustments', 'web');
        $this->accountant->givePermissionTo('approve tariff adjustments');
    }

    public function test_successive_transport_increases_use_non_overlapping_immediate_predecessor_segments(): void
    {
        [$coverage, $original, $january, $march] = $this->transportCoverage('2027-05-31');
        $payment = $this->pay($coverage->invoiceItem->invoice, (string) $coverage->invoiceItem->invoice->total_amount);
        $originalInvoice = $coverage->invoiceItem->invoice->fresh()->toArray();
        $originalItem = $coverage->invoiceItem->fresh()->toArray();
        $originalPayment = $payment->fresh()->toArray();
        $service = app(TariffAdjustmentService::class);

        $janPreview = $service->preview($coverage, $january);
        $this->assertSame(['2027-01-01', '2027-02-28'], $janPreview['segment']);
        $this->assertSame(2, $janPreview['units']);
        $this->assertSame('300.00', $janPreview['difference_per_unit']);
        $this->assertSame('600.00', $janPreview['total_difference']);
        $this->assertDatabaseCount('tariff_adjustments', 0);

        $janAdjustment = $service->approve($coverage, $january, $this->accountant);
        $marchPreview = $service->preview($coverage, $march);
        $this->assertSame($january->id, $marchPreview['previous_fee_price']->id);
        $this->assertSame(['2027-03-01', '2027-05-31'], $marchPreview['segment']);
        $this->assertSame(3, $marchPreview['units']);
        $this->assertSame('200.00', $marchPreview['difference_per_unit']);
        $this->assertSame('600.00', $marchPreview['total_difference']);
        $marchAdjustment = $service->approve($coverage, $march, $this->accountant);

        $this->assertSame('1200.00', TariffAdjustment::all()->reduce(fn ($sum, $row) => bcadd($sum, $row->total_difference, 2), '0.00'));
        $this->assertSame(2, $janAdjustment->segments->first()->units);
        $this->assertSame(3, $marchAdjustment->segments->first()->units);
        $this->assertSame($janAdjustment->id, $service->approve($coverage, $january, $this->accountant)->id);
        $this->assertDatabaseCount('tariff_adjustments', 2);
        $this->assertDatabaseCount('tariff_adjustment_segments', 2);
        $this->assertSame($originalInvoice, $coverage->invoiceItem->invoice->fresh()->toArray());
        $this->assertSame($originalItem, $coverage->invoiceItem->fresh()->toArray());
        $this->assertSame($originalPayment, InvoicePayment::find($originalPayment['id'])->toArray());
        $this->assertNotSame($original->id, $marchPreview['previous_fee_price']->id);
    }

    public function test_coverage_limits_adjustments_and_preserves_zone_and_period_dimensions(): void
    {
        [$partial, , $january, $march] = $this->transportCoverage('2027-02-28');
        $service = app(TariffAdjustmentService::class);
        $this->assertSame('600.00', $service->preview($partial, $january)['total_difference']);
        $this->assertSame(0, $service->preview($partial, $march)['units']);
        $this->assertNull($service->approve($partial, $march, $this->accountant));

        $wrongZone = $this->price($partial->fee, '1900.00', '2027-01-01', '2027-02-28', ['option_type' => 'zone', 'option_value' => 'Зона 2', 'payment_period' => 'monthly']);
        $wrongPeriod = $this->price($partial->fee, '19000.00', '2027-01-01', '2027-02-28', ['option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'yearly']);
        foreach ([$wrongZone, $wrongPeriod] as $invalid) {
            try {
                $service->preview($partial, $invalid);
                $this->fail('Mismatched dimensions must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('new_fee_price_id', $exception->errors());
            }
        }

        [$ended, , , $after] = $this->transportCoverage('2026-12-31', 'Зона 3');
        $this->assertSame(0, $service->preview($ended, $after)['units']);
    }

    public function test_tariff_decrease_posts_credit_without_invoice_payment_refund_or_cash(): void
    {
        [$coverage, , , $march] = $this->transportCoverage('2027-05-31', 'Зона 1', '1800.00', '1600.00');
        $before = [Invoice::count(), InvoicePayment::count(), \App\Models\PaymentRefund::count(), \App\Models\CashTransaction::count()];
        $adjustment = app(TariffAdjustmentService::class)->approve($coverage, $march, $this->accountant);

        $this->assertSame('-600.00', $adjustment->total_difference);
        $this->assertSame('credit', $adjustment->kind);
        $this->assertNull($adjustment->posting_invoice_id);
        $this->assertSame($before, [Invoice::count(), InvoicePayment::count(), \App\Models\PaymentRefund::count(), \App\Models\CashTransaction::count()]);
        $summary = app(StudentFinanceSummaryService::class)->summarize($this->student);
        $this->assertSame('600.00', $summary['available_credit']);
        $this->assertSame('0.00', $summary['credit_applied']);
    }

    public function test_food_daily_coverage_uses_daily_effective_interval(): void
    {
        $food = Fee::create(['name_ru' => 'Питание', 'category' => Fee::CATEGORY_FOOD, 'amount' => '1.00', 'is_active' => true]);
        $old = $this->price($food, '70.00', '2026-09-01', '2027-01-09', ['option_type' => 'meal_plan', 'option_value' => 'Завтрак', 'payment_period' => 'daily']);
        $new = $this->price($food, '80.00', '2027-01-10', '2027-06-30', ['option_type' => 'meal_plan', 'option_value' => 'Завтрак', 'payment_period' => 'daily']);
        $coverage = $this->coverage($food, $old, '2027-01-10', '2027-01-14', 'daily');
        $preview = app(TariffAdjustmentService::class)->preview($coverage, $new);

        $this->assertSame(5, $preview['units']);
        $this->assertSame('50.00', $preview['total_difference']);
    }

    public function test_partial_payment_remains_canonical_and_promise_never_changes_money(): void
    {
        $invoice = $this->invoice('5000.00');
        $payment = $this->pay($invoice, '4500.00');
        $invoice->refresh();
        $this->assertSame(['4500.00', '500.00', Invoice::STATUS_PARTIAL], [$invoice->paid_amount, $invoice->remaining_amount, $invoice->status]);

        $promises = app(PromiseToPayService::class);
        $promise = $promises->create($this->student, [
            'invoice_id' => $invoice->id, 'promised_amount' => '500.00',
            'expected_payment_date' => now()->subDay()->toDateString(), 'note' => 'Оплата на следующей неделе',
        ], $this->accountant);
        $this->assertTrue($promise->is_overdue);
        $this->assertSame(['4500.00', '500.00', Invoice::STATUS_PARTIAL], [$invoice->fresh()->paid_amount, $invoice->fresh()->remaining_amount, $invoice->fresh()->status]);
        $this->assertDatabaseCount('invoice_payments', 1);

        $lastPayment = $this->pay($invoice, '500.00');
        $promises->fulfill($promise, $lastPayment, $this->accountant);
        $this->assertSame(PromiseToPay::STATUS_FULFILLED, $promise->fresh()->status);
        $this->assertSame($lastPayment->id, $promise->fresh()->invoice_payment_id);
        $this->assertSame(['500.00', 'Оплата на следующей неделе'], [$promise->fresh()->promised_amount, $promise->fresh()->note]);
        $this->assertSame(['5000.00', '0.00', Invoice::STATUS_PAID], [$invoice->fresh()->paid_amount, $invoice->fresh()->remaining_amount, $invoice->fresh()->status]);
    }

    public function test_promises_cancel_and_keep_independent_history_without_affecting_totals(): void
    {
        $invoice = $this->invoice('500.00');
        $service = app(PromiseToPayService::class);
        $first = $service->create($this->student, ['invoice_id' => $invoice->id, 'promised_amount' => '300.00', 'expected_payment_date' => '2027-01-01', 'note' => 'Первое'], $this->accountant);
        $second = $service->create($this->student, ['invoice_id' => $invoice->id, 'promised_amount' => '200.00', 'expected_payment_date' => now()->subDay()->toDateString(), 'note' => 'Второе'], $this->accountant);
        $before = $invoice->fresh()->toArray();
        $service->cancel($first, $this->accountant, 'Не подтверждено');
        $summary = app(StudentFinanceSummaryService::class)->summarize($this->student->fresh());

        $this->assertSame($before, $invoice->fresh()->toArray());
        $this->assertSame('200.00', $summary['promised']);
        $this->assertSame('500.00', $summary['remaining']);
        $this->assertDatabaseCount('promise_to_pays', 2);
        $this->assertSame(PromiseToPay::STATUS_CANCELLED, $first->fresh()->status);
        $this->assertSame(PromiseToPay::STATUS_OPEN, $second->fresh()->status);
        $this->actingAs($this->accountant)->get(route('dashboard.students.finance', $this->student))
            ->assertOk()->assertSee('Корректировки')->assertSee('Обещания оплаты')->assertSee('ПРОСРОЧЕНО');
    }

    public function test_accountant_preview_route_does_not_post_and_explicit_approval_posts_auditable_debit(): void
    {
        [$coverage, , $january] = $this->transportCoverage('2027-05-31');
        $originalInvoiceCount = Invoice::count();

        $this->actingAs($this->accountant)->post(route('dashboard.finance.adjustments.preview'), [
            'new_fee_price_id' => $january->id,
        ])->assertOk()->assertSee($this->student->full_name)->assertSee('600.00 EGP')->assertSee('Предпросмотр не создаёт долг');
        $this->assertDatabaseCount('tariff_adjustments', 0);
        $this->assertSame($originalInvoiceCount, Invoice::count());

        $this->post(route('dashboard.finance.adjustments.store'), [
            'service_coverage_id' => $coverage->id,
            'new_fee_price_id' => $january->id,
            'note' => 'Утверждено бухгалтером',
        ])->assertRedirect(route('dashboard.students.finance', $this->student));
        $adjustment = TariffAdjustment::with('postingInvoice')->sole();
        $this->assertSame('600.00', $adjustment->total_difference);
        $this->assertSame($this->accountant->id, $adjustment->approved_by);
        $this->assertNotNull($adjustment->postingInvoice);
        $this->assertSame($originalInvoiceCount + 1, Invoice::count());

        $this->get(route('dashboard.students.finance', $this->student))
            ->assertOk()->assertSee('ДОЛГ')->assertSee('600.00 EGP')->assertSee('Доначисление');
    }

    public function test_same_date_tariffs_are_ambiguous_and_existing_segment_overlap_is_rejected(): void
    {
        [$coverage, , $january] = $this->transportCoverage('2027-05-31');
        $duplicate = $this->price($coverage->fee, '1850.00', '2027-01-01', '2027-02-28', [
            'option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'monthly',
        ]);
        $service = app(TariffAdjustmentService::class);
        foreach ([$january, $duplicate] as $price) {
            try {
                $service->preview($coverage, $price);
                $this->fail('Competing canonical tariffs must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('new_fee_price_id', $exception->errors());
            }
        }

        $duplicate->delete();
        $first = $service->approve($coverage, $january, $this->accountant);
        $other = $this->price($coverage->fee, '1900.00', '2027-01-15', '2027-02-15', [
            'option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'monthly',
        ]);
        $this->expectException(ValidationException::class);
        $service->approve($coverage, $other, $this->accountant);
        $this->assertNotNull($first);
    }

    public function test_coverage_rejects_untrusted_provenance_and_invalid_month_boundaries(): void
    {
        [$coverage, $original] = $this->transportCoverage('2027-05-31');
        $item = $coverage->invoiceItem;
        $service = app(ServiceCoverageService::class);
        $otherPrice = $this->price($coverage->fee, '1550.00', '2026-09-01', '2026-12-31', [
            'option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'monthly',
        ]);
        ServiceCoverage::query()->delete();

        foreach ([
            ['fee_price_id' => $otherPrice->id, 'coverage_start' => '2026-09-01', 'coverage_end' => '2027-05-31', 'billing_unit' => 'monthly'],
            ['fee_price_id' => $original->id, 'coverage_start' => '2026-09-02', 'coverage_end' => '2027-05-31', 'billing_unit' => 'monthly'],
            ['fee_price_id' => $original->id, 'coverage_start' => '2026-09-01', 'coverage_end' => '2027-05-30', 'billing_unit' => 'monthly'],
        ] as $invalid) {
            try {
                $service->record($item, $invalid, $this->accountant, $this->student);
                $this->fail('Invalid coverage provenance must be rejected.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('service_coverages', 0);
            }
        }
        $this->assertSame('1500.00', $item->unit_price);
    }

    public function test_coverage_rejects_student_subscription_and_dimension_conflicts_and_ignores_spoofed_price(): void
    {
        [$coverage, $original] = $this->transportCoverage('2027-05-31');
        $item = $coverage->invoiceItem;
        ServiceCoverage::query()->delete();
        $service = app(ServiceCoverageService::class);
        $data = ['fee_price_id' => $original->id, 'coverage_start' => '2026-09-01', 'coverage_end' => '2027-05-31', 'billing_unit' => 'monthly'];
        $otherStudent = Student::create(['last_name_ru' => 'Сидоров', 'first_name_ru' => 'Сидор', 'phone' => '+201001112255', 'status' => 'registration_completed']);
        try {
            $service->record($item, $data, $this->accountant, $otherStudent);
            $this->fail('Route student mismatch must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice_item_id', $exception->errors());
        }

        $item->forceFill(['metadata' => array_merge($item->metadata, ['option_value' => 'Зона 2'])])->save();
        try {
            $service->record($item->fresh(), $data, $this->accountant, $this->student);
            $this->fail('Conflicting authoritative dimensions must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fee_price_id', $exception->errors());
        }
        $item->forceFill(['metadata' => array_merge($item->metadata, ['option_value' => 'Зона 1'])])->save();
        $wrongFee = Fee::create(['name_ru' => 'Другая услуга', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $wrongSubscription = \App\Models\StudentServiceSubscription::create(['enrollment_id' => $this->enrollment->id, 'fee_id' => $wrongFee->id, 'start_date' => '2026-09-01', 'status' => 'active']);
        $item->forceFill(['subscription_id' => $wrongSubscription->id])->save();
        try {
            $service->record($item->fresh(), $data, $this->accountant, $this->student);
            $this->fail('Subscription service mismatch must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice_item_id', $exception->errors());
        }
        $item->forceFill(['subscription_id' => null])->save();
        $recorded = $service->record($item->fresh(), $data + ['original_unit_price' => '0.01'], $this->accountant, $this->student);
        $this->assertSame('1500.00', $recorded->original_unit_price);
    }

    public function test_posted_segment_overlap_guard_rejects_a_second_economic_effect(): void
    {
        [$coverage, , $january, $march] = $this->transportCoverage('2027-05-31');
        $service = app(TariffAdjustmentService::class);
        $service->approve($coverage, $january, $this->accountant);
        \Illuminate\Support\Facades\DB::table('tariff_adjustment_segments')->update(['segment_end' => '2027-03-31']);

        $this->expectException(ValidationException::class);
        $service->approve($coverage, $march, $this->accountant);
    }

    public function test_full_month_policy_handles_leap_february(): void
    {
        $this->year->forceFill(['end_date' => '2028-06-30'])->save();
        [$coverage] = $this->transportCoverage('2028-02-29');
        $price = $this->price($coverage->fee, '1800.00', '2028-02-01', '2028-02-29', [
            'option_type' => 'zone', 'option_value' => 'Зона 1', 'payment_period' => 'monthly',
        ]);
        $preview = app(TariffAdjustmentService::class)->preview($coverage->fresh(), $price);
        $this->assertSame(1, $preview['units']);
        $this->assertSame(['2028-02-01', '2028-02-29'], $preview['segment']);
    }

    public function test_credit_application_is_explicit_auditable_and_preserves_excess(): void
    {
        [$coverage, , , $decrease] = $this->transportCoverage('2027-05-31', 'Зона 1', '1800.00', '1600.00');
        app(TariffAdjustmentService::class)->approve($coverage, $decrease, $this->accountant);
        $credit = StudentCredit::sole();
        $invoice = $this->invoice('500.00', now()->subDay()->toDateString());
        $before = [InvoicePayment::count(), \App\Models\CashTransaction::count(), \App\Models\PaymentRefund::count()];
        $key = (string) Str::uuid();
        $application = app(StudentCreditService::class)->apply($credit, $invoice, '500.00', $key, $this->accountant);
        $replay = app(StudentCreditService::class)->apply($credit, $invoice, '500.00', $key, $this->accountant);

        $this->assertSame($application->id, $replay->id);
        $this->assertSame(['600.00', '500.00', '100.00'], [$credit->fresh()->original_amount, $credit->fresh()->consumed_amount, $credit->fresh()->available_amount]);
        $this->assertDatabaseCount('student_credit_applications', 1);
        $this->assertSame($before, [InvoicePayment::count(), \App\Models\CashTransaction::count(), \App\Models\PaymentRefund::count()]);
        $summary = app(StudentFinanceSummaryService::class)->summarize($this->student->fresh());
        $this->assertSame('500.00', $summary['credit_applied']);
        $this->assertSame('100.00', $summary['available_credit']);
        $this->assertSame('0.00', $summary['overdue_net']);

        $later = $this->invoice('100.00');
        app(StudentCreditService::class)->apply($credit->fresh(), $later, '100.00', (string) Str::uuid(), $this->accountant);
        $this->assertSame(['600.00', '0.00', StudentCredit::STATUS_CONSUMED], [
            $credit->fresh()->consumed_amount, $credit->fresh()->available_amount, $credit->fresh()->status,
        ]);
        $this->assertDatabaseCount('student_credit_applications', 2);
    }

    public function test_posting_requires_dedicated_approval_permission(): void
    {
        [$coverage, , $january] = $this->transportCoverage('2027-05-31');
        $this->accountant->revokePermissionTo('approve tariff adjustments');
        $this->actingAs($this->accountant)->post(route('dashboard.finance.adjustments.store'), [
            'service_coverage_id' => $coverage->id, 'new_fee_price_id' => $january->id,
        ])->assertForbidden();
        $this->assertDatabaseCount('tariff_adjustments', 0);
    }

    public function test_promise_fulfilment_rejects_cross_student_wrong_invoice_and_reused_payment(): void
    {
        $service = app(PromiseToPayService::class);
        $invoice = $this->invoice('100.00');
        $otherInvoice = $this->invoice('100.00');
        $promise = $service->create($this->student, ['invoice_id' => $invoice->id, 'promised_amount' => '100.00', 'expected_payment_date' => '2027-01-01'], $this->accountant);
        $wrongInvoicePayment = $this->pay($otherInvoice, '100.00');
        try {
            $service->fulfill($promise, $wrongInvoicePayment, $this->accountant);
            $this->fail('Wrong-invoice payment must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice_payment_id', $exception->errors());
        }

        $payment = $this->pay($invoice, '100.00');
        $service->fulfill($promise, $payment, $this->accountant);
        $second = $service->create($this->student, ['invoice_id' => $invoice->id, 'promised_amount' => '10.00', 'expected_payment_date' => '2027-01-02'], $this->accountant);
        $this->expectException(ValidationException::class);
        $service->fulfill($second, $payment, $this->accountant);
    }

    public function test_cross_student_payment_and_nonexistent_payment_are_rejected(): void
    {
        $other = Student::create(['last_name_ru' => 'Петров', 'first_name_ru' => 'Пётр', 'phone' => '+201001112244', 'status' => 'registration_completed']);
        $otherInvoice = Invoice::create(['student_id' => $other->id, 'academic_year_id' => $this->year->id, 'customer_name' => $other->full_name, 'currency' => 'EGP', 'subtotal_amount' => '50.00', 'total_amount' => '50.00', 'discount_amount' => '0.00', 'paid_amount' => '0.00', 'remaining_amount' => '50.00', 'status' => 'unpaid', 'due_date' => '2027-01-01', 'created_by' => $this->accountant->id]);
        $otherInvoice->invoice_number = Invoice::numberFor($otherInvoice->id, '2026');
        $otherInvoice->save();
        $payment = $this->pay($otherInvoice, '50.00');
        $promise = app(PromiseToPayService::class)->create($this->student, ['promised_amount' => '50.00', 'expected_payment_date' => '2027-01-01'], $this->accountant);
        try {
            app(PromiseToPayService::class)->fulfill($promise, $payment, $this->accountant);
            $this->fail('Cross-student payment must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice_payment_id', $exception->errors());
        }
        $this->actingAs($this->accountant)->post(route('dashboard.finance.promises.fulfill', $promise), ['invoice_payment_id' => 999999])
            ->assertSessionHasErrors('invoice_payment_id');
    }

    public function test_promise_model_rejects_incoherent_state_and_non_positive_amount(): void
    {
        $this->expectException(\LogicException::class);
        PromiseToPay::create([
            'student_id' => $this->student->id, 'promised_amount' => '0.00',
            'expected_payment_date' => '2027-01-01', 'status' => PromiseToPay::STATUS_OPEN,
        ]);
    }

    private function transportCoverage(string $coverageEnd, string $zone = 'Зона 1', string $januaryAmount = '1800.00', string $marchAmount = '2000.00'): array
    {
        $transport = Fee::create(['name_ru' => 'Трансфер', 'category' => Fee::CATEGORY_TRANSPORT, 'amount' => '1.00', 'is_active' => true]);
        $dimensions = ['option_type' => 'zone', 'option_value' => $zone, 'payment_period' => 'monthly'];
        $original = $this->price($transport, '1500.00', '2026-09-01', '2026-12-31', $dimensions);
        $january = $this->price($transport, $januaryAmount, '2027-01-01', '2027-02-28', $dimensions);
        $march = $this->price($transport, $marchAmount, '2027-03-01', '2027-06-30', $dimensions);

        return [$this->coverage($transport, $original, '2026-09-01', $coverageEnd, 'monthly'), $original, $january, $march];
    }

    private function coverage(Fee $fee, FeePrice $price, string $start, string $end, string $unit): ServiceCoverage
    {
        $invoice = Invoice::create(['student_id' => $this->student->id, 'academic_year_id' => $this->year->id, 'customer_name' => $this->student->full_name, 'currency' => 'EGP', 'subtotal_amount' => $price->amount, 'total_amount' => $price->amount, 'discount_amount' => '0.00', 'paid_amount' => '0.00', 'remaining_amount' => $price->amount, 'status' => 'unpaid', 'due_date' => $start, 'created_by' => $this->accountant->id]);
        $invoice->invoice_number = Invoice::numberFor($invoice->id, '2026');
        $invoice->save();
        $item = InvoiceItem::create(['invoice_id' => $invoice->id, 'fee_id' => $fee->id, 'description' => $fee->name_ru, 'unit_price' => $price->amount, 'quantity' => 1, 'amount' => $price->amount, 'paid_amount' => '0.00', 'remaining_amount' => $price->amount, 'metadata' => ['fee_price_id' => $price->id, 'option_type' => $price->option_type, 'option_value' => $price->option_value, 'payment_period' => $price->payment_period]]);

        return app(ServiceCoverageService::class)->record($item, ['fee_price_id' => $price->id, 'coverage_start' => $start, 'coverage_end' => $end, 'billing_unit' => $unit], $this->accountant);
    }

    private function price(Fee $fee, string $amount, string $start, string $end, array $dimensions): FeePrice
    {
        return FeePrice::create($dimensions + ['fee_id' => $fee->id, 'academic_year_id' => $this->year->id, 'amount' => $amount, 'currency' => 'EGP', 'start_date' => $start, 'end_date' => $end, 'is_active' => true]);
    }

    private function pay(Invoice $invoice, string $amount): InvoicePayment
    {
        return app(InvoicePaymentService::class)->record($invoice->id, $this->cash->id, $amount, 'cash', (string) Str::uuid(), $this->accountant);
    }
}
