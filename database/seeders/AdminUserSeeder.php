<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Alpha handoff: no plaintext password is committed here. Set ADMIN_PASSWORD
 * in .env to control it (also lets ops rotate it by changing the env var and
 * reseeding). If it's unset and the account doesn't exist yet, a random
 * password is generated and printed once to the console — it is never
 * stored anywhere, so capture it immediately. An already-existing account's
 * password is left untouched on repeat seeding unless ADMIN_PASSWORD is set.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'admin@school.test';
        $exists = User::where('email', $email)->exists();

        $password = env('ADMIN_PASSWORD');

        if (! $password && ! $exists) {
            $password = Str::password(16, symbols: false);

            $this->command?->warn("Generated Alpha admin password for {$email}: {$password}");
            $this->command?->warn('Store this securely now — it will not be shown again.');
        }

        $attributes = [
            'name' => 'Admin',
            'is_active' => 1,
        ];

        if ($password) {
            $attributes['password'] = Hash::make($password);
        }

        $user = User::updateOrCreate(['email' => $email], $attributes);

        if (!$user->hasRole('admin')) {
            $user->assignRole('admin');
        }
    }
}