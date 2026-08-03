<?php

namespace Tests\Feature\Branding;

use App\Models\SchoolSetting;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SchoolSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder())->run();
    }

    public function test_singleton_defaults_and_modern_settings_page_are_available_to_admin(): void
    {
        $settings = SchoolSetting::current();
        $admin = $this->user('admin');

        $this->assertSame(1, SchoolSetting::count());
        $this->assertSame('ЦЕНТР «НАШИ ТРАДИЦИИ»', $settings->school_name);
        $this->assertSame('EGP', $settings->currency);

        $this->actingAs($admin)->get(route('dashboard.settings.school.edit'))
            ->assertOk()
            ->assertSee('Настройки школы')
            ->assertSee('Основные')
            ->assertSee('Контакты')
            ->assertSee('Документы')
            ->assertSee('Финансы')
            ->assertSee('Учебный год');
    }

    public function test_only_admin_and_super_admin_can_edit_settings(): void
    {
        foreach (['admin', 'super-admin'] as $role) {
            $this->actingAs($this->user($role))->get(route('dashboard.settings.school.edit'))->assertOk();
        }

        foreach (['principal', 'school-admin', 'accountant', 'reception'] as $role) {
            $this->actingAs($this->user($role))->get(route('dashboard.settings.school.edit'))->assertForbidden();
        }

        $this->actingAs($this->user('teacher'))->get(route('dashboard.settings.school.edit'))->assertRedirect('/login');
    }

    public function test_settings_save_and_branding_updates_immediately_without_finance_changes(): void
    {
        $before = [
            'invoices' => DB::table('invoices')->count(),
            'payments' => DB::table('invoice_payments')->count(),
            'tariffs' => DB::table('fee_prices')->count(),
        ];

        $this->actingAs($this->user('admin'))->put(route('dashboard.settings.school.update'), $this->payload([
            'school_name' => 'НОВОЕ НАЗВАНИЕ ШКОЛЫ',
            'phone_1' => '+20 111 111 1111',
            'email' => 'office@example.test',
        ]))->assertRedirect();

        $settings = SchoolSetting::current();
        $this->assertSame('НОВОЕ НАЗВАНИЕ ШКОЛЫ', $settings->school_name);
        $html = view('components.school-document-header', ['title' => 'Документ'])->render();
        $this->assertStringContainsString('НОВОЕ НАЗВАНИЕ ШКОЛЫ', $html);
        $this->assertStringContainsString('+20 111 111 1111', $html);
        $this->assertStringContainsString('office@example.test', $html);
        $this->assertSame($before['invoices'], DB::table('invoices')->count());
        $this->assertSame($before['payments'], DB::table('invoice_payments')->count());
        $this->assertSame($before['tariffs'], DB::table('fee_prices')->count());
    }

    public function test_logo_upload_and_replacement_update_the_shared_header(): void
    {
        Storage::fake('public');
        $admin = $this->user('admin');

        $this->actingAs($admin)->put(route('dashboard.settings.school.update'), $this->payload([
            'logo' => UploadedFile::fake()->image('first.png', 240, 120),
        ]))->assertRedirect();
        $first = SchoolSetting::current()->logo_path;
        Storage::disk('public')->assertExists($first);

        $this->actingAs($admin)->put(route('dashboard.settings.school.update'), $this->payload([
            'logo' => UploadedFile::fake()->image('second.png', 360, 180),
        ]))->assertRedirect();
        $second = SchoolSetting::current()->logo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertExists($second);
        $this->assertStringContainsString('data:image/png;base64,', view('components.school-document-header')->render());
    }

    public function test_existing_logo_is_displayed_and_saving_without_a_file_preserves_it(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/existing-logo.png', 'existing-logo-content');
        $settings = SchoolSetting::current();
        $settings->update([
            'logo_path' => 'branding/existing-logo.png',
            'printing_logo_path' => 'branding/existing-logo.png',
        ]);

        $this->actingAs($this->user('admin'))->get(route('dashboard.settings.school.edit'))
            ->assertOk()
            ->assertSee(Storage::disk('public')->url('branding/existing-logo.png'))
            ->assertSee('Оставьте поле пустым, чтобы сохранить текущий логотип.');

        $this->actingAs($this->user('admin'))->put(
            route('dashboard.settings.school.update'),
            $this->payload(),
        )->assertRedirect();

        $settings->refresh();
        $this->assertSame('branding/existing-logo.png', $settings->logo_path);
        $this->assertSame('branding/existing-logo.png', $settings->printing_logo_path);
        Storage::disk('public')->assertExists('branding/existing-logo.png');
    }

    public function test_logo_size_and_php_upload_failures_return_clear_russian_errors(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->put(route('dashboard.settings.school.update'), $this->payload([
            'logo' => UploadedFile::fake()->create('large-logo.png', 2049, 'image/png'),
        ]))->assertSessionHasErrors([
            'logo' => 'Размер логотипа не должен превышать 2 МБ.',
        ]);

        $temporary = UploadedFile::fake()->create('failed-logo.png', 10, 'image/png');
        $failedUpload = new UploadedFile(
            $temporary->getPathname(),
            'failed-logo.png',
            'image/png',
            UPLOAD_ERR_INI_SIZE,
            true,
        );

        $this->actingAs($admin)->put(route('dashboard.settings.school.update'), $this->payload([
            'logo' => $failedUpload,
        ]))->assertSessionHasErrors([
            'logo' => 'Не удалось загрузить логотип. Максимальный размер файла — 2 МБ.',
        ]);
    }

    public function test_shared_pdf_header_footer_and_missing_logo_fallback(): void
    {
        Storage::fake('public');
        SchoolSetting::current()->update(['logo_path' => 'missing.png', 'printing_logo_path' => 'missing.png']);

        $html = view('components.school-document-header', [
            'title' => 'Проверка документа',
            'academicYear' => '2026/2027',
            'footer' => true,
        ])->render();

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('ЦЕНТР «НАШИ ТРАДИЦИИ»', $html);
        $this->assertStringContainsString('Generated by School ERP', $html);
        $this->assertStringContainsString('Учебный год: 2026/2027', $html);
        $this->assertStringContainsString('Page ', $html);
        $this->assertStringStartsWith('%PDF-', Pdf::loadHTML('<html><body>'.$html.'</body></html>')->output());
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        $settings = SchoolSetting::current();

        return array_merge([
            'school_name' => $settings->school_name,
            'short_name' => $settings->short_name,
            'country' => $settings->country,
            'city' => $settings->city,
            'timezone' => $settings->timezone,
            'language' => 'ru',
            'phone_1' => $settings->phone_1,
            'phone_2' => $settings->phone_2,
            'email' => $settings->email,
            'website' => $settings->website,
            'address' => $settings->address,
            'header_color' => $settings->header_color,
            'footer_color' => $settings->footer_color,
            'print_date_enabled' => true,
            'page_numbers_enabled' => true,
            'currency' => 'EGP',
            'currency_symbol' => 'EGP',
            'decimal_places' => 2,
            'amount_format' => '1 234.56',
            'default_academic_year_id' => null,
            'school_year_start' => null,
            'school_year_end' => null,
        ], $overrides);
    }
}
