<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFinanceServiceRequest;
use App\Http\Requests\UpdateFinanceServiceRequest;
use App\Models\Fee;
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

    public function create(): View { return view('dashboard.finance.services.create'); }

    public function store(StoreFinanceServiceRequest $request): RedirectResponse
    {
        $fee = Fee::create($request->safe()->except(['amount', 'base_price']) + ['amount' => '0.00']);
        return redirect()->route('dashboard.finance.services.show', $fee)->with('success', 'Услуга создана. Добавьте первый тариф.');
    }

    public function show(Fee $fee): View
    {
        $fee->load(['prices' => fn ($q) => $q->with('academicYear')->orderByDesc('start_date')->orderByDesc('id')]);
        return view('dashboard.finance.services.show', compact('fee'));
    }

    public function edit(Fee $fee): View { return view('dashboard.finance.services.edit', compact('fee')); }

    public function update(UpdateFinanceServiceRequest $request, Fee $fee): RedirectResponse
    {
        $fee->update($request->safe()->except(['amount', 'base_price']));
        return redirect()->route('dashboard.finance.services.show', $fee)->with('success', 'Данные услуги обновлены. История тарифов не изменена.');
    }
}
