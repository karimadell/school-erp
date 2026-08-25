<?php

namespace Tests\Feature\Finance;

use App\Models\SchoolSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SchoolDocumentBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_byte_logo_falls_back_to_the_bundled_school_logo(): void
    {
        // The uploaded (invalid, zero-byte) file is rejected on its own,
        // but documentLogoAsset() now falls back to the real bundled
        // public/images/school-logo.png (App\Models\SchoolSetting::
        // bundledLogoAsset()) rather than leaving the document logo-less.
        $disk = config('filesystems.uploads.public');
        Storage::fake($disk);
        Storage::disk($disk)->put('branding/empty.png', '');
        SchoolSetting::current()->update([
            'logo_path' => 'branding/empty.png',
            'printing_logo_path' => 'branding/empty.png',
        ]);

        $html = view('components.school-document-header', ['title' => 'Тестовый документ'])->render();

        $this->assertNull(SchoolSetting::current()->resolveBrandingAsset('branding/empty.png'));
        $asset = SchoolSetting::current()->documentLogoAsset();
        $this->assertNotNull($asset);
        $this->assertSame('images/school-logo.png', $asset['path']);
        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('ЦЕНТР «НАШИ ТРАДИЦИИ»', $html);
    }

    public function test_valid_jpg_printing_logo_uses_its_detected_mime_type(): void
    {
        $disk = config('filesystems.uploads.public');
        $jpg = file_get_contents(storage_path('app/public/branding/ka8lgzQDsFdfIVSWqqZ4q4pgkziGLmwHvjBsYhj9.jpg'));
        Storage::fake($disk);
        Storage::disk($disk)->put('branding/approved-logo.jpg', $jpg);
        SchoolSetting::current()->update([
            'logo_path' => null,
            'printing_logo_path' => 'branding/approved-logo.jpg',
        ]);

        $html = view('components.school-document-header', ['title' => 'Тестовый документ'])->render();
        $asset = SchoolSetting::current()->documentLogoAsset();

        $this->assertSame('image/jpeg', $asset['mime_type']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $asset['data_uri']);
        $this->assertStringContainsString('data:image/jpeg;base64,', $html);
        $this->assertStringNotContainsString('data:image/png;base64,', $html);
    }

    public function test_valid_png_stamp_and_missing_optional_signature_resolve_safely(): void
    {
        $disk = config('filesystems.uploads.public');
        $png = file_get_contents(storage_path('app/public/branding/RFRJ4ke6qPH0As79aPEBE84u0V70bJbX3wcWGNuI.png'));
        Storage::fake($disk);
        Storage::disk($disk)->put('branding/approved-stamp.png', $png);
        SchoolSetting::current()->update([
            'stamp_path' => 'branding/approved-stamp.png',
            'director_signature_path' => null,
        ]);

        $settings = SchoolSetting::current();

        $this->assertSame('image/png', $settings->stampAsset()['mime_type']);
        $this->assertStringStartsWith('data:image/png;base64,', $settings->stampAsset()['data_uri']);
        $this->assertNull($settings->directorSignatureAsset());
        $this->assertNull($settings->directorSignatureUrl());
    }

    public function test_shared_pdf_branding_rejects_corrupt_content_and_renders_valid_jpg(): void
    {
        $disk = config('filesystems.uploads.public');
        $jpg = file_get_contents(storage_path('app/public/branding/ka8lgzQDsFdfIVSWqqZ4q4pgkziGLmwHvjBsYhj9.jpg'));
        Storage::fake($disk);
        Storage::disk($disk)->put('branding/corrupt.webp', 'not an image');
        Storage::disk($disk)->put('branding/approved-logo.jpg', $jpg);
        $settings = SchoolSetting::current();

        $this->assertNull($settings->resolveBrandingAsset('branding/corrupt.webp'));
        $settings->update(['printing_logo_path' => 'branding/approved-logo.jpg']);

        $html = '<html lang="ru"><body>'.view('components.school-document-header', [
            'title' => 'Проверка PDF',
            'academicYear' => '2026/2027',
            'footer' => true,
        ])->render().'</body></html>';

        $output = Pdf::loadHTML($html)->output();

        $this->assertStringContainsString('data:image/jpeg;base64,', $html);
        $this->assertStringStartsWith('%PDF-', $output);
        $this->assertGreaterThan(1000, strlen($output));
    }
}
