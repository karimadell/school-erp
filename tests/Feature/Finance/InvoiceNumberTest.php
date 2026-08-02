<?php

namespace Tests\Feature\Finance;

use App\Models\Invoice;
use App\Models\Student;
use LogicException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceNumberTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(): Invoice
    {
        $student = Student::create(['name' => 'Ученик']);
        $invoice = Invoice::create([
            'student_id' => $student->id,
            'subtotal_amount' => '100.00', 'total_amount' => '100.00',
            'paid_amount' => '0.00', 'remaining_amount' => '100.00',
            'status' => Invoice::STATUS_UNPAID,
        ]);
        $invoice->invoice_number = Invoice::numberFor($invoice->id, 2026);
        $invoice->save();

        return $invoice;
    }

    public function test_numbers_are_stable_padded_and_unique(): void
    {
        $first = $this->invoice();
        $second = $this->invoice();

        $this->assertMatchesRegularExpression('/^INV-2026-\d{6}$/', $first->invoice_number);
        $this->assertSame('INV-2026-'.str_pad((string) $first->id, 6, '0', STR_PAD_LEFT), $first->invoice_number);
        $this->assertNotSame($first->invoice_number, $second->invoice_number);
        $first->update(['customer_name' => 'Сохранённый номер']);
        $this->assertSame($first->invoice_number, $first->fresh()->invoice_number);
    }

    public function test_assigned_number_cannot_be_changed(): void
    {
        $invoice = $this->invoice();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Номер счёта нельзя изменить после присвоения.');

        $invoice->update(['invoice_number' => 'INV-2026-999999']);
    }
}
