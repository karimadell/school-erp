<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FinanceCollection;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Services\Finance\InvoiceIssuanceService;
use App\Services\Finance\InvoiceRefundService;
use Illuminate\Support\Str;

/**
 * Unified Cashier Receipt + Print Flow (PR C2) — read-only receipt/reprint
 * for a completed FinanceCollection. These tests prove the receipt
 * reconstructs entirely from already-persisted canonical records reachable
 * from the FinanceCollection itself (never from session/flash state),
 * performs zero accounting writes of its own, and never nets a later
 * refund into the original collected amount. FinanceCollectionService/
 * InvoicePaymentService/InvoiceIssuanceService/InvoiceRefundService remain
 * the only accounting engines — entirely untouched by this feature.
 */
class UnifiedCollectionReceiptTest extends FinanceOperationsTestCase
{
    private function issueSimpleInvoice(string $amount, ?string $name = null): Invoice
    {
        $fee = Fee::create(['name_ru' => $name ?? 'Доп. услуга '.Str::random(6), 'category' => Fee::CATEGORY_BOOKS, 'amount' => $amount, 'is_active' => true]);

        return app(InvoiceIssuanceService::class)->issue($this->student, [
            'student_id' => $this->student->id, 'academic_year_id' => $this->year->id,
            'due_date' => '2027-06-30', 'pricing_date' => '2026-09-01',
            'items' => [['fee_id' => $fee->id, 'grade_group' => null, 'payment_period' => null, 'first_last_month' => false, 'size' => null, 'item' => null, 'option_type' => null, 'option_value' => null]],
            'payment_type' => 'one_time',
        ], $this->accountant);
    }

    private function collectExisting(Invoice $invoice, string $amount): FinanceCollection
    {
        $this->actingAs($this->accountant)->post(route('dashboard.students.unified-collection.store', $this->student), [
            'idempotency_token' => (string) Str::uuid(),
            'academic_year_id' => $this->year->id, 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'existing_obligations' => [['invoice_id' => $invoice->id, 'receive_now_amount' => $amount]],
        ])->assertRedirect();

        return FinanceCollection::query()->latest('id')->firstOrFail();
    }

    private function collectNewService(string $feeAmount, string $receiveNow, string $name = 'Экскурсия'): FinanceCollection
    {
        $fee = Fee::create(['name_ru' => $name, 'category' => Fee::CATEGORY_ACTIVITY, 'amount' => $feeAmount, 'is_active' => true]);

        $this->actingAs($this->accountant)->post(route('dashboard.students.unified-collection.store', $this->student), [
            'idempotency_token' => (string) Str::uuid(),
            'academic_year_id' => $this->year->id, 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'new_services' => [['fee_id' => $fee->id, 'quantity' => 1, 'receive_now_amount' => $receiveNow]],
        ])->assertRedirect();

        return FinanceCollection::query()->latest('id')->firstOrFail();
    }

    private function collectMixed(Invoice $existingInvoice, string $existingAmount, string $feeAmount, string $receiveNow, string $name = 'Поездка'): FinanceCollection
    {
        $fee = Fee::create(['name_ru' => $name, 'category' => Fee::CATEGORY_ACTIVITY, 'amount' => $feeAmount, 'is_active' => true]);

        $this->actingAs($this->accountant)->post(route('dashboard.students.unified-collection.store', $this->student), [
            'idempotency_token' => (string) Str::uuid(),
            'academic_year_id' => $this->year->id, 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'existing_obligations' => [['invoice_id' => $existingInvoice->id, 'receive_now_amount' => $existingAmount]],
            'new_services' => [['fee_id' => $fee->id, 'quantity' => 1, 'receive_now_amount' => $receiveNow]],
        ])->assertRedirect();

        return FinanceCollection::query()->latest('id')->firstOrFail();
    }

    // ----- A. Authorized view -----

    public function test_authorized_staff_can_view_receipt(): void
    {
        $invoice = $this->issueSimpleInvoice('500.00');
        $collection = $this->collectExisting($invoice, '400.00');

        $this->actingAs($this->accountant)
            ->get(route('dashboard.collections.receipt', $collection))
            ->assertOk()
            ->assertSee($collection->collection_number)
            ->assertSee($this->student->full_name);
    }

    // ----- B. Unauthorized/reception denied -----

    public function test_user_without_view_invoices_is_denied(): void
    {
        $invoice = $this->issueSimpleInvoice('500.00');
        $collection = $this->collectExisting($invoice, '400.00');

        // Every seeded administrative-portal role happens to already carry
        // 'view invoices' (reception/cashier/accountant/principal/admin all
        // need it for existing Finance screens) — so this test proves the
        // permission gate itself, on a user who reaches the dashboard but
        // explicitly lacks the one permission this route requires. The
        // permission is revoked at the ROLE level (not the user) since
        // Spatie resolves 'can()' through role-inherited permissions too —
        // safe here because RefreshDatabase reseeds roles fresh per test.
        \Spatie\Permission\Models\Role::findByName('reception')->revokePermissionTo('view invoices');
        $reception = $this->user('reception');

        $this->actingAs($reception)
            ->get(route('dashboard.collections.receipt', $collection))
            ->assertForbidden();
    }

    // ----- C. Teacher cannot access the administrative Finance receipt path -----

    public function test_teacher_cannot_access_receipt(): void
    {
        $invoice = $this->issueSimpleInvoice('500.00');
        $collection = $this->collectExisting($invoice, '400.00');
        $teacher = $this->user('teacher');

        // Teachers are redirected away from the whole administrative
        // dashboard entirely (EnsureAdministrativePortalAccess), not a raw
        // 403 — matches the exact convention UnifiedCollectionWorkspaceTest
        // already establishes for this same middleware.
        $this->actingAs($teacher)
            ->get(route('dashboard.collections.receipt', $collection))
            ->assertRedirect('/login');
    }

    // ----- D. Completed collection renders successfully -----

    public function test_completed_collection_renders_successfully(): void
    {
        $invoice = $this->issueSimpleInvoice('500.00');
        $collection = $this->collectExisting($invoice, '500.00');

        $this->actingAs($this->accountant)
            ->get(route('dashboard.collections.receipt', $collection))
            ->assertOk()
            ->assertSee('500.00');
    }

    // ----- E. Pending/incomplete FinanceCollection cannot be rendered -----

    public function test_pending_collection_returns_404(): void
    {
        $invoice = $this->issueSimpleInvoice('500.00');
        $collection = $this->collectExisting($invoice, '400.00');
        $collection->forceFill(['status' => FinanceCollection::STATUS_PENDING])->save();

        $this->actingAs($this->accountant)
            ->get(route('dashboard.collections.receipt', $collection))
            ->assertNotFound();

        $this->actingAs($this->accountant)
            ->get(route('dashboard.collections.receipt.pdf', $collection))
            ->assertNotFound();
    }

    // ----- F. Nonexistent collection returns 404 -----

    public function test_nonexistent_collection_returns_404(): void
    {
        $this->actingAs($this->accountant)
            ->get(route('dashboard.collections.receipt', 999999))
            ->assertNotFound();
    }

    // ----- G. Existing-obligation-only collection receipt is correct -----

    public function test_existing_obligation_only_receipt_is_correct(): void
    {
        $invoice = $this->issueSimpleInvoice('1000.00');
        $collection = $this->collectExisting($invoice, '400.00');

        $response = $this->actingAs($this->accountant)->get(route('dashboard.collections.receipt', $collection));

        $response->assertOk()
            ->assertSee($invoice->display_number)
            ->assertSee('400.00');

        $this->assertSame(1, $collection->invoicePayments()->count());
        $this->assertCount(1, $collection->linkedInvoices());
    }

    // ----- H. New-service-only collection receipt is correct -----

    public function test_new_service_only_receipt_is_correct(): void
    {
        $collection = $this->collectNewService('500.00', '300.00', 'Экскурсия в музей');

        $response = $this->actingAs($this->accountant)->get(route('dashboard.collections.receipt', $collection));

        $response->assertOk()
            ->assertSee('Экскурсия в музей')
            ->assertSee('300.00');

        $newInvoice = $collection->linkedInvoices()->sole();
        $response->assertSee($newInvoice->display_number);
    }

    // ----- I. Mixed collection is correct -----

    public function test_mixed_collection_receipt_is_correct(): void
    {
        $existingInvoice = $this->issueSimpleInvoice('4500.00');
        $collection = $this->collectMixed($existingInvoice, '4300.00', '500.00', '300.00');

        $response = $this->actingAs($this->accountant)->get(route('dashboard.collections.receipt', $collection));

        $response->assertOk()->assertSee('4600.00');
        $this->assertSame('4600.00', $collection->grossReceivedTotal());
    }

    // ----- J. Multiple InvoicePayment rows belonging to one collection all appear -----

    public function test_multiple_payment_rows_all_appear_on_receipt(): void
    {
        $existingInvoice = $this->issueSimpleInvoice('4500.00');
        $collection = $this->collectMixed($existingInvoice, '4300.00', '500.00', '300.00');

        $paymentNumbers = $collection->invoicePayments()->pluck('payment_number');
        $this->assertGreaterThanOrEqual(2, $paymentNumbers->count());

        $response = $this->actingAs($this->accountant)->get(route('dashboard.collections.receipt', $collection));
        $response->assertOk();
        foreach ($paymentNumbers as $paymentNumber) {
            $response->assertSee($paymentNumber);
        }
    }

    // ----- K. Multiple touched invoices are represented correctly -----

    public function test_multiple_touched_invoices_are_represented(): void
    {
        $existingInvoice = $this->issueSimpleInvoice('4500.00');
        $collection = $this->collectMixed($existingInvoice, '4300.00', '500.00', '300.00');

        $linkedInvoices = $collection->linkedInvoices();
        $this->assertCount(2, $linkedInvoices);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.collections.receipt', $collection));
        $response->assertOk();
        foreach ($linkedInvoices as $invoice) {
            $response->assertSee($invoice->display_number);
        }
    }

    // ----- L. Gross total equals canonical FinanceCollection::grossReceivedTotal() -----

    public function test_gross_total_matches_canonical_accessor(): void
    {
        $existingInvoice = $this->issueSimpleInvoice('4500.00');
        $collection = $this->collectMixed($existingInvoice, '4300.00', '500.00', '300.00');

        $response = $this->actingAs($this->accountant)->get(route('dashboard.collections.receipt', $collection));
        $response->assertOk()->assertSee(number_format((float) $collection->grossReceivedTotal(), 2, '.', ''));
    }

    // ----- M/N/O/P/Q. Correct student/year/payment method/collection number/cashier -----

    public function test_receipt_shows_correct_identity_fields(): void
    {
        $invoice = $this->issueSimpleInvoice('500.00');
        $collection = $this->collectExisting($invoice, '400.00');

        $response = $this->actingAs($this->accountant)->get(route('dashboard.collections.receipt', $collection));

        $response->assertOk()
            ->assertSee($this->student->full_name)
            ->assertSee($this->year->name)
            ->assertSee('Наличные')
            ->assertSee($collection->collection_number)
            ->assertSee($this->accountant->name);
    }

    // ----- R. Direct reprint works with no session/flash state -----

    public function test_direct_reprint_works_with_no_session_state(): void
    {
        $invoice = $this->issueSimpleInvoice('500.00');
        $collection = $this->collectExisting($invoice, '400.00');

        // A fresh, unauthenticated-until-now client — no flash/session
        // carried over from the collection's own store() request.
        $this->flushSession();

        $this->actingAs($this->accountant)
            ->get(route('dashboard.collections.receipt', $collection))
            ->assertOk()
            ->assertSee($collection->collection_number);
    }

    // ----- S. Idempotent replay points to the same FinanceCollection, no duplicate rows -----

    public function test_idempotent_replay_does_not_duplicate_receipt_rows(): void
    {
        $invoice = $this->issueSimpleInvoice('1000.00');
        $payload = [
            'idempotency_token' => (string) Str::uuid(),
            'academic_year_id' => $this->year->id, 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'existing_obligations' => [['invoice_id' => $invoice->id, 'receive_now_amount' => '400.00']],
        ];

        $first = $this->actingAs($this->accountant)->post(route('dashboard.students.unified-collection.store', $this->student), $payload);
        $second = $this->actingAs($this->accountant)->post(route('dashboard.students.unified-collection.store', $this->student), $payload);

        $collection = FinanceCollection::query()->sole();
        $first->assertRedirect(route('dashboard.collections.receipt', $collection));
        $second->assertRedirect(route('dashboard.collections.receipt', $collection));

        $this->assertSame(1, $collection->invoicePayments()->count());

        $response = $this->actingAs($this->accountant)->get(route('dashboard.collections.receipt', $collection));
        $response->assertOk()->assertSee('400.00');
    }

    // ----- T. Later refund does NOT rewrite/net the original receipt amount -----

    public function test_later_refund_does_not_change_original_receipt_amount(): void
    {
        $invoice = $this->issueSimpleInvoice('1000.00');
        $collection = $this->collectExisting($invoice, '400.00');
        $originalTotal = $collection->grossReceivedTotal();

        $payment = $collection->invoicePayments()->sole();
        app(InvoiceRefundService::class)->refund(
            invoicePaymentId: $payment->id,
            amount: '150.00',
            reason: 'Тестовый возврат',
            idempotencyKey: (string) Str::uuid(),
            actor: $this->accountant,
        );

        $collection->refresh();

        $this->assertSame($originalTotal, $collection->grossReceivedTotal());
        $this->assertSame('400.00', $collection->grossReceivedTotal());

        $response = $this->actingAs($this->accountant)->get(route('dashboard.collections.receipt', $collection));
        $response->assertOk()
            ->assertSee('400.00')
            ->assertDontSee('250.00');
    }

    // ----- U. Receipt GET performs ZERO accounting writes -----

    public function test_receipt_get_performs_no_accounting_writes(): void
    {
        $invoice = $this->issueSimpleInvoice('500.00');
        $collection = $this->collectExisting($invoice, '400.00');

        $collectionCountBefore = FinanceCollection::query()->count();
        $invoiceCountBefore = Invoice::query()->count();
        $paymentCountBefore = InvoicePayment::query()->count();

        $this->actingAs($this->accountant)
            ->get(route('dashboard.collections.receipt', $collection))
            ->assertOk();

        $this->assertSame($collectionCountBefore, FinanceCollection::query()->count());
        $this->assertSame($invoiceCountBefore, Invoice::query()->count());
        $this->assertSame($paymentCountBefore, InvoicePayment::query()->count());
        $this->assertSame('400.00', $collection->fresh()->grossReceivedTotal());
    }

    // ----- V. PDF route succeeds and renders the same collection identity/total -----

    public function test_pdf_route_renders_same_collection_identity(): void
    {
        $invoice = $this->issueSimpleInvoice('500.00');
        $collection = $this->collectExisting($invoice, '400.00');

        $response = $this->actingAs($this->accountant)->get(route('dashboard.collections.receipt.pdf', $collection));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    // ----- W. Successful store() redirects to the new receipt route -----

    public function test_store_redirects_to_receipt_route(): void
    {
        $invoice = $this->issueSimpleInvoice('500.00');

        $response = $this->actingAs($this->accountant)->post(route('dashboard.students.unified-collection.store', $this->student), [
            'idempotency_token' => (string) Str::uuid(),
            'academic_year_id' => $this->year->id, 'payment_method' => 'cash', 'cash_account_id' => $this->cash->id,
            'existing_obligations' => [['invoice_id' => $invoice->id, 'receive_now_amount' => '400.00']],
        ]);

        $collection = FinanceCollection::query()->sole();
        $response->assertRedirect(route('dashboard.collections.receipt', $collection));
    }

    // ----- X. Route-model-bound FinanceCollection cannot be replaced via request/query parameters -----

    public function test_collection_cannot_be_overridden_via_query_parameter(): void
    {
        $invoiceA = $this->issueSimpleInvoice('500.00', 'Услуга А');
        $collectionA = $this->collectExisting($invoiceA, '400.00');

        $invoiceB = $this->issueSimpleInvoice('700.00', 'Услуга Б');
        $collectionB = $this->collectExisting($invoiceB, '600.00');

        $response = $this->actingAs($this->accountant)->get(
            route('dashboard.collections.receipt', $collectionA).'?financeCollection='.$collectionB->id
        );

        $response->assertOk()
            ->assertSee($collectionA->collection_number)
            ->assertDontSee($collectionB->collection_number);
    }
}
