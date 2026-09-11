<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\CashSession;
use App\Models\CashTransaction;
use App\Models\RevenueCategory;
use App\Models\RevenueEntry;
use App\Services\Finance\CashSessionService;
use App\Services\Finance\RevenueService;
use RuntimeException;

/**
 * Proves RevenueEntry's create/post/reverse operations are each fully
 * atomic: either every write commits (RevenueEntry + CashTransaction +
 * CashAccount balance + AuditLog) or none of it does.
 */
class RevenueAtomicCreationTest extends FinanceOperationsTestCase
{
    private RevenueCategory $cafeteria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cafeteria = RevenueCategory::firstOrCreate(['code' => RevenueCategory::CODE_CAFETERIA], ['name_ru' => 'Кафетерий', 'is_active' => true]);
    }

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

    protected function tearDown(): void
    {
        $this->repairCashSessionService();
        parent::tearDown();
    }

    public function test_service_create_rolls_back_entirely_on_forced_ledger_failure(): void
    {
        $balanceBefore = (string) $this->cash->fresh()->balance;
        $transactionCountBefore = CashTransaction::query()->count();
        $this->bindBrokenCashSessionService();

        $threw = false;
        try {
            app(RevenueService::class)->create([
                'revenue_category_id' => $this->cafeteria->id,
                'amount' => '4850.00',
                'revenue_date' => today()->toDateString(),
                'cash_account_id' => $this->cash->id,
                'payment_method' => 'cash',
                'status' => RevenueEntry::STATUS_POSTED,
            ], $this->accountant);
        } catch (RuntimeException $exception) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected the simulated failure to propagate.');
        $this->assertSame(0, RevenueEntry::query()->count(), 'No RevenueEntry was left behind.');
        $this->assertSame($transactionCountBefore, CashTransaction::query()->count(), 'No CashTransaction was left behind.');
        $this->assertSame($balanceBefore, (string) $this->cash->fresh()->balance, 'Cash balance is unchanged.');
    }

    // Non-Tuition Revenues V1 integration: the parked branch's Filament
    // CreateRevenueEntry page is deliberately not ported this pass
    // (dashboard-native only). The equivalent "atomic rollback via the
    // real HTTP entry point" coverage now lives in
    // RevenueEntryControllerTest against the dashboard-native store()
    // action instead of a Livewire/Filament page.

    public function test_post_rolls_back_entirely_on_forced_ledger_failure(): void
    {
        $entry = app(RevenueService::class)->create([
            'revenue_category_id' => $this->cafeteria->id,
            'amount' => '4850.00',
            'revenue_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
            'payment_method' => 'cash',
        ], $this->accountant);
        $balanceBefore = (string) $this->cash->fresh()->balance;
        $this->bindBrokenCashSessionService();

        $threw = false;
        try {
            app(RevenueService::class)->post($entry, $this->accountant);
        } catch (RuntimeException $exception) {
            $threw = true;
        }

        $this->assertTrue($threw);
        $this->assertTrue($entry->fresh()->isDraft(), 'Status did not change — rolled back.');
        $this->assertSame(0, CashTransaction::query()->where('revenue_entry_id', $entry->id)->count());
        $this->assertSame($balanceBefore, (string) $this->cash->fresh()->balance);
    }

    public function test_reversal_rolls_back_entirely_on_forced_failure(): void
    {
        $entry = app(RevenueService::class)->create([
            'revenue_category_id' => $this->cafeteria->id,
            'amount' => '4850.00',
            'revenue_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
            'payment_method' => 'cash',
            'status' => RevenueEntry::STATUS_POSTED,
        ], $this->accountant);
        $balanceAfterPost = (string) $this->cash->fresh()->balance;
        $this->bindBrokenCashSessionService();

        $threw = false;
        try {
            app(RevenueService::class)->reverse($entry, $this->accountant, 'Ошибка');
        } catch (RuntimeException $exception) {
            $threw = true;
        }

        $this->assertTrue($threw);
        $this->assertTrue($entry->fresh()->isPosted(), 'Status stayed posted — the reversal rolled back, not just the transaction.');
        $this->assertNull($entry->fresh()->reversed_at);
        $this->assertSame(0, CashTransaction::query()->where('reversed_revenue_entry_id', $entry->id)->count());
        $this->assertSame($balanceAfterPost, (string) $this->cash->fresh()->balance, 'Balance still reflects the (unreversed) original posting.');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'revenue_reversed', 'model_id' => $entry->id]);
    }

    /**
     * Architectural safeguard: the only place app/ code may call
     * RevenueEntry::create() directly is RevenueService::create() itself.
     */
    public function test_no_application_code_bypasses_the_revenue_service_for_creation(): void
    {
        $offendingFiles = [];

        foreach ((new \Symfony\Component\Finder\Finder())->files()->in(app_path())->name('*.php') as $file) {
            $path = $file->getRealPath();
            if (str_ends_with($path, 'RevenueService.php')) {
                continue;
            }
            $code = '';
            foreach (token_get_all(file_get_contents($path)) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }
            if (preg_match('/RevenueEntry::create\s*\(/', $code) || preg_match('/new\s+RevenueEntry\s*\(/', $code)) {
                $offendingFiles[] = str_replace(app_path().'/', '', $path);
            }
        }

        $this->assertSame([], $offendingFiles, 'Found application code creating a RevenueEntry outside RevenueService: '.implode(', ', $offendingFiles));
    }
}
