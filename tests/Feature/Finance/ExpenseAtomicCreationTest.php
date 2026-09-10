<?php

namespace Tests\Feature\Finance;

use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Models\CashAccount;
use App\Models\CashSession;
use App\Models\CashTransaction;
use App\Models\Expense;
use App\Services\Finance\CashSessionService;
use App\Services\Finance\ExpenseService;
use Livewire\Livewire;
use RuntimeException;

/**
 * Proves the atomic-create corrective pass: a default/immediately-paid
 * Expense and its CashTransaction either both commit or neither does, for
 * both of Expenses V1's supported creation entry points (ExpenseService and
 * the Filament CreateExpense page, which now delegates to it).
 */
class ExpenseAtomicCreationTest extends FinanceOperationsTestCase
{
    private function bindBrokenCashSessionService(): void
    {
        $this->app->bind(CashSessionService::class, function () {
            return new class extends CashSessionService
            {
                public function __construct() {}

                public function activeFor(CashAccount $account, bool $lock = false): ?CashSession
                {
                    throw new RuntimeException('Simulated unexpected ledger-posting failure.');
                }
            };
        });
    }

    private function repairCashSessionService(): void
    {
        $this->app->bind(CashSessionService::class, fn () => new CashSessionService());
    }

    public function test_service_create_rolls_back_entirely_on_forced_ledger_failure(): void
    {
        $balanceBefore = (string) $this->cash->fresh()->balance;
        $transactionCountBefore = CashTransaction::query()->count();
        $this->bindBrokenCashSessionService();

        $threw = false;
        try {
            app(ExpenseService::class)->create([
                'title' => 'Atomic Probe A', 'amount' => '77.00', 'category' => 'other',
                'expense_date' => today()->toDateString(), 'cash_account_id' => $this->cash->id,
            ]);
        } catch (RuntimeException $exception) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected the simulated failure to propagate.');
        $this->assertDatabaseMissing('expenses', ['title' => 'Atomic Probe A']);
        $this->assertSame($transactionCountBefore, CashTransaction::query()->count(), 'No CashTransaction was left behind.');
        $this->assertSame($balanceBefore, (string) $this->cash->fresh()->balance, 'Cash balance is unchanged.');
    }

    public function test_filament_create_rolls_back_entirely_on_forced_ledger_failure(): void
    {
        $balanceBefore = (string) $this->cash->fresh()->balance;
        $transactionCountBefore = CashTransaction::query()->count();
        $this->bindBrokenCashSessionService();

        $this->actingAs($this->accountant);

        $caught = null;
        try {
            Livewire::test(CreateExpense::class)
                ->fillForm([
                    'title' => 'Atomic Probe B',
                    'amount' => '88.00',
                    'expense_date' => today()->toDateString(),
                    'cash_account_id' => $this->cash->id,
                    'status' => Expense::STATUS_PAID,
                ])
                ->call('create');
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught, 'Expected the simulated failure to propagate out of the Livewire action.');
        $this->assertDatabaseMissing('expenses', ['title' => 'Atomic Probe B']);
        $this->assertSame($transactionCountBefore, CashTransaction::query()->count(), 'No CashTransaction was left behind.');
        $this->assertSame($balanceBefore, (string) $this->cash->fresh()->balance, 'Cash balance is unchanged.');
    }

    public function test_service_create_succeeds_atomically_for_default_paid_expense(): void
    {
        $balanceBefore = (string) $this->cash->fresh()->balance;

        $expense = app(ExpenseService::class)->create([
            'title' => 'Atomic Probe C', 'amount' => '55.00', 'category' => 'other',
            'expense_date' => today()->toDateString(), 'cash_account_id' => $this->cash->id,
        ], $this->accountant);

        $this->assertSame(Expense::STATUS_PAID, $expense->status);
        $this->assertSame($this->accountant->id, $expense->created_by);
        $transaction = CashTransaction::query()->where('expense_id', $expense->id)->firstOrFail();
        $this->assertSame(1, CashTransaction::query()->where('expense_id', $expense->id)->count());
        $this->assertSame($this->cashSession->id, $transaction->cash_session_id);
        $this->assertSame(bcsub($balanceBefore, '55.00', 2), (string) $this->cash->fresh()->balance);
    }

    public function test_service_create_draft_has_no_ledger_impact(): void
    {
        $balanceBefore = (string) $this->cash->fresh()->balance;

        $expense = app(ExpenseService::class)->create([
            'title' => 'Atomic Probe D', 'amount' => '33.00', 'category' => 'other',
            'expense_date' => today()->toDateString(), 'cash_account_id' => $this->cash->id,
            'status' => Expense::STATUS_DRAFT,
        ], $this->accountant);

        $this->assertTrue($expense->isDraft());
        $this->assertSame(0, CashTransaction::query()->where('expense_id', $expense->id)->count());
        $this->assertSame($balanceBefore, (string) $this->cash->fresh()->balance);
    }

    public function test_service_create_approved_has_no_ledger_impact(): void
    {
        $expense = app(ExpenseService::class)->create([
            'title' => 'Atomic Probe E', 'amount' => '40.00', 'category' => 'other',
            'expense_date' => today()->toDateString(), 'cash_account_id' => $this->cash->id,
            'status' => Expense::STATUS_APPROVED,
        ], $this->accountant);

        $this->assertTrue($expense->isApproved());
        $this->assertSame(0, CashTransaction::query()->where('expense_id', $expense->id)->count());
    }

    public function test_draft_approved_paid_via_service_still_posts_exactly_once(): void
    {
        $expense = app(ExpenseService::class)->create([
            'title' => 'Atomic Probe F', 'amount' => '60.00', 'category' => 'other',
            'expense_date' => today()->toDateString(), 'cash_account_id' => $this->cash->id,
            'status' => Expense::STATUS_DRAFT,
        ], $this->accountant);

        app(ExpenseService::class)->approve($expense, $this->accountant);
        app(ExpenseService::class)->pay($expense, $this->accountant);

        $this->assertSame(1, CashTransaction::query()->where('expense_id', $expense->id)->count());
    }

    public function test_repeated_pay_after_service_create_still_posts_exactly_once(): void
    {
        $expense = app(ExpenseService::class)->create([
            'title' => 'Atomic Probe G', 'amount' => '15.00', 'category' => 'other',
            'expense_date' => today()->toDateString(), 'cash_account_id' => $this->cash->id,
            'status' => Expense::STATUS_DRAFT,
        ], $this->accountant);
        app(ExpenseService::class)->approve($expense, $this->accountant);

        app(ExpenseService::class)->pay($expense, $this->accountant);
        app(ExpenseService::class)->pay($expense, $this->accountant);
        app(ExpenseService::class)->pay($expense, $this->accountant);

        $this->assertSame(1, CashTransaction::query()->where('expense_id', $expense->id)->count());
    }

    public function test_raw_legacy_expense_create_still_works_unchanged(): void
    {
        $balanceBefore = (string) $this->cash->fresh()->balance;

        $expense = Expense::create([
            'title' => 'Atomic Probe H (legacy raw)', 'amount' => '25.00', 'category' => 'other',
            'expense_date' => today()->toDateString(), 'cash_account_id' => $this->cash->id,
        ]);

        $this->assertSame(Expense::STATUS_PAID, $expense->status);
        $this->assertSame(1, CashTransaction::query()->where('expense_id', $expense->id)->count());
        $this->assertSame(bcsub($balanceBefore, '25.00', 2), (string) $this->cash->fresh()->balance);
    }

    /**
     * Architectural safeguard: the only place app/ code may call
     * Expense::create() directly is ExpenseService::create() itself — every
     * other application entry point (currently just the Filament
     * CreateExpense page) must go through the service for the atomicity
     * guarantee to hold. This fails loudly if a future change reintroduces
     * a bypassing call site.
     */
    public function test_no_application_code_bypasses_the_expense_service_for_creation(): void
    {
        $offendingFiles = [];

        foreach ((new \Symfony\Component\Finder\Finder())->files()->in(app_path())->name('*.php') as $file) {
            $path = $file->getRealPath();
            if (str_ends_with($path, 'ExpenseService.php')) {
                continue;
            }
            $code = '';
            foreach (token_get_all(file_get_contents($path)) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }
            if (preg_match('/Expense::create\s*\(/', $code) || preg_match('/new\s+Expense\s*\(/', $code)) {
                $offendingFiles[] = str_replace(app_path().'/', '', $path);
            }
        }

        $this->assertSame([], $offendingFiles, 'Found application code creating an Expense outside ExpenseService: '.implode(', ', $offendingFiles));
    }

    protected function tearDown(): void
    {
        $this->repairCashSessionService();
        parent::tearDown();
    }
}
