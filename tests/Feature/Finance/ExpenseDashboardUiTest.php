<?php

namespace Tests\Feature\Finance;

use App\Models\CashTransaction;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payee;
use App\Services\Finance\ExpenseService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Corrective pass: proves the dashboard-native Expenses V1 pages
 * (ExpenseController / ExpenseCategoryController / PayeeController) exist,
 * enforce the same permissions as the Filament resources they replace on
 * the sidebar, and delegate every workflow mutation to the canonical
 * ExpenseService rather than duplicating any status-transition rule.
 */
class ExpenseDashboardUiTest extends FinanceOperationsTestCase
{
    private function draftExpense(array $overrides = []): Expense
    {
        return app(ExpenseService::class)->create(array_merge([
            'title' => 'Канцелярия',
            'amount' => '300.00',
            'expense_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
            'status' => Expense::STATUS_DRAFT,
        ], $overrides), $this->accountant);
    }

    private function paidExpense(array $overrides = []): Expense
    {
        return app(ExpenseService::class)->create(array_merge([
            'title' => 'Топливо',
            'amount' => '200.00',
            'expense_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
        ], $overrides), $this->accountant);
    }

    // 1. Index access with permission.
    public function test_user_with_manage_expenses_can_access_dashboard_expense_index(): void
    {
        $user = $this->user('reception');
        $user->givePermissionTo('manage expenses');

        $this->actingAs($user)
            ->get(route('dashboard.finance.expenses.index'))
            ->assertOk()
            ->assertViewIs('dashboard.finance.expenses.index');
    }

    // 2. Index access without permission is refused.
    public function test_user_without_permission_gets_403_on_expense_index(): void
    {
        $user = $this->user('reception');

        $this->actingAs($user)
            ->get(route('dashboard.finance.expenses.index'))
            ->assertForbidden();
    }

    // 5. Dashboard create delegates to ExpenseService and creates an Expense.
    public function test_dashboard_create_draft_persists_via_expense_service(): void
    {
        $user = $this->user('reception');
        $user->givePermissionTo('manage expenses');

        $response = $this->actingAs($user)->post(route('dashboard.finance.expenses.store'), [
            'title' => 'Бумага для принтера',
            'amount' => '150.00',
            'currency' => 'EGP',
            'expense_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
            'status' => Expense::STATUS_DRAFT,
        ]);

        $expense = Expense::where('title', 'Бумага для принтера')->firstOrFail();
        $response->assertRedirect(route('dashboard.finance.expenses.show', $expense));
        $this->assertTrue($expense->isDraft());
        $this->assertSame($user->id, $expense->created_by);
        $this->assertDatabaseMissing('cash_transactions', ['expense_id' => $expense->id]);
    }

    public function test_dashboard_create_paid_posts_exactly_one_cash_transaction(): void
    {
        $user = $this->user('reception');
        $user->givePermissionTo('manage expenses');

        $this->actingAs($user)->post(route('dashboard.finance.expenses.store'), [
            'title' => 'Уборка офиса',
            'amount' => '90.00',
            'currency' => 'EGP',
            'expense_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
            'status' => Expense::STATUS_PAID,
        ])->assertRedirect();

        $expense = Expense::where('title', 'Уборка офиса')->firstOrFail();
        $this->assertTrue($expense->isPaid());
        $this->assertSame(1, CashTransaction::query()->where('expense_id', $expense->id)->count());
    }

    // Create must reject approved/void — only draft/paid supported on the dashboard form.
    public function test_dashboard_create_rejects_approved_status(): void
    {
        $user = $this->user('reception');
        $user->givePermissionTo('manage expenses');

        $this->actingAs($user)->post(route('dashboard.finance.expenses.store'), [
            'title' => 'Недопустимый статус',
            'amount' => '10.00',
            'currency' => 'EGP',
            'expense_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
            'status' => Expense::STATUS_APPROVED,
        ])->assertSessionHasErrors('status');

        $this->assertDatabaseMissing('expenses', ['title' => 'Недопустимый статус']);
    }

    // 6. Unauthorized create cannot create an Expense.
    public function test_unauthorized_user_cannot_create_expense_via_dashboard(): void
    {
        $user = $this->user('reception');
        $countBefore = Expense::query()->count();

        $this->actingAs($user)->post(route('dashboard.finance.expenses.store'), [
            'title' => 'Попытка без прав',
            'amount' => '10.00',
            'currency' => 'EGP',
            'expense_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
            'status' => Expense::STATUS_DRAFT,
        ])->assertForbidden();

        $this->assertSame($countBefore, Expense::query()->count());
    }

    // 7. Draft approve honors 'approve expenses'.
    public function test_draft_approve_action_honors_approve_expenses_permission(): void
    {
        $expense = $this->draftExpense();
        $approver = $this->user('reception');
        $approver->givePermissionTo(['manage expenses', 'approve expenses']);

        $this->actingAs($approver)
            ->post(route('dashboard.finance.expenses.approve', $expense))
            ->assertRedirect();

        $this->assertTrue($expense->fresh()->isApproved());
    }

    public function test_approve_action_without_permission_is_forbidden(): void
    {
        $expense = $this->draftExpense();
        $user = $this->user('reception');
        $user->givePermissionTo('manage expenses');

        $this->actingAs($user)
            ->post(route('dashboard.finance.expenses.approve', $expense))
            ->assertForbidden();

        $this->assertTrue($expense->fresh()->isDraft());
    }

    // 8. Approved pay honors 'post expenses' and produces exactly one CashTransaction.
    public function test_approved_pay_action_honors_post_expenses_permission(): void
    {
        $expense = $this->draftExpense();
        app(ExpenseService::class)->approve($expense, $this->accountant);

        $payer = $this->user('reception');
        $payer->givePermissionTo(['manage expenses', 'post expenses']);

        $this->actingAs($payer)
            ->post(route('dashboard.finance.expenses.pay', $expense))
            ->assertRedirect();

        $this->assertTrue($expense->fresh()->isPaid());
        $this->assertSame(1, CashTransaction::query()->where('expense_id', $expense->id)->count());
    }

    public function test_pay_action_without_permission_is_forbidden(): void
    {
        $expense = $this->draftExpense();
        app(ExpenseService::class)->approve($expense, $this->accountant);
        $user = $this->user('reception');
        $user->givePermissionTo('manage expenses');

        $this->actingAs($user)
            ->post(route('dashboard.finance.expenses.pay', $expense))
            ->assertForbidden();

        $this->assertSame(0, CashTransaction::query()->where('expense_id', $expense->id)->count());
    }

    // 9. Void requires permission and a non-empty reason.
    public function test_void_requires_permission(): void
    {
        $expense = $this->draftExpense();
        $user = $this->user('reception');
        $user->givePermissionTo('manage expenses');

        $this->actingAs($user)
            ->post(route('dashboard.finance.expenses.void', $expense), ['void_reason' => 'Дубликат'])
            ->assertForbidden();

        $this->assertTrue($expense->fresh()->isDraft());
    }

    public function test_void_requires_non_empty_reason(): void
    {
        $expense = $this->draftExpense();
        $voider = $this->user('reception');
        $voider->givePermissionTo(['manage expenses', 'void expenses']);

        $this->actingAs($voider)
            ->post(route('dashboard.finance.expenses.void', $expense), ['void_reason' => ''])
            ->assertSessionHasErrors('void_reason');

        $this->assertTrue($expense->fresh()->isDraft());
    }

    public function test_void_with_reason_and_permission_succeeds(): void
    {
        $expense = $this->draftExpense();
        $voider = $this->user('reception');
        $voider->givePermissionTo(['manage expenses', 'void expenses']);

        $this->actingAs($voider)
            ->post(route('dashboard.finance.expenses.void', $expense), ['void_reason' => 'Дубликат записи'])
            ->assertRedirect();

        $fresh = $expense->fresh();
        $this->assertTrue($fresh->isVoid());
        $this->assertSame('Дубликат записи', $fresh->void_reason);
    }

    // 10. Paid expense cannot be edited or voided through the dashboard.
    public function test_paid_expense_cannot_be_edited_through_dashboard(): void
    {
        $expense = $this->paidExpense();
        $user = $this->user('reception');
        $user->givePermissionTo('manage expenses');

        $this->actingAs($user)
            ->get(route('dashboard.finance.expenses.edit', $expense))
            ->assertForbidden();

        $this->actingAs($user)
            ->put(route('dashboard.finance.expenses.update', $expense), [
                'title' => 'Изменено',
                'amount' => '999.00',
                'currency' => 'EGP',
                'expense_date' => today()->toDateString(),
                'cash_account_id' => $this->cash->id,
            ])
            ->assertForbidden();

        $this->assertSame('Топливо', $expense->fresh()->title);
    }

    public function test_paid_expense_cannot_be_voided_through_dashboard(): void
    {
        $expense = $this->paidExpense();
        $voider = $this->user('reception');
        $voider->givePermissionTo(['manage expenses', 'void expenses']);

        $this->actingAs($voider)
            ->post(route('dashboard.finance.expenses.void', $expense), ['void_reason' => 'Попытка'])
            ->assertForbidden();

        $this->assertTrue($expense->fresh()->isPaid());
    }

    // Draft metadata editing remains supported for an authorized actor.
    public function test_draft_expense_metadata_can_be_edited_by_authorized_user(): void
    {
        $expense = $this->draftExpense();
        $user = $this->user('reception');
        $user->givePermissionTo('manage expenses');

        $this->actingAs($user)
            ->put(route('dashboard.finance.expenses.update', $expense), [
                'title' => 'Обновлённое название',
                'amount' => '350.00',
                'currency' => 'EGP',
                'expense_date' => today()->toDateString(),
                'cash_account_id' => $this->cash->id,
            ])
            ->assertRedirect(route('dashboard.finance.expenses.show', $expense));

        $fresh = $expense->fresh();
        $this->assertSame('Обновлённое название', $fresh->title);
        $this->assertTrue($fresh->isDraft(), 'Editing metadata must never change workflow status.');
    }

    // 11. Category/payee pages require 'manage expenses'.
    public function test_category_pages_require_manage_expenses(): void
    {
        $noPermission = $this->user('reception');
        $this->actingAs($noPermission)
            ->get(route('dashboard.finance.expense-categories.index'))
            ->assertForbidden();

        $authorized = $this->user('reception');
        $authorized->givePermissionTo('manage expenses');
        $this->actingAs($authorized)
            ->get(route('dashboard.finance.expense-categories.index'))
            ->assertOk()
            ->assertViewIs('dashboard.finance.expense-categories.index');
    }

    public function test_payee_pages_require_manage_expenses(): void
    {
        $noPermission = $this->user('reception');
        $this->actingAs($noPermission)
            ->get(route('dashboard.finance.payees.index'))
            ->assertForbidden();

        $authorized = $this->user('reception');
        $authorized->givePermissionTo('manage expenses');
        $this->actingAs($authorized)
            ->get(route('dashboard.finance.payees.index'))
            ->assertOk()
            ->assertViewIs('dashboard.finance.payees.index');
    }

    public function test_category_create_store_and_edit_flow(): void
    {
        $user = $this->user('reception');
        $user->givePermissionTo('manage expenses');

        $this->actingAs($user)->post(route('dashboard.finance.expense-categories.store'), [
            'name' => 'Хознужды',
            'is_active' => '1',
        ])->assertRedirect(route('dashboard.finance.expense-categories.index'));

        $category = ExpenseCategory::where('name', 'Хознужды')->firstOrFail();

        $this->actingAs($user)->put(route('dashboard.finance.expense-categories.update', $category), [
            'name' => 'Хознужды 2',
            'is_active' => '0',
        ])->assertRedirect(route('dashboard.finance.expense-categories.index'));

        $fresh = $category->fresh();
        $this->assertSame('Хознужды 2', $fresh->name);
        $this->assertFalse($fresh->is_active);
    }

    public function test_payee_create_store_and_edit_flow(): void
    {
        $user = $this->user('reception');
        $user->givePermissionTo('manage expenses');

        $this->actingAs($user)->post(route('dashboard.finance.payees.store'), [
            'name' => 'ООО Поставщик',
            'phone' => '+201001112233',
            'is_active' => '1',
        ])->assertRedirect(route('dashboard.finance.payees.index'));

        $payee = Payee::where('name', 'ООО Поставщик')->firstOrFail();

        $this->actingAs($user)->put(route('dashboard.finance.payees.update', $payee), [
            'name' => 'ООО Поставщик',
            'phone' => '+201001112233',
            'is_active' => '0',
        ])->assertRedirect(route('dashboard.finance.payees.index'));

        $this->assertFalse($payee->fresh()->is_active);
    }

    // 12. Private attachment behaviour remains authorized/private.
    public function test_attachment_download_requires_authorization(): void
    {
        $disk = config('filesystems.uploads.private');
        Storage::fake($disk);
        Storage::disk($disk)->put('expenses/receipt.pdf', 'fake-pdf-contents');

        $expense = $this->draftExpense(['attachment_path' => 'expenses/receipt.pdf']);

        $noPermission = $this->user('reception');
        $this->actingAs($noPermission)
            ->get(route('dashboard.finance.expenses.attachment', $expense))
            ->assertForbidden();

        $authorized = $this->user('reception');
        $authorized->givePermissionTo('manage expenses');
        $this->actingAs($authorized)
            ->get(route('dashboard.finance.expenses.attachment', $expense))
            ->assertOk()
            ->assertHeader('Content-Disposition');
    }

    public function test_create_stores_uploaded_attachment_on_private_disk(): void
    {
        $disk = config('filesystems.uploads.private');
        Storage::fake($disk);

        $user = $this->user('reception');
        $user->givePermissionTo('manage expenses');

        $this->actingAs($user)->post(route('dashboard.finance.expenses.store'), [
            'title' => 'С вложением',
            'amount' => '77.00',
            'currency' => 'EGP',
            'expense_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
            'status' => Expense::STATUS_DRAFT,
            'attachment' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
        ])->assertRedirect();

        $expense = Expense::where('title', 'С вложением')->firstOrFail();
        $this->assertNotNull($expense->attachment_path);
        $this->assertStringStartsWith('expenses/', $expense->attachment_path);
        Storage::disk($disk)->assertExists($expense->attachment_path);
        $this->assertNotNull($expense->attachment_size);
    }

    // 13. Dashboard pages render inside layouts.dashboard (the unified shell), not a bare/Filament page.
    public function test_expense_pages_render_inside_unified_dashboard_shell(): void
    {
        $user = $this->user('reception');
        $user->givePermissionTo('manage expenses');
        $expense = $this->draftExpense();

        foreach ([
            route('dashboard.finance.expenses.index'),
            route('dashboard.finance.expenses.create'),
            route('dashboard.finance.expenses.show', $expense),
            route('dashboard.finance.expense-categories.index'),
            route('dashboard.finance.payees.index'),
        ] as $url) {
            $this->actingAs($user)->get($url)
                ->assertOk()
                ->assertSee('ui2-shell', false)
                ->assertSee('ui2-sidebar', false);
        }
    }
}
