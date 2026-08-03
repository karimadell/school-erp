<?php

namespace Tests\Feature\Finance;

use Barryvdh\DomPDF\Facade\Pdf;
use Tests\TestCase;

class SchoolDocumentBrandingTest extends TestCase
{
    public function test_existing_school_logo_is_rendered_by_shared_branding(): void
    {
        $html = view('components.school-document-header', [
            'title' => 'Тестовый документ',
            'logoPath' => public_path('images/school-logo.png'),
        ])->render();

        $this->assertFileExists(public_path('images/school-logo.png'));
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('ЦЕНТР «НАШИ ТРАДИЦИИ»', $html);
        $this->assertStringContainsString('Русская школа в Египте', $html);
    }

    public function test_missing_logo_falls_back_to_school_identity_without_broken_image(): void
    {
        $html = view('components.school-document-header', [
            'title' => 'Тестовый документ',
            'logoPath' => public_path('images/missing-school-logo.png'),
        ])->render();

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
