<?php

namespace Tests\Feature\Finance;

use App\Models\SchoolSetting;
use Illuminate\Support\Facades\Storage;

class InvoiceBrandingIntegrityTest extends FinanceOperationsTestCase
{
    public function test_invoice_pdf_template_resolves_approved_jpg_branding(): void
    {
        $disk = config('filesystems.uploads.public');
        $jpg = file_get_contents(storage_path('app/public/branding/ka8lgzQDsFdfIVSWqqZ4q4pgkziGLmwHvjBsYhj9.jpg'));
        Storage::fake($disk);
        Storage::disk($disk)->put('branding/approved-logo.jpg', $jpg);
        SchoolSetting::current()->update([
            'logo_path' => 'branding/approved-logo.jpg',
            'printing_logo_path' => 'branding/approved-logo.jpg',
            'stamp_path' => null,
            'director_signature_path' => null,
        ]);

        $invoice = $this->invoice()->load([
            'student.grade',
            'academicYear',
            'items.fee',
            'payments',
        ]);
        $html = view('dashboard.invoices.pdf', compact('invoice'))->render();

        $this->assertStringContainsString('data:image/jpeg;base64,', $html);
        $this->assertStringNotContainsString('Официальная печать школы', $html);
        $this->assertStringNotContainsString('Подпись директора', $html);

        $this->actingAs($this->accountant)
            ->get(route('dashboard.invoices.pdf', $invoice))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
