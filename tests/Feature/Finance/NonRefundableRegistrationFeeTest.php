<?php

namespace Tests\Feature\Finance;

use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\InvoiceItem;
use App\Services\Finance\InvoicePaymentService;
use App\Models\SchoolSetting;
use Illuminate\Support\Str;

class NonRefundableRegistrationFeeTest extends FinanceOperationsTestCase
{
    public function test_service_metadata_and_invoice_item_snapshot_are_independent(): void
    {
        $fee = Fee::create(['name_ru'=>'Регистрационный взнос','category'=>Fee::CATEGORY_REGISTRATION,
            'type'=>'service','amount'=>'0.00','is_active'=>true,'is_non_refundable'=>true]);
        FeePrice::create(['fee_id'=>$fee->id,'academic_year_id'=>$this->year->id,'amount'=>'7000.00','currency'=>'EGP',
            'start_date'=>'2026-05-01','end_date'=>'2027-06-30','is_active'=>true]);

        $this->actingAs($this->accountant)->post(route('dashboard.students.invoices.store',$this->student), [
            'student_id'=>$this->student->id,'academic_year_id'=>$this->year->id,'pricing_date'=>'2026-08-01',
            'due_date'=>'2027-06-30','fees'=>[$fee->id],'notes'=>'Комментарий сотрудника',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $item = InvoiceItem::sole();
        $this->assertTrue($item->is_non_refundable);
        $fee->update(['is_non_refundable'=>false]);
        $this->assertTrue($item->fresh()->is_non_refundable);

        $other = Fee::create(['name_ru'=>'Книги','category'=>Fee::CATEGORY_BOOKS,'type'=>'service','amount'=>'100.00','is_active'=>true]);
        $falseSnapshot = InvoiceItem::create(['invoice_id'=>$item->invoice_id,'fee_id'=>$other->id,'description'=>'Книги',
            'unit_price'=>'100.00','quantity'=>1,'amount'=>'100.00','paid_amount'=>'0.00','remaining_amount'=>'100.00']);
        $this->assertFalse($falseSnapshot->is_non_refundable);
        $other->update(['is_non_refundable'=>true]);
        $this->assertFalse($falseSnapshot->fresh()->is_non_refundable);
    }

    public function test_invoice_and_receipt_documents_show_snapshot_notice_without_mutation(): void
    {
        $invoice = $this->invoice('7000.00');
        $invoice->update(['note'=>'Комментарий сотрудника']);
        $item = $invoice->items()->firstOrFail();
        $item->update(['is_non_refundable'=>true]);
        $payment = app(InvoicePaymentService::class)->record($invoice->id,$this->cash->id,'1000.00','cash',(string)Str::uuid(),$this->accountant);
        $snapshot = [$invoice->fresh()->toArray(),$item->fresh()->toArray(),$payment->fresh()->toArray()];

        $this->actingAs($this->accountant)->get(route('dashboard.invoices.show',$invoice))->assertOk()
            ->assertSee('Регистрационный взнос возврату не подлежит.')->assertSee('Комментарий сотрудника');
        $this->get(route('dashboard.invoices.print',$invoice))->assertOk()->assertSee('Регистрационный взнос возврату не подлежит.');
        $this->get(route('dashboard.invoices.pdf',$invoice))->assertOk()->assertHeader('content-type','application/pdf');
        $this->get(route('dashboard.payments.receipt',$payment))->assertOk()
            ->assertSee('Регистрационный взнос возврату не подлежит.')->assertSee('1000.00 EGP');
        $this->get(route('dashboard.payments.receipt.pdf',$payment))->assertOk()->assertHeader('content-type','application/pdf');
        $invoice->load(['items','student.grade','fees','cashAccount','payments.cashAccount','academicYear']);
        $this->assertStringContainsString('Регистрационный взнос возврату не подлежит.', view('dashboard.invoices.pdf',compact('invoice'))->render());
        $this->assertStringContainsString('Регистрационный взнос возврату не подлежит.', view('dashboard.finance.payments.receipt-pdf',[
            'payment'=>$payment->load(['cashAccount','creator']), 'invoice'=>$invoice, 'settings'=>SchoolSetting::current(),
            'previouslyPaid'=>'0.00', 'remainingAfter'=>'6000.00', 'methodLabels'=>['cash'=>'Наличные'],
        ])->render());
        $this->assertSame($snapshot,[$invoice->fresh()->toArray(),$item->fresh()->toArray(),$payment->fresh()->toArray()]);
    }

    public function test_documents_without_snapshot_do_not_show_notice(): void
    {
        $invoice = $this->invoice();
        $payment = app(InvoicePaymentService::class)->record($invoice->id,$this->cash->id,'100.00','cash',(string)Str::uuid(),$this->accountant);

        $this->actingAs($this->accountant)->get(route('dashboard.invoices.show',$invoice))->assertOk()
            ->assertDontSee('Регистрационный взнос возврату не подлежит.');
        $this->get(route('dashboard.payments.receipt',$payment))->assertOk()
            ->assertDontSee('Регистрационный взнос возврату не подлежит.');
    }

    public function test_service_form_can_mark_only_the_selected_service_non_refundable(): void
    {
        $fee = Fee::create(['name_ru'=>'Регистрационный взнос','category'=>Fee::CATEGORY_REGISTRATION,'type'=>'service','amount'=>'0.00','is_active'=>true]);
        $other = Fee::create(['name_ru'=>'Обучение','category'=>Fee::CATEGORY_TUITION,'type'=>'service','amount'=>'0.00','is_active'=>true]);
        $this->actingAs($this->accountant)->put(route('dashboard.finance.services.update',$fee), [
            'name_ru'=>$fee->name_ru,'category'=>$fee->category,'type'=>$fee->type,'is_active'=>'1','is_non_refundable'=>'1',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($fee->fresh()->is_non_refundable);
        $this->assertFalse($other->fresh()->is_non_refundable);
    }
}
