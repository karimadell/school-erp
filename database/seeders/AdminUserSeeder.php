<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {

        $user = User::updateOrCreate(

            ['email' => 'admin@school.test'],

            [
                'name' => 'Admin',
                'password' => Hash::make('12345678'),
                'is_active' => 1
            ]
        );

        if (!$user->hasRole('admin')) {
            $user->assignRole('admin');
        }
    }
}