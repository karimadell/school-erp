<?php

namespace Tests\Feature\Finance;

use App\Models\CashTransaction;
use App\Models\RevenueCategory;
use App\Models\RevenueEntry;
use App\Services\Finance\RevenueService;

/**
 * Non-Tuition Revenues V1 integration — dashboard-native HTTP layer over
 * the canonical RevenueService, reached only from the Приход type
 * selector (see IncomeEntryControllerTest-equivalent coverage in
 * FinanceWorkspaceSimplificationTest for donation()/other() redirects).
 * No Filament, no standalone sidebar entry.
 */
class RevenueEntryControllerTest extends FinanceOperationsTestCase
{
    private RevenueCategory $cafeteria;

    private RevenueCategory $donation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cafeteria = RevenueCategory::firstOrCreate(['code' => RevenueCategory::CODE_CAFETERIA], ['name_ru' => 'Кафетерий', 'is_active' => true]);
        $this->donation = RevenueCategory::firstOrCreate(['code' => RevenueCategory::CODE_DONATION], ['name_ru' => 'Пожертвования', 'is_active' => true]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'revenue_category_id' => $this->cafeteria->id,
            'amount' => '500.00',
            'revenue_date' => today()->toDateString(),
            'cash_account_id' => $this->cash->id,
            'payment_method' => 'cash',
            'status' => RevenueEntry::STATUS_DRAFT,
        ], $overrides);
    }

    // 6. Revenue create permission.
    public function test_create_and_store_require_manage_revenues_permission(): void
    {
        $user = $this->user('reception');
        $user->givePermissionTo('manage revenues');

        $this->actingAs($user)
            ->get(route('dashboard.finance.income.revenue.create'))
            ->assertOk()
            ->assertViewIs('dashboard.finance.income.revenue.create');

        $this->actingAs($user)
            ->post(route('dashboard.finance.income.revenue.store'), $this->payload())
            ->assertRedirect();

        $this->assertDatabaseHas('revenue_entries', ['amount' => '500.00']);
    }

    public function test_create_and_store_forbidden_without_manage_revenues(): void
    {
        $user = $this->user('reception');

        $this->actingAs($user)->get(route('dashboard.finance.income.revenue.create'))->assertForbidden();

        $this->actingAs($user)
            ->post(route('dashboard.finance.income.revenue.store'), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, RevenueEntry::query()->count());
    }

    // Donation entry point locks the category server-side, not just visually.
    public function test_donation_create_locks_category_server_side(): void
    {
        $user = $this->user('reception');
        $user->givePermissionTo('manage revenues');

        $this->actingAs($user)
            ->post(route('dashboard.finance.income.revenue.store'), $this->payload([
                'revenue_category_id' => $this->donation->id,
                'payer_name' => 'Иванов И.И.',
            ]))
            ->assertRedirect();

        $entry = RevenueEntry::query()->where('payer_name', 'Иванов И.И.')->firstOrFail();
        $this->assertSame($this->donation->id, $entry->revenue_category_id);
    }

    // Draft-only status accepted on create — never reversed directly.
    public function test_store_rejects_reversed_status(): void
    {
        $user = $this->user('reception');
        $user->givePermissionTo('manage revenues');

        $this->actingAs($user)
            ->post(route('dashboard.finance.income.revenue.store'), $this->payload(['status' => RevenueEntry::STATUS_REVERSED]))
            ->assertSessionHasErrors('status');

        $this->assertSame(0, RevenueEntry::query()->count());
    }

    // 8. DRAFT -> POSTED via the dashboard action.
    public function test_post_action_transitions_draft_to_posted_and_honors_permission(): void
    {
        $entry = app(RevenueService::class)->create($this->payload(), $this->accountant);

        $noPermission = $this->user('reception');
        $noPermission->givePermissionTo('manage revenues');
        $this->actingAs($noPermission)
            ->post(route('dashboard.finance.income.revenue.post', $entry))
            ->assertForbidden();
        $this->assertTrue($entry->fresh()->isDraft());

        $poster = $this->user('reception');
        $poster->givePermissionTo(['manage revenues', 'post revenues']);
        $this->actingAs($poster)
            ->post(route('dashboard.finance.income.revenue.post', $entry))
            ->assertRedirect();

        $this->assertTrue($entry->fresh()->isPosted());
        $this->assertSame(1, CashTransaction::query()->where('revenue_entry_id', $entry->id)->count());
    }

    // 9. POSTED -> REVERSED via the dashboard action, with a required reason.
    public function test_reverse_action_transitions_posted_to_reversed_and_honors_permission(): void
    {
        $entry = app(RevenueService::class)->create($this->payload(['status' => RevenueEntry::STATUS_POSTED]), $this->accountant);

        $noPermission = $this->user('reception');
        $noPermission->givePermissionTo('manage revenues');
        $this->actingAs($noPermission)
            ->post(route('dashboard.finance.income.revenue.reverse', $entry), ['reversal_reason' => 'Ошибка'])
            ->assertForbidden();

        $reverser = $this->user('reception');
        $reverser->givePermissionTo(['manage revenues', 'reverse revenues']);
        $this->actingAs($reverser)
            ->post(route('dashboard.finance.income.revenue.reverse', $entry), ['reversal_reason' => ''])
            ->assertSessionHasErrors('reversal_reason');
        $this->assertTrue($entry->fresh()->isPosted());

        $this->actingAs($reverser)
            ->post(route('dashboard.finance.income.revenue.reverse', $entry), ['reversal_reason' => 'Ошибочная запись'])
            ->assertRedirect();

        $fresh = $entry->fresh();
        $this->assertTrue($fresh->isReversed());
        $this->assertSame('Ошибочная запись', $fresh->reversal_reason);
    }

    // 10. Draft deletion rules — only a draft, only with permission.
    public function test_destroy_allows_only_draft_deletion_with_permission(): void
    {
        $draft = app(RevenueService::class)->create($this->payload(), $this->accountant);
        $posted = app(RevenueService::class)->create($this->payload(['status' => RevenueEntry::STATUS_POSTED]), $this->accountant);

        $user = $this->user('reception');
        $user->givePermissionTo('manage revenues');

        $this->actingAs($user)
            ->delete(route('dashboard.finance.income.revenue.destroy', $posted))
            ->assertForbidden();
        $this->assertDatabaseHas('revenue_entries', ['id' => $posted->id]);

        $this->actingAs($user)
            ->delete(route('dashboard.finance.income.revenue.destroy', $draft))
            ->assertRedirect(route('dashboard.finance.income.revenue.index'));
        $this->assertDatabaseMissing('revenue_entries', ['id' => $draft->id]);
    }

    public function test_destroy_forbidden_without_permission(): void
    {
        $draft = app(RevenueService::class)->create($this->payload(), $this->accountant);
        $noPermission = $this->user('reception');

        $this->actingAs($noPermission)
            ->delete(route('dashboard.finance.income.revenue.destroy', $draft))
            ->assertForbidden();

        $this->assertDatabaseHas('revenue_entries', ['id' => $draft->id]);
    }

    // 19. Dashboard-native UX — every Revenue page renders inside the
    // unified dashboard shell, never a bare or Filament page.
    public function test_revenue_pages_render_inside_unified_dashboard_shell(): void
    {
        $user = $this->user('reception');
        $user->givePermissionTo('manage revenues');
        $entry = app(RevenueService::class)->create($this->payload(), $this->accountant);

        foreach ([
            route('dashboard.finance.income.revenue.index'),
            route('dashboard.finance.income.revenue.create'),
            route('dashboard.finance.income.revenue.show', $entry),
        ] as $url) {
            $this->actingAs($user)->get($url)
                ->assertOk()
                ->assertSee('ui2-shell', false)
                ->assertSee('ui2-sidebar', false);
        }
    }

    // 20. No Filament route is linked from the Revenue pages themselves.
    public function test_revenue_pages_never_link_to_filament(): void
    {
        $user = $this->user('reception');
        $user->givePermissionTo('manage revenues');
        $entry = app(RevenueService::class)->create($this->payload(), $this->accountant);

        $this->actingAs($user)
            ->get(route('dashboard.finance.income.revenue.show', $entry))
            ->assertOk()
            ->assertDontSee('/admin/revenue', false);
    }

    // Private attachment behaviour mirrors Expenses V1 exactly.
    public function test_attachment_download_requires_authorization(): void
    {
        $disk = config('filesystems.uploads.private');
        \Illuminate\Support\Facades\Storage::fake($disk);
        \Illuminate\Support\Facades\Storage::disk($disk)->put('revenue-entries/receipt.pdf', 'fake-pdf-contents');

        $entry = app(RevenueService::class)->create($this->payload(['attachment_path' => 'revenue-entries/receipt.pdf']), $this->accountant);

        $noPermission = $this->user('reception');
        $this->actingAs($noPermission)
            ->get(route('dashboard.finance.income.revenue.attachment', $entry))
            ->assertForbidden();

        $authorized = $this->user('reception');
        $authorized->givePermissionTo('manage revenues');
        $this->actingAs($authorized)
            ->get(route('dashboard.finance.income.revenue.attachment', $entry))
            ->assertOk()
            ->assertHeader('Content-Disposition');
    }
}
