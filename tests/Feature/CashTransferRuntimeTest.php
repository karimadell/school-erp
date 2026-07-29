<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finance Batch 2 / CashTransferController runtime-error fixes:
 *
 * 1. create()/index() referenced Blade views ('cash.transfers.create',
 *    'cash.transfers.index') that never existed anywhere in the
 *    codebase — every visit to the sidebar-linked "Cash Transfer" page
 *    (dashboard.cash.transfer.form) fatal-errored with a
 *    ViewNotFoundException, and a successful transfer's own redirect
 *    target (dashboard.cash.transfers) did too. Fixed by pointing
 *    create() at the already-existing, already-correct
 *    dashboard.cash.transfer.create view, and adding the smallest
 *    functional dashboard.cash.transfer.index view for the list.
 * 2. index() eager-loads a 'creator' relation that did not exist on the
 *    CashTransfer model at all (only fromAccount()/toAccount() did) —
 *    a second, independent cause of the same route's 500, fixed by
 *    adding CashTransfer::creator() (belongsTo User via created_by,
 *    an already-existing, already-nullable column — no schema change).
 * 3. store()'s notes-concatenation line accessed $data['notes'] with no
 *    fallback; $data (from $request->validate()) omits the key
 *    entirely when the optional 'notes' field is absent from the
 *    request, throwing "Undefined array key notes". Fixed with a
 *    ?? null fallback — the resulting stored value is unchanged from
 *    the original intent (purpose alone, no ' - notes' suffix).
 */
class CashTransferRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function makeCashAccount(float $balance = 0): CashAccount
    {
        return CashAccount::create(['name' => 'Account ' . uniqid(), 'type' => CashAccount::TYPE_CASH, 'balance' => $balance]);
    }

    public function test_the_transfer_form_route_returns_200(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard.cash.transfer.form'));

        $response->assertOk();
    }

    public function test_the_transfer_index_route_returns_200(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard.cash.transfers'));

        $response->assertOk();
    }

    public function test_transfer_submission_succeeds_when_notes_is_entirely_omitted_from_the_request(): void
    {
        $user = User::factory()->create();
        $from = $this->makeCashAccount(balance: 500);
        $to = $this->makeCashAccount(balance: 0);

        // Deliberately no 'notes' key at all in the payload — this is the
        // exact condition that used to throw "Undefined array key notes".
        $response = $this->actingAs($user)->post(route('dashboard.cash.transfer.store'), [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 100,
            'purpose' => 'Test transfer',
        ]);

        $response->assertRedirect(route('dashboard.cash.transfers'));
        $response->assertSessionHasNoErrors();
    }

    public function test_the_transfer_is_persisted_with_the_intended_notes_value_when_notes_is_omitted(): void
    {
        $user = User::factory()->create();
        $from = $this->makeCashAccount(balance: 500);
        $to = $this->makeCashAccount(balance: 0);

        $this->actingAs($user)->post(route('dashboard.cash.transfer.store'), [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 100,
            'purpose' => 'Test transfer',
        ]);

        $transfer = CashTransfer::sole();

        // No 'notes' submitted — the original " - {notes}" suffix logic
        // never applied, so the stored value is exactly the purpose text,
        // matching what the pre-crash code already intended for a blank
        // notes field.
        $this->assertSame('Test transfer', $transfer->notes);
    }

    public function test_end_to_end_a_submitted_transfer_is_visible_on_the_index_page_after_the_redirect(): void
    {
        $user = User::factory()->create();
        $from = $this->makeCashAccount(balance: 500);
        $to = $this->makeCashAccount(balance: 0);

        $storeResponse = $this->actingAs($user)->post(route('dashboard.cash.transfer.store'), [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 100,
            'purpose' => 'Test transfer',
        ]);

        $storeResponse->assertRedirect(route('dashboard.cash.transfers'));

        $indexResponse = $this->actingAs($user)->get(route('dashboard.cash.transfers'));

        $indexResponse->assertOk();
        $indexResponse->assertSee($from->name);
        $indexResponse->assertSee($to->name);

        $transfer = CashTransfer::sole();
        $indexResponse->assertSee($transfer->receipt_number);
    }
}
