<?php

namespace App\Services\Finance;

use App\Models\Invoice;
use App\Models\InvoiceInstallment;
use App\Models\PaymentPlan;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class InstallmentPlanService
{
    public function generate(Invoice $invoice, PaymentPlan $plan, string $baseDate, ?array $manualAmounts = null): void
    {
        $segments = $plan->installments()->get();
        if ($segments->isEmpty()) throw ValidationException::withMessages(['payment_plan_id'=>'В выбранном плане отсутствуют этапы оплаты.']);
        $total = bcadd((string)$invoice->total_amount, '0', 2);
        $allocated = '0.00';
        foreach ($segments as $index => $segment) {
            $last = $index === $segments->count() - 1;
            $amount = isset($manualAmounts[$index]) ? bcadd((string)$manualAmounts[$index], '0', 2)
                : ($last ? bcsub($total,$allocated,2) : bcdiv(bcmul($total,(string)$segment->percentage,4),'100',2));
            if (bccomp($amount,'0.00',2) <= 0) throw ValidationException::withMessages(['installments'=>'Сумма каждого этапа должна быть больше нуля.']);
            $allocated = bcadd($allocated,$amount,2);
            InvoiceInstallment::create(['invoice_id'=>$invoice->id,'payment_plan_id'=>$plan->id,'name_ru'=>$segment->name_ru,
                'sequence'=>$segment->sequence,'due_date'=>Carbon::parse($baseDate)->addDays($segment->offset_days),
                'amount'=>$amount,'paid_amount'=>'0.00','remaining_amount'=>$amount,'status'=>'pending']);
        }
        if (bccomp($allocated,$total,2)!==0) throw ValidationException::withMessages(['installments'=>'Сумма этапов должна совпадать с итогом счёта.']);
    }

    public function generateSingle(Invoice $invoice, string $dueDate): void
    {
        InvoiceInstallment::create(['invoice_id'=>$invoice->id,'name_ru'=>'Полная оплата','sequence'=>1,'due_date'=>$dueDate,
            'amount'=>$invoice->total_amount,'paid_amount'=>'0.00','remaining_amount'=>$invoice->total_amount,'status'=>'pending']);
    }
}
