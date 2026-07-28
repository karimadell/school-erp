<?php

namespace Tests\Feature;

use App\Filament\Resources\LessonJournalEntries\Pages\ListLessonJournalEntries;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Batch 9: admin-side oversight of lesson journal entries — gated on
 * 'manage journal entries' (admin/school-admin/principal only).
 */
class LessonJournalEntryResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithRole(string $role): User
    {
        (new RolesAndPermissionsSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_a_role_without_manage_journal_entries_cannot_open_the_resource(): void
    {
        $user = $this->userWithRole('accountant');

        Livewire::actingAs($user)->test(ListLessonJournalEntries::class)->assertForbidden();
    }

    public function test_school_admin_can_open_the_resource(): void
    {
        $user = $this->userWithRole('school-admin');

        Livewire::actingAs($user)->test(ListLessonJournalEntries::class)->assertSuccessful();
    }

    public function test_principal_can_open_the_resource(): void
    {
        $user = $this->userWithRole('principal');

        Livewire::actingAs($user)->test(ListLessonJournalEntries::class)->assertSuccessful();
    }

    public function test_reception_cannot_open_the_resource(): void
    {
        $user = $this->userWithRole('reception');

        Livewire::actingAs($user)->test(ListLessonJournalEntries::class)->assertForbidden();
    }
}
