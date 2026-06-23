<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Fee;

class FeeSeeder extends Seeder
{
    public function run()
    {

        /*
        |---------------------------------------
        | Tuition Fees
        |---------------------------------------
        */

        Fee::create([
            'name_ru' => 'Tuition Grade 1-4',
            'amount' => 4500,
            'category' => 'tuition',
            'grade_id' => 1,
            'is_active' => 1
        ]);

        Fee::create([
            'name_ru' => 'Tuition Grade 5-6',
            'amount' => 5500,
            'category' => 'tuition',
            'grade_id' => 2,
            'is_active' => 1
        ]);

        Fee::create([
            'name_ru' => 'Tuition Grade 7-8',
            'amount' => 6100,
            'category' => 'tuition',
            'grade_id' => 3,
            'is_active' => 1
        ]);

        /*
        |---------------------------------------
        | Food
        |---------------------------------------
        */

        Fee::create([
            'name_ru' => 'Food',
            'amount' => 170,
            'category' => 'food',
            'is_active' => 1
        ]);

        /*
        |---------------------------------------
        | Bus
        |---------------------------------------
        */

        Fee::create([
            'name_ru' => 'Bus',
            'amount' => 1500,
            'category' => 'transport',
            'is_active' => 1
        ]);

        /*
        |---------------------------------------
        | Registration
        |---------------------------------------
        */

        Fee::create([
            'name_ru' => 'Registration Fee',
            'amount' => 7000,
            'category' => 'registration',
            'is_active' => 1
        ]);

    }
}