<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentPlanRequest;
use App\Models\InvoiceInstallment;
use App\Models\PaymentPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PaymentPlanController extends Controller
{
    public function __construct() { $this->middleware('permission:manage invoices'); }
    public function index(): View { return view('dashboard.finance.payment-plans.index',['plans'=>PaymentPlan::withCount('installments')->orderBy('sort_order')->get()]); }
    public function create(): View { return view('dashboard.finance.payment-plans.form',['plan'=>new PaymentPlan]); }
    public function store(StorePaymentPlanRequest $request): RedirectResponse { $plan=$this->save(new PaymentPlan,$request->validated()); return redirect()->route('dashboard.finance.payment-plans.edit',$plan)->with('success','План оплаты создан.'); }
    public function edit(PaymentPlan $paymentPlan): View { return view('dashboard.finance.payment-plans.form',['plan'=>$paymentPlan->load('installments')]); }
    public function update(StorePaymentPlanRequest $request, PaymentPlan $paymentPlan): RedirectResponse { $this->save($paymentPlan,$request->validated()); return back()->with('success','План оплаты сохранён. Существующие графики счетов не изменены.'); }
    public function reports(): View
    {
        $installments=InvoiceInstallment::with(['invoice.student','payments'])->orderBy('due_date')->get();
        return view('dashboard.finance.installments.index',compact('installments'));
    }
    private function save(PaymentPlan $plan,array $data): PaymentPlan
    {
        return DB::transaction(function()use($plan,$data){$plan->fill(collect($data)->except('installments')->all())->save();$plan->installments()->delete();foreach($data['installments'] as $i=>$row)$plan->installments()->create($row+['sequence'=>$i+1]);return $plan;});
    }
}
