<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('school_name');
            $table->string('short_name');
            $table->string('country')->default('Egypt');
            $table->string('city')->nullable();
            $table->string('timezone')->default('Africa/Cairo');
            $table->string('language', 5)->default('ru');
            $table->string('phone_1')->nullable();
            $table->string('phone_2')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->text('address')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('printing_logo_path')->nullable();
            $table->string('stamp_path')->nullable();
            $table->string('director_signature_path')->nullable();
            $table->string('header_color', 7)->default('#263B58');
            $table->string('footer_color', 7)->default('#6B7280');
            $table->boolean('print_date_enabled')->default(true);
            $table->boolean('page_numbers_enabled')->default(true);
            $table->string('currency', 3)->default('EGP');
            $table->string('currency_symbol', 10)->default('EGP');
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->string('amount_format')->default('1 234.56');
            $table->foreignId('default_academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->date('school_year_start')->nullable();
            $table->date('school_year_end')->nullable();
            $table->timestamps();
        });

        $logoPath = null;
        $existingLogo = public_path('images/school-logo.png');

        if (is_file($existingLogo)) {
            $logoPath = 'branding/school-logo.png';
            Storage::disk('public')->put($logoPath, file_get_contents($existingLogo));
        }

        DB::table('school_settings')->insert([
            'id' => 1,
            'school_name' => 'ЦЕНТР «НАШИ ТРАДИЦИИ»',
            'short_name' => 'Наши традиции',
            'country' => 'Egypt',
            'timezone' => 'Africa/Cairo',
            'language' => 'ru',
            'phone_1' => '+20 106 553 6448',
            'phone_2' => '+20 10 6217 2809',
            'email' => 'nashitradicii@gmail.com',
            'logo_path' => $logoPath,
            'printing_logo_path' => $logoPath,
            'currency' => 'EGP',
            'currency_symbol' => 'EGP',
            'decimal_places' => 2,
            'amount_format' => '1 234.56',
            'header_color' => '#263B58',
            'footer_color' => '#6B7280',
            'print_date_enabled' => true,
            'page_numbers_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('school_settings');
    }
};
