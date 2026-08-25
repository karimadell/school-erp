<?php

namespace Tests\Feature\Branding;

use App\Models\SchoolSetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression coverage for the app-wide school-logo consistency work:
 * SchoolSetting::documentLogoAsset() is the single central place the
 * fallback (printing_logo_path -> logo_path -> bundled asset) lives, and
 * <x-school-logo> is the single Blade component every surface (login,
 * dashboard shell, financial documents) renders it through.
 *
 * public/images/school-logo.png is now the real official logo, so
 * create_school_settings_table's migration-time copy into storage (as
 * branding/school-logo.png, set as the default printing_logo_path on a
 * fresh migration) succeeds too. Tests that want to exercise the
 * *bundled* fallback branch specifically (as opposed to that
 * migration-seeded default) explicitly clear printing_logo_path/
 * logo_path first.
 */
class SchoolBrandingConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private string $bundledLogoPath;

    private string $bundledLogoBackup;

    protected function setUp(): void
    {
        parent::setUp();
        // Restored verbatim in tearDown() regardless of what a test does
        // to it, so this file is never left mutated by a test run.
        $this->bundledLogoPath = public_path('images/school-logo.png');
        $this->bundledLogoBackup = (string) file_get_contents($this->bundledLogoPath);
    }

    protected function tearDown(): void
    {
        file_put_contents($this->bundledLogoPath, $this->bundledLogoBackup);
        parent::tearDown();
    }

    private function clearUploadedLogo(): void
    {
        SchoolSetting::current()->update(['printing_logo_path' => null, 'logo_path' => null]);
    }

    public function test_bundled_logo_is_used_when_no_valid_uploaded_logo_exists(): void
    {
        $this->clearUploadedLogo();

        $asset = SchoolSetting::current()->documentLogoAsset();

        $this->assertNotNull($asset);
        $this->assertSame('images/school-logo.png', $asset['path']);
        $this->assertSame('image/png', $asset['mime_type']);
        $this->assertStringStartsWith('data:image/png;base64,', $asset['data_uri']);
    }

    public function test_uploaded_logo_still_overrides_the_bundled_fallback(): void
    {
        $disk = config('filesystems.uploads.public');
        Storage::fake($disk);
        $png = file_get_contents(storage_path('app/public/branding/RFRJ4ke6qPH0As79aPEBE84u0V70bJbX3wcWGNuI.png'));
        Storage::disk($disk)->put('branding/uploaded-logo.png', $png);
        SchoolSetting::current()->update(['printing_logo_path' => 'branding/uploaded-logo.png']);

        $asset = SchoolSetting::current()->documentLogoAsset();

        $this->assertNotNull($asset);
        $this->assertSame('branding/uploaded-logo.png', $asset['path']);
        $this->assertSame('image/png', $asset['mime_type']);
    }

    public function test_login_page_shows_the_school_logo(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('data:image/png;base64,', false);
    }

    public function test_unified_dashboard_shell_shows_the_school_logo(): void
    {
        (new RolesAndPermissionsSeeder)->run();
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        $this->actingAs($user)->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee('data:image/png;base64,', false);
    }

    public function test_school_logo_component_renders_nothing_when_truly_no_asset_resolves(): void
    {
        $this->clearUploadedLogo();
        file_put_contents($this->bundledLogoPath, '');

        $html = (string) $this->view('components.school-logo');

        $this->assertStringNotContainsString('<img', $html);
    }
}
