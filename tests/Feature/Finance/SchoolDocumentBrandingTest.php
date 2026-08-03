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

    public function test_existing_school_logo_is_rendered_by_shared_branding(): void
    {
        $html = view('components.school-document-header', ['title' => 'Тестовый документ'])->render();

        $this->assertFileExists(public_path('images/school-logo.png'));
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('ЦЕНТР «НАШИ ТРАДИЦИИ»', $html);
        $this->assertStringContainsString('Русская школа в Египте', $html);
        $this->assertStringContainsString('+20 106 553 6448', $html);
        $this->assertStringContainsString('nashitradicii@gmail.com', $html);
    }

    public function test_missing_logo_falls_back_to_school_identity_without_broken_image(): void
    {
        SchoolSetting::current()->update([
            'logo_path' => 'branding/missing.png',
            'printing_logo_path' => 'branding/missing.png',
        ]);
        $html = view('components.school-document-header', ['title' => 'Тестовый документ'])->render();

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('ЦЕНТР «НАШИ ТРАДИЦИИ»', $html);
        $this->assertStringContainsString('Русская школа в Египте', $html);
    }

    public function test_shared_branding_can_be_rendered_by_dompdf(): void
    {
        $html = '<html lang="ru"><body>'.view('components.school-document-header', [
            'title' => 'Проверка PDF',
            'academicYear' => '2026/2027',
            'footer' => true,
        ])->render().'</body></html>';

        $output = Pdf::loadHTML($html)->output();

        $this->assertStringStartsWith('%PDF-', $output);
        $this->assertGreaterThan(1000, strlen($output));
    }
}
