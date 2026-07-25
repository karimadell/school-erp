<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CashAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function authorizedUser(): User
    {
        $user = User::factory()->create();

        foreach (['cash.create', 'cash.edit', 'cash.delete'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user->givePermissionTo(['cash.create', 'cash.edit', 'cash.delete']);

        return $user;
    }

    public function test_authorized_user_can_open_edit_page(): void
    {
        $user = $this->authorizedUser();
        $account = CashAccount::create(['name' => 'Main', 'type' => 'main', 'balance' => 100]);

        $response = $this->actingAs($user)
            ->get(route('dashboard.cash.accounts.edit', $account));

        $response->assertOk();
    }

    public function test_edit_page_displays_current_values(): void
    {
        $user = $this->authorizedUser();
        $account = CashAccount::create(['name' => 'Petty Cash', 'type' => 'main', 'balance' => 250]);

        $response = $this->actingAs($user)
            ->get(route('dashboard.cash.accounts.edit', $account));

        $response->assertOk();
        $response->assertSee('Petty Cash');
    }

    public function test_account_can_be_updated(): void
    {
        $user = $this->authorizedUser();
        $account = CashAccount::create(['name' => 'Old Name', 'type' => 'main', 'balance' => 0]);

        $response = $this->actingAs($user)
            ->put(route('dashboard.cash.accounts.update', $account), [
                'name' => 'New Name',
                'type' => 'main',
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('New Name', $account->fresh()->name);
    }

    public function test_parent_id_can_be_updated(): void
    {
        $user = $this->authorizedUser();
        $parent = CashAccount::create(['name' => 'Parent', 'type' => 'main', 'balance' => 0]);
        $child = CashAccount::create(['name' => 'Child', 'type' => 'sub', 'balance' => 0]);

        $response = $this->actingAs($user)
            ->put(route('dashboard.cash.accounts.update', $child), [
                'name' => 'Child',
                'type' => 'sub',
                'parent_id' => $parent->id,
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame($parent->id, $child->fresh()->parent_id);
    }

    public function test_account_cannot_be_its_own_parent(): void
    {
        $user = $this->authorizedUser();
        $account = CashAccount::create(['name' => 'Self', 'type' => 'main', 'balance' => 0]);

        $response = $this->actingAs($user)
            ->put(route('dashboard.cash.accounts.update', $account), [
                'name' => 'Self',
                'type' => 'main',
                'parent_id' => $account->id,
            ]);

        $response->assertSessionHasErrors('parent_id');
        $this->assertNull($account->fresh()->parent_id);
    }

    public function test_account_cannot_use_a_descendant_as_parent(): void
    {
        $user = $this->authorizedUser();
        $grandparent = CashAccount::create(['name' => 'A', 'type' => 'main', 'balance' => 0]);
        $parent = CashAccount::create(['name' => 'B', 'type' => 'main', 'parent_id' => $grandparent->id, 'balance' => 0]);
        $child = CashAccount::create(['name' => 'C', 'type' => 'main', 'parent_id' => $parent->id, 'balance' => 0]);

        // Grandparent trying to adopt its own descendant as a parent must be rejected.
        $response = $this->actingAs($user)
            ->put(route('dashboard.cash.accounts.update', $grandparent), [
                'name' => 'A',
                'type' => 'main',
                'parent_id' => $child->id,
            ]);

        $response->assertSessionHasErrors('parent_id');
        $this->assertNull($grandparent->fresh()->parent_id);
    }

    public function test_unauthorized_user_cannot_edit(): void
    {
        $user = User::factory()->create();
        $account = CashAccount::create(['name' => 'Guarded', 'type' => 'main', 'balance' => 0]);

        $response = $this->actingAs($user)
            ->get(route('dashboard.cash.accounts.edit', $account));

        $response->assertForbidden();
    }

    public function test_invalid_parent_id_is_rejected(): void
    {
        $user = $this->authorizedUser();
        $account = CashAccount::create(['name' => 'X', 'type' => 'main', 'balance' => 0]);

        $response = $this->actingAs($user)
            ->put(route('dashboard.cash.accounts.update', $account), [
                'name' => 'X',
                'type' => 'main',
                'parent_id' => 999999,
            ]);

        $response->assertSessionHasErrors('parent_id');
    }

    public function test_edit_page_renders_without_translation_key_leaks_in_all_locales(): void
    {
        $user = $this->authorizedUser();
        $account = CashAccount::create(['name' => 'Multi', 'type' => 'main', 'balance' => 0]);

        foreach (['ru', 'en', 'ar'] as $locale) {
            app()->setLocale($locale);

            $response = $this->actingAs($user)
                ->get(route('dashboard.cash.accounts.edit', $account));

            $response->assertOk();
            $response->assertDontSee('cash_accounts.', false);
        }
    }
}
