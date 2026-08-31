<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFinanceServiceRequest;
use App\Http\Requests\UpdateFinanceServiceRequest;
use App\Models\Fee;
use App\Models\PaymentPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class FinanceServiceController extends Controller
{
    public function __construct() { $this->middleware('permission:manage fees'); }

    public function index(): View
    {
        $services = Fee::query()->with(['prices.academicYear'])->withCount('prices')->orderBy('name_ru')->paginate(25);
        return view('dashboard.finance.services.index', compact('services'));
    }

    public function create(): View { return view('dashboard.finance.services.create', ['paymentPlans' => PaymentPlan::active()->orderBy('sort_order')->get()]); }

    public function store(StoreFinanceServiceRequest $request): RedirectResponse
    {
        $fee = Fee::create($request->safe()->except(['amount', 'base_price', 'billing_periods', 'payment_plan_ids']) + ['amount' => '0.00']);
        $this->syncBillingOptions($fee, $request);
        return redirect()->route('dashboard.finance.services.show', $fee)->with('success', 'Услуга создана. Добавьте первый тариф.');
    }

    public function show(Fee $fee): View
    {
        $fee->load(['prices' => fn ($q) => $q->with('academicYear')->orderByDesc('start_date')->orderByDesc('id'), 'billingPeriods', 'assignedPaymentPlans']);
        return view('dashboard.finance.services.show', compact('fee'));
    }

    public function edit(Fee $fee): View
    {
        $fee->load(['billingPeriods', 'assignedPaymentPlans']);
        return view('dashboard.finance.services.edit', ['fee' => $fee, 'paymentPlans' => PaymentPlan::active()->orderBy('sort_order')->get()]);
    }

    public function update(UpdateFinanceServiceRequest $request, Fee $fee): RedirectResponse
    {
        $fee->update($request->safe()->except(['amount', 'base_price', 'billing_periods', 'payment_plan_ids']));
        $this->syncBillingOptions($fee, $request);
        return redirect()->route('dashboard.finance.services.show', $fee)->with('success', 'Данные услуги обновлены. История тарифов не изменена.');
    }

    /**
     * Finance V2, Phase 2B — replace this Fee's allowed billing periods and
     * assigned custom PaymentPlan(s) with exactly what was submitted
     * (unchecked = removed, matching an ordinary form checkbox group).
     */
    private function syncBillingOptions(Fee $fee, StoreFinanceServiceRequest $request): void
    {
        $periods = $request->safe()->input('billing_periods', []) ?? [];
        $fee->billingPeriods()->delete();
        foreach (array_unique($periods) as $period) {
            $fee->billingPeriods()->create(['billing_period' => $period]);
        }

        $fee->assignedPaymentPlans()->sync($request->safe()->input('payment_plan_ids', []) ?? []);
    }
}
