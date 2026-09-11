<?php

namespace Tests\Feature\Finance;

use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\RevenueCategory;
use App\Models\RevenueEntry;
use App\Models\Student;
use App\Services\Finance\RevenueService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RevenueEntryWorkflowTest extends FinanceOperationsTestCase
{
    private RevenueCategory $cafeteria;

    private RevenueCategory $donation;

    private RevenueCategory $fine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cafeteria = RevenueCategory::firstOrCreate(['code' => RevenueCategory::CODE_CAFETERIA], ['name_ru' => 'Кафетерий', 'is_active' => true]);
        $this->donation = RevenueCategory::firstOrCreate(['code' => RevenueCategory::CODE_DONATION], ['name_ru' => 'Пожертвования', 'is_active' => true]);
        $this->fine = RevenueCategory::firstOrCreate(['code' => RevenueCategory::CODE_FINE], ['name_ru' => 'Штрафы', 'is_active' => true]);
    }

    private function draftPayload(array $overrides = []): array
    {
        return array_merge([
            'revenue_category_id' => $this->cafeteria->id,
            'amount' => '4850.00',
            'revenue_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
            'payment_method' => 'cash',
        ], $overrides);
    }

    // ----------------------------------------------------------------
    // CREATE
    // ----------------------------------------------------------------

    public function test_draft_create_has_no_ledger_impact(): void
    {
        $before = (string) $this->cash->fresh()->balance;

        $entry = app(RevenueService::class)->create($this->draftPayload(), $this->accountant);

        $this->assertTrue($entry->isDraft());
        $this->assertNotNull($entry->reference_number);
        $this->assertSame(0, CashTransaction::query()->where('revenue_entry_id', $entry->id)->count());
        $this->assertSame($before, (string) $this->cash->fresh()->balance);
    }

    public function test_posted_create_where_supported(): void
    {
        $before = (string) $this->cash->fresh()->balance;

        $entry = app(RevenueService::class)->create(
            $this->draftPayload(['status' => RevenueEntry::STATUS_POSTED]),
            $this->accountant
        );

        $this->assertTrue($entry->isPosted());
        $this->assertSame(1, CashTransaction::query()->where('revenue_entry_id', $entry->id)->count());
        $this->assertSame(bcadd($before, '4850.00', 2), (string) $this->cash->fresh()->balance);
    }

    public function test_posted_create_requires_post_revenues_permission(): void
    {
        $reception = $this->user('reception');
        $reception->givePermissionTo('manage revenues'); // has create, but not post

        $this->expectException(HttpException::class);
        app(RevenueService::class)->create($this->draftPayload(['status' => RevenueEntry::STATUS_POSTED]), $reception);
    }

    public function test_create_rejects_non_positive_amount_even_for_draft(): void
    {
        $this->expectException(ValidationException::class);
        app(RevenueService::class)->create($this->draftPayload(['amount' => '0.00']), $this->accountant);
    }

    public function test_create_rejects_missing_category(): void
    {
        $payload = $this->draftPayload();
        unset($payload['revenue_category_id']);

        $this->expectException(ValidationException::class);
        app(RevenueService::class)->create($payload, $this->accountant);
    }

    // ----------------------------------------------------------------
    // SERVICE AUTHORIZATION (P1 corrective — RevenueService must enforce
    // 'manage revenues' itself, not rely solely on Filament's Policy)
    // ----------------------------------------------------------------

    public function test_service_create_rejects_actor_with_zero_revenue_permissions(): void
    {
        $noPermissionUser = $this->user('reception');
        $countBefore = RevenueEntry::query()->count();
        $transactionCountBefore = CashTransaction::query()->count();
        $auditCountBefore = \App\Models\AuditLog::query()->count();

        $this->expectException(HttpException::class);

        try {
            app(RevenueService::class)->create($this->draftPayload(), $noPermissionUser);
        } finally {
            $this->assertSame($countBefore, RevenueEntry::query()->count(), 'No RevenueEntry was created.');
            $this->assertSame($transactionCountBefore, CashTransaction::query()->count(), 'No CashTransaction was created.');
            $this->assertSame($auditCountBefore, \App\Models\AuditLog::query()->count(), 'No AuditLog was written.');
        }
    }

    public function test_manage_revenues_alone_can_create_draft(): void
    {
        $manager = $this->user('reception');
        $manager->givePermissionTo('manage revenues');

        $entry = app(RevenueService::class)->create($this->draftPayload(), $manager);

        $this->assertTrue($entry->isDraft());
    }

    public function test_manage_revenues_alone_cannot_create_posted(): void
    {
        $manager = $this->user('reception');
        $manager->givePermissionTo('manage revenues'); // deliberately NOT 'post revenues'

        $this->expectException(HttpException::class);
        app(RevenueService::class)->create($this->draftPayload(['status' => RevenueEntry::STATUS_POSTED]), $manager);
    }

    // ----------------------------------------------------------------
    // POST
    // ----------------------------------------------------------------

    public function test_post_draft_to_posted_creates_exactly_one_in_transaction(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload(), $this->accountant);

        $posted = app(RevenueService::class)->post($entry, $this->accountant);

        $this->assertTrue($posted->isPosted());
        $transaction = CashTransaction::query()->where('revenue_entry_id', $entry->id)->firstOrFail();
        $this->assertSame(CashTransaction::TYPE_IN, $transaction->type);
        $this->assertSame(CashTransaction::CATEGORY_INCOME, $transaction->category);
        $this->assertSame(1, CashTransaction::query()->where('revenue_entry_id', $entry->id)->count());
    }

    public function test_duplicate_posting_prevented_at_service_level(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload(), $this->accountant);

        app(RevenueService::class)->post($entry, $this->accountant);
        app(RevenueService::class)->post($entry->fresh(), $this->accountant);
        app(RevenueService::class)->post($entry->fresh(), $this->accountant);

        $this->assertSame(1, CashTransaction::query()->where('revenue_entry_id', $entry->id)->count());
    }

    public function test_duplicate_posting_prevented_at_database_level(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload(['status' => RevenueEntry::STATUS_POSTED]), $this->accountant);

        $this->expectException(QueryException::class);
        CashTransaction::create([
            'cash_account_id' => $this->cash->id,
            'revenue_entry_id' => $entry->id,
            'amount' => '4850.00',
            'type' => CashTransaction::TYPE_IN,
            'category' => CashTransaction::CATEGORY_INCOME,
            'description' => 'Duplicate attempt',
        ]);
    }

    public function test_balance_updated_exactly_once_on_post(): void
    {
        $before = (string) $this->cash->fresh()->balance;
        $entry = app(RevenueService::class)->create($this->draftPayload(), $this->accountant);

        app(RevenueService::class)->post($entry, $this->accountant);

        $this->assertSame(bcadd($before, '4850.00', 2), (string) $this->cash->fresh()->balance);
    }

    public function test_audit_evidence_exactly_once_on_post(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload(), $this->accountant);

        app(RevenueService::class)->post($entry, $this->accountant);

        $this->assertDatabaseHas('audit_logs', ['action' => 'revenue_posted', 'model' => 'RevenueEntry', 'model_id' => $entry->id]);
        $this->assertSame(1, \App\Models\AuditLog::query()->where('action', 'revenue_posted')->where('model_id', $entry->id)->count());
    }

    public function test_post_missing_account_rejected(): void
    {
        $payload = $this->draftPayload();
        unset($payload['cash_account_id']);
        $entry = app(RevenueService::class)->create($payload, $this->accountant);

        $this->expectException(ValidationException::class);
        app(RevenueService::class)->post($entry, $this->accountant);
    }

    public function test_post_missing_payment_method_rejected(): void
    {
        $payload = $this->draftPayload();
        unset($payload['payment_method']);
        $entry = app(RevenueService::class)->create($payload, $this->accountant);

        $this->expectException(ValidationException::class);
        app(RevenueService::class)->post($entry, $this->accountant);
    }

    public function test_post_cash_drawer_without_open_session_rejected(): void
    {
        $this->closeCashSession();
        $entry = app(RevenueService::class)->create($this->draftPayload(), $this->accountant);

        $this->expectException(ValidationException::class);
        app(RevenueService::class)->post($entry, $this->accountant);
    }

    public function test_post_bank_account_without_session_succeeds(): void
    {
        $bank = CashAccount::create(['name' => 'Банк', 'type' => CashAccount::TYPE_BANK, 'balance' => '0.00', 'is_active' => true]);
        $this->closeCashSession(); // no open session anywhere — bank must not care

        $entry = app(RevenueService::class)->create($this->draftPayload([
            'cash_account_id' => $bank->id, 'payment_method' => 'bank',
        ]), $this->accountant);
        $posted = app(RevenueService::class)->post($entry, $this->accountant);

        $this->assertTrue($posted->isPosted());
        $transaction = CashTransaction::query()->where('revenue_entry_id', $entry->id)->firstOrFail();
        $this->assertNull($transaction->cash_session_id);
        $this->assertSame('4850.00', (string) $bank->fresh()->balance);
    }

    // ----------------------------------------------------------------
    // IMMUTABILITY
    // ----------------------------------------------------------------

    public function test_posted_financial_fields_cannot_be_edited(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload(['status' => RevenueEntry::STATUS_POSTED]), $this->accountant);

        $this->expectException(LogicException::class);
        $entry->update(['amount' => '999.00']);
    }

    public function test_posted_entry_cannot_be_deleted(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload(['status' => RevenueEntry::STATUS_POSTED]), $this->accountant);

        $this->expectException(LogicException::class);
        $entry->delete();
    }

    public function test_draft_entry_can_be_deleted_via_the_model_invariant_directly(): void
    {
        // The model's own guard (defense in depth, no actor awareness) —
        // a raw delete() on a draft still succeeds and is not blocked,
        // but writes no audit entry itself; that is RevenueService::
        // deleteDraft()'s job (see the "DRAFT DELETE SERVICE" block below).
        $entry = app(RevenueService::class)->create($this->draftPayload(), $this->accountant);

        $entry->delete();

        $this->assertDatabaseMissing('revenue_entries', ['id' => $entry->id]);
    }

    // ----------------------------------------------------------------
    // DRAFT DELETE SERVICE (RevenueService::deleteDraft() — the canonical,
    // authorized, audited application path)
    // ----------------------------------------------------------------

    public function test_authorized_draft_deletion_succeeds_via_service(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload(), $this->accountant);
        $entryId = $entry->id;

        app(RevenueService::class)->deleteDraft($entry, $this->accountant);

        $this->assertDatabaseMissing('revenue_entries', ['id' => $entryId]);
    }

    public function test_unauthorized_draft_deletion_rejected_via_service(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload(), $this->accountant);
        $reception = $this->user('reception');

        $this->expectException(HttpException::class);
        try {
            app(RevenueService::class)->deleteDraft($entry, $reception);
        } finally {
            $this->assertDatabaseHas('revenue_entries', ['id' => $entry->id]);
        }
    }

    public function test_posted_deletion_rejected_via_service(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload(['status' => RevenueEntry::STATUS_POSTED]), $this->accountant);

        $this->expectException(ValidationException::class);
        try {
            app(RevenueService::class)->deleteDraft($entry, $this->accountant);
        } finally {
            $this->assertDatabaseHas('revenue_entries', ['id' => $entry->id]);
            $this->assertSame(1, CashTransaction::query()->where('revenue_entry_id', $entry->id)->count());
        }
    }

    public function test_reversed_deletion_rejected_via_service(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload(['status' => RevenueEntry::STATUS_POSTED]), $this->accountant);
        app(RevenueService::class)->reverse($entry, $this->accountant, 'Причина');

        $this->expectException(ValidationException::class);
        try {
            app(RevenueService::class)->deleteDraft($entry->fresh(), $this->accountant);
        } finally {
            $this->assertDatabaseHas('revenue_entries', ['id' => $entry->id]);
        }
    }

    public function test_delete_audit_created_exactly_once(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload(), $this->accountant);
        $entryId = $entry->id;

        app(RevenueService::class)->deleteDraft($entry, $this->accountant);

        $this->assertSame(
            1,
            \App\Models\AuditLog::query()->where('action', 'revenue_draft_deleted')->where('model_id', $entryId)->count()
        );
    }

    public function test_draft_delete_service_cannot_leave_an_orphan_cash_transaction(): void
    {
        // Defensive check inside deleteDraft() itself: even if a
        // CashTransaction somehow already referenced this "draft" (should
        // be structurally impossible via the service), deletion must be
        // refused rather than silently orphaning that ledger row.
        $entry = app(RevenueService::class)->create($this->draftPayload(), $this->accountant);
        CashTransaction::create([
            'cash_account_id' => $this->cash->id,
            'revenue_entry_id' => $entry->id,
            'amount' => '4850.00',
            'type' => CashTransaction::TYPE_IN,
            'category' => CashTransaction::CATEGORY_INCOME,
            'description' => 'Simulated pre-existing ledger row for a nominally-draft entry',
        ]);

        $this->expectException(ValidationException::class);
        try {
            app(RevenueService::class)->deleteDraft($entry, $this->accountant);
        } finally {
            $this->assertDatabaseHas('revenue_entries', ['id' => $entry->id]);
            $this->assertSame(1, CashTransaction::query()->where('revenue_entry_id', $entry->id)->count());
        }
    }

    public function test_draft_delete_rolls_back_atomically_on_injected_failure(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload(), $this->accountant);
        $entryId = $entry->id;
        $auditCountBefore = \App\Models\AuditLog::query()->count();

        // Eloquent's `deleted` event fires synchronously right after the
        // DB DELETE, inside deleteDraft()'s own DB::transaction(), before
        // AuditLog::create() runs. A listener that throws here simulates
        // an unexpected failure occurring after the row is gone but before
        // the audit entry is written — proving the whole thing (including
        // the already-issued DELETE) rolls back together, not just the
        // audit write.
        RevenueEntry::deleted(function () {
            throw new \RuntimeException('Simulated failure after delete, before audit.');
        });

        $threw = false;
        try {
            app(RevenueService::class)->deleteDraft($entry, $this->accountant);
        } catch (\RuntimeException $exception) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected the simulated failure to propagate.');
        // The delete rolled back — the row still exists.
        $this->assertDatabaseHas('revenue_entries', ['id' => $entryId]);
        $this->assertSame($auditCountBefore, \App\Models\AuditLog::query()->count(), 'No audit entry was left behind for the rolled-back delete.');
    }

    // Non-Tuition Revenues V1 integration into current authoritative
    // Finance UX: the parked branch's Filament EditRevenueEntry page is
    // deliberately not ported this pass (dashboard-native only, matching
    // Expenses V1's precedent — Filament stays a technical fallback for a
    // future pass, not part of the operational sidebar). The equivalent
    // "deletion routes through the service and audits" coverage now lives
    // in RevenueEntryControllerTest against the dashboard-native delete
    // action instead of a Livewire/Filament page.

    // "Ledger posting cannot silently change" is proven by
    // test_reverse_preserves_original_entry_and_transaction below: the
    // original CashTransaction's amount/account are asserted unchanged
    // after a reversal — RevenueService never mutates a posted
    // transaction, it only ever creates a second, separate reversing row.

    // ----------------------------------------------------------------
    // REVERSAL
    // ----------------------------------------------------------------

    public function test_reverse_posted_to_reversed(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload(['status' => RevenueEntry::STATUS_POSTED]), $this->accountant);

        $reversed = app(RevenueService::class)->reverse($entry, $this->accountant, 'Ошибочная запись');

        $this->assertTrue($reversed->isReversed());
        $this->assertSame('Ошибочная запись', $reversed->reversal_reason);
        $this->assertNotNull($reversed->reversed_by);
        $this->assertNotNull($reversed->reversed_at);
    }

    public function test_reverse_preserves_original_entry_and_transaction(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload(['status' => RevenueEntry::STATUS_POSTED]), $this->accountant);
        $originalTransaction = CashTransaction::query()->where('revenue_entry_id', $entry->id)->firstOrFail();
        $originalAmount = (string) $originalTransaction->amount;
        $originalAccountId = $originalTransaction->cash_account_id;

        app(RevenueService::class)->reverse($entry, $this->accountant, 'Ошибка');

        $this->assertDatabaseHas('revenue_entries', ['id' => $entry->id, 'amount' => '4850.00']);
        $preserved = CashTransaction::find($originalTransaction->id);
        $this->assertNotNull($preserved);
        $this->assertSame($originalAmount, (string) $preserved->amount);
        $this->assertSame($originalAccountId, $preserved->cash_account_id);
        $this->assertSame(CashTransaction::TYPE_IN, $preserved->type);
    }

    public function test_reverse_creates_exactly_one_out_transaction_same_amount_and_account(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload(['status' => RevenueEntry::STATUS_POSTED]), $this->accountant);

        app(RevenueService::class)->reverse($entry, $this->accountant, 'Ошибка');

        $reversal = CashTransaction::query()->where('reversed_revenue_entry_id', $entry->id)->firstOrFail();
        $this->assertSame(1, CashTransaction::query()->where('reversed_revenue_entry_id', $entry->id)->count());
        $this->assertSame(CashTransaction::TYPE_OUT, $reversal->type);
        $this->assertSame(CashTransaction::CATEGORY_REFUND, $reversal->category);
        $this->assertSame('4850.00', (string) $reversal->amount);
        $this->assertSame($this->cash->id, $reversal->cash_account_id);
    }

    public function test_reverse_balance_returns_correctly(): void
    {
        $before = (string) $this->cash->fresh()->balance;
        $entry = app(RevenueService::class)->create($this->draftPayload(['status' => RevenueEntry::STATUS_POSTED]), $this->accountant);
        $this->assertSame(bcadd($before, '4850.00', 2), (string) $this->cash->fresh()->balance);

        app(RevenueService::class)->reverse($entry, $this->accountant, 'Ошибка');

        $this->assertSame($before, (string) $this->cash->fresh()->balance);
    }

    // A second reverse() call on an already-reversed entry is a safe,
    // idempotent no-op (mirrors ExpenseService::pay()'s established
    // early-return-if-already-transitioned pattern) rather than an
    // exception — "prevented" means no second reversing CashTransaction
    // is ever created, which is what this test (and the DB-level backstop
    // test below) actually verifies.
    public function test_second_reversal_is_a_no_op_not_a_new_reversal(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload(['status' => RevenueEntry::STATUS_POSTED]), $this->accountant);
        app(RevenueService::class)->reverse($entry, $this->accountant, 'Первая причина');

        $result = app(RevenueService::class)->reverse($entry->fresh(), $this->accountant, 'Вторая попытка');

        $this->assertTrue($result->isReversed());
        $this->assertSame('Первая причина', $result->reversal_reason);
        $this->assertSame(1, CashTransaction::query()->where('reversed_revenue_entry_id', $entry->id)->count());
    }

    public function test_second_reversal_prevented_at_database_level(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload(['status' => RevenueEntry::STATUS_POSTED]), $this->accountant);
        app(RevenueService::class)->reverse($entry, $this->accountant, 'Причина');

        $this->expectException(QueryException::class);
        CashTransaction::create([
            'cash_account_id' => $this->cash->id,
            'reversed_revenue_entry_id' => $entry->id,
            'amount' => '4850.00',
            'type' => CashTransaction::TYPE_OUT,
            'category' => CashTransaction::CATEGORY_REFUND,
            'description' => 'Duplicate reversal attempt',
        ]);
    }

    public function test_reverse_requires_reason(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload(['status' => RevenueEntry::STATUS_POSTED]), $this->accountant);

        $this->expectException(ValidationException::class);
        app(RevenueService::class)->reverse($entry, $this->accountant, '   ');
    }

    public function test_reverse_requires_permission(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload(['status' => RevenueEntry::STATUS_POSTED]), $this->accountant);
        $reception = $this->user('reception');

        $this->expectException(HttpException::class);
        app(RevenueService::class)->reverse($entry, $reception, 'Причина');
    }

    public function test_draft_cannot_be_reversed(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload(), $this->accountant);

        $this->expectException(ValidationException::class);
        app(RevenueService::class)->reverse($entry, $this->accountant, 'Причина');
    }

    public function test_reverse_cash_drawer_requires_open_session(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload(['status' => RevenueEntry::STATUS_POSTED]), $this->accountant);
        $this->closeCashSession();

        $this->expectException(ValidationException::class);
        app(RevenueService::class)->reverse($entry, $this->accountant, 'Причина');
    }

    public function test_reverse_bank_account_without_session_succeeds(): void
    {
        $bank = CashAccount::create(['name' => 'Банк 2', 'type' => CashAccount::TYPE_BANK, 'balance' => '0.00', 'is_active' => true]);
        $entry = app(RevenueService::class)->create($this->draftPayload([
            'cash_account_id' => $bank->id, 'payment_method' => 'bank', 'status' => RevenueEntry::STATUS_POSTED,
        ]), $this->accountant);
        $this->closeCashSession();

        $reversed = app(RevenueService::class)->reverse($entry, $this->accountant, 'Причина');

        $this->assertTrue($reversed->isReversed());
        $this->assertSame('0.00', (string) $bank->fresh()->balance);
    }

    public function test_reverse_audit_evidence(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload(['status' => RevenueEntry::STATUS_POSTED]), $this->accountant);

        app(RevenueService::class)->reverse($entry, $this->accountant, 'Причина сторно');

        $this->assertDatabaseHas('audit_logs', ['action' => 'revenue_reversed', 'model' => 'RevenueEntry', 'model_id' => $entry->id]);
    }

    public function test_reversal_in_a_new_session_leaves_the_original_session_attribution_untouched(): void
    {
        $balanceBefore = (string) $this->cash->fresh()->balance;
        $sessionA = $this->cashSession; // already open in setUp()
        $entry = app(RevenueService::class)->create($this->draftPayload(['status' => RevenueEntry::STATUS_POSTED]), $this->accountant);
        $originalTransaction = CashTransaction::query()->where('revenue_entry_id', $entry->id)->firstOrFail();
        $this->assertSame($sessionA->id, $originalTransaction->cash_session_id);

        $this->closeCashSession($sessionA);
        $sessionB = $this->openCashSession();
        $this->assertNotSame($sessionA->id, $sessionB->id);

        app(RevenueService::class)->reverse($entry, $this->accountant, 'Причина');

        $reversal = CashTransaction::query()->where('reversed_revenue_entry_id', $entry->id)->firstOrFail();
        $this->assertSame($sessionB->id, $reversal->cash_session_id, 'The reversal belongs to the currently open session.');

        $originalTransaction->refresh();
        $this->assertSame($sessionA->id, $originalTransaction->cash_session_id, 'The original transaction still belongs to the now-closed session A.');

        // Session A (closed, historical) still reflects only the original
        // posting it actually saw — its own expectedClosing() is frozen at
        // close time and must not retroactively include the reversal.
        $sessionA->refresh();
        $this->assertTrue($sessionA->isClosed());

        // Session B (open) reflects only the reversal it actually
        // received — the outflow correctly reduces its expected cash.
        $expectedB = bcsub((string) $sessionB->fresh()->opening_expected, (string) $entry->amount, 2);
        $this->assertSame($expectedB, $sessionB->fresh()->expectedClosing());

        // Overall account balance nets back to its pre-operation value.
        $this->assertSame($balanceBefore, (string) $this->cash->fresh()->balance);
    }

    // ----------------------------------------------------------------
    // CATEGORIES
    // ----------------------------------------------------------------

    public function test_initial_categories_can_be_seeded(): void
    {
        (new \Database\Seeders\RevenueCategorySeeder())->run();

        foreach ([RevenueCategory::CODE_CAFETERIA, RevenueCategory::CODE_DONATION, RevenueCategory::CODE_FINE, RevenueCategory::CODE_OTHER] as $code) {
            $this->assertDatabaseHas('revenue_categories', ['code' => $code]);
        }
    }

    public function test_inactive_category_cannot_be_used_for_new_posting(): void
    {
        $this->cafeteria->update(['is_active' => false]);
        $entry = RevenueEntry::create($this->draftPayload());

        $this->expectException(ValidationException::class);
        app(RevenueService::class)->post($entry, $this->accountant);
    }

    public function test_category_stable_code_behavior_survives_rename(): void
    {
        $this->fine->update(['name_ru' => 'Переименованная категория']);

        $entry = app(RevenueService::class)->create($this->draftPayload([
            'revenue_category_id' => $this->fine->id,
        ]), $this->accountant);

        $this->assertSame(RevenueCategory::CODE_FINE, $entry->category->code);
    }

    // ----------------------------------------------------------------
    // FINE / STUDENT LINK
    // ----------------------------------------------------------------

    public function test_fine_with_nullable_student_link_and_no_invoice_created(): void
    {
        $invoiceCountBefore = \App\Models\Invoice::query()->count();

        $entry = app(RevenueService::class)->create($this->draftPayload([
            'revenue_category_id' => $this->fine->id,
            'student_id' => $this->student->id,
            'status' => RevenueEntry::STATUS_POSTED,
        ]), $this->accountant);

        $this->assertSame($this->student->id, $entry->student_id);
        $this->assertSame($invoiceCountBefore, \App\Models\Invoice::query()->count());
        $this->assertSame(0, \App\Models\InvoicePayment::query()->count());
    }

    public function test_fine_without_student_is_valid(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload([
            'revenue_category_id' => $this->fine->id,
        ]), $this->accountant);

        $this->assertNull($entry->student_id);
    }

    public function test_cafeteria_donation_other_do_not_require_student(): void
    {
        foreach ([$this->cafeteria, $this->donation] as $category) {
            $entry = app(RevenueService::class)->create($this->draftPayload([
                'revenue_category_id' => $category->id,
            ]), $this->accountant);
            $this->assertNull($entry->student_id);
        }
    }

    public function test_donation_records_payer_name(): void
    {
        $entry = app(RevenueService::class)->create($this->draftPayload([
            'revenue_category_id' => $this->donation->id,
            'payer_name' => 'Иванов И.И.',
        ]), $this->accountant);

        $this->assertSame('Иванов И.И.', $entry->payer_name);
    }

    // ----------------------------------------------------------------
    // REPORTING
    // ----------------------------------------------------------------

    public function test_posted_revenue_appears_in_cash_report_totals(): void
    {
        app(RevenueService::class)->create($this->draftPayload(['status' => RevenueEntry::STATUS_POSTED]), $this->accountant);

        $response = $this->actingAs($this->user('admin'))->get(route('dashboard.cash.reports', ['account_id' => $this->cash->id]));

        $response->assertOk();
        $this->assertSame(4850.0, (float) $response->viewData('totalIn'));
    }

    public function test_posted_revenue_impacts_cash_session_expected_closing(): void
    {
        $opening = $this->cashSession->fresh()->opening_expected;
        app(RevenueService::class)->create($this->draftPayload(['status' => RevenueEntry::STATUS_POSTED]), $this->accountant);

        $expected = bcadd((string) $opening, '4850.00', 2);
        $this->assertSame($expected, $this->cashSession->fresh()->expectedClosing());
    }

    public function test_reversal_offsets_cash_session_expected_closing(): void
    {
        $opening = $this->cashSession->fresh()->opening_expected;
        $entry = app(RevenueService::class)->create($this->draftPayload(['status' => RevenueEntry::STATUS_POSTED]), $this->accountant);

        app(RevenueService::class)->reverse($entry, $this->accountant, 'Причина');

        $this->assertSame((string) $opening, $this->cashSession->fresh()->expectedClosing());
    }

    public function test_collections_page_shows_exactly_the_real_invoice_payment_unaffected_by_revenue_entries(): void
    {
        $invoice = $this->invoice('1200.00');
        $payment = app(\App\Services\Finance\InvoicePaymentService::class)->record(
            $invoice->id,
            $this->cash->id,
            '1200.00',
            'cash',
            (string) \Illuminate\Support\Str::uuid(),
            $this->accountant,
        );

        // A non-tuition revenue entry posted alongside the real collection
        // must neither appear in Collections nor inflate its total.
        app(RevenueService::class)->create($this->draftPayload(['status' => RevenueEntry::STATUS_POSTED]), $this->accountant);

        $response = $this->actingAs($this->accountant)->get(route('dashboard.finance.collections.index'));

        $response->assertOk();
        $payments = $response->viewData('payments');
        $this->assertSame(1, $payments->total(), 'Exactly the one real InvoicePayment is counted.');
        $this->assertTrue($payments->getCollection()->contains(fn ($row) => $row['payment']->id === $payment->id));
        $totalCollected = $payments->getCollection()->sum(fn ($row) => (float) $row['payment']->amount);
        $this->assertSame(1200.0, $totalCollected, 'The collections total equals exactly the real payment, not 1200 + the revenue entry.');
    }

    // ----------------------------------------------------------------
    // SECURITY
    // ----------------------------------------------------------------

    public function test_reception_cannot_manage_revenues(): void
    {
        $reception = $this->user('reception');
        $this->assertFalse($reception->can('viewAny', RevenueEntry::class));
        $this->assertFalse($reception->can('create', RevenueEntry::class));
    }

    public function test_cashier_cannot_manage_revenues(): void
    {
        $cashier = $this->user('cashier');
        $this->assertFalse($cashier->can('viewAny', RevenueEntry::class));
    }

    public function test_teacher_cannot_manage_revenues(): void
    {
        $teacher = $this->user('teacher');
        $this->assertFalse($teacher->can('viewAny', RevenueEntry::class));
    }

    public function test_accountant_has_full_revenue_access(): void
    {
        $this->assertTrue($this->accountant->can('viewAny', RevenueEntry::class));
        $this->assertTrue($this->accountant->can('create', RevenueEntry::class));
        $this->assertTrue($this->accountant->can('post revenues'));
        $this->assertTrue($this->accountant->can('reverse revenues'));
    }

    public function test_private_attachment_metadata_is_derived_from_stored_file(): void
    {
        $disk = config('filesystems.uploads.private');
        Storage::fake($disk);
        Storage::disk($disk)->put('revenue-entries/receipt.pdf', 'fake-pdf-contents');

        $metadata = RevenueEntry::attachmentMetadataFrom('revenue-entries/receipt.pdf');

        $this->assertSame('receipt.pdf', $metadata['attachment_name']);
        $this->assertGreaterThan(0, $metadata['attachment_size']);
        $this->assertNotEmpty($metadata['attachment_type']);
    }
}
