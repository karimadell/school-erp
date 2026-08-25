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
 */
class SchoolBrandingConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private string $bundledLogoPath;

    private string $bundledLogoBackup;

    protected function setUp(): void
    {
        parent::setUp();
        // public/images/school-logo.png ships as an empty 0-byte
        // placeholder in this repository (confirmed via `git cat-file -s`
        // on every commit since the initial upload) — a real logo image
        // was never actually committed there. Since the bundled fallback
        // deliberately reads straight off disk via public_path() (so it
        // never depends on Storage/upload-disk configuration), there is
        // no virtual-filesystem seam to fake it through in a test. To
        // exercise the fallback mechanism itself, these tests briefly
        // swap in a real fixture image and always restore the original
        // (empty) bytes in tearDown(), so the repository is never left
        // mutated.
        $this->bundledLogoPath = public_path('images/school-logo.png');
        $this->bundledLogoBackup = (string) file_get_contents($this->bundledLogoPath);
    }

    protected function tearDown(): void
    {
        file_put_contents($this->bundledLogoPath, $this->bundledLogoBackup);
        parent::tearDown();
    }

    private function useRealBundledFixture(): void
    {
        $jpg = file_get_contents(storage_path('app/public/branding/ka8lgzQDsFdfIVSWqqZ4q4pgkziGLmwHvjBsYhj9.jpg'));
        file_put_contents($this->bundledLogoPath, $jpg);
    }

    public function test_bundled_logo_is_used_when_no_valid_uploaded_logo_exists(): void
    {
        $this->useRealBundledFixture();

        $asset = SchoolSetting::current()->documentLogoAsset();

        $this->assertNotNull($asset);
        $this->assertSame('images/school-logo.png', $asset['path']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $asset['data_uri']);
    }

    public function test_uploaded_logo_still_overrides_the_bundled_fallback(): void
    {
        $this->useRealBundledFixture();

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
        $this->useRealBundledFixture();

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('data:image/jpeg;base64,', false);
    }

    public function test_unified_dashboard_shell_shows_the_school_logo(): void
    {
        $this->useRealBundledFixture();

        (new RolesAndPermissionsSeeder)->run();
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        $this->actingAs($user)->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee('data:image/jpeg;base64,', false);
    }

    public function test_school_logo_component_renders_nothing_when_truly_no_asset_resolves(): void
    {
        // Bundled file left as the real empty placeholder (no
        // useRealBundledFixture() call) and nothing uploaded: the
        // component must degrade to no <img>, not a broken one.
        $html = (string) $this->view('components.school-logo');

        $this->assertStringNotContainsString('<img', $html);
    }
}
