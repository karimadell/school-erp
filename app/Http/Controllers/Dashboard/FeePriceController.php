<?php

namespace App\Http\Controllers\Dashboard;

use App\Filament\Resources\FeePrices\FeePriceResource;
use App\Http\Controllers\Controller;
use App\Models\FeePrice;
use Illuminate\Http\RedirectResponse;

class FeePriceController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage fee prices');
    }

    public function index(): RedirectResponse
    {
        return redirect()->to(FeePriceResource::getUrl('index'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->to(FeePriceResource::getUrl('create'));
    }

    public function edit(FeePrice $feePrice): RedirectResponse
    {
        return redirect()->to(FeePriceResource::getUrl('edit', ['record' => $feePrice]));
    }

    public function store(): never
    {
        abort(410, 'Создание цен перенесено в единый раздел «Цены на услуги».');
    }

    public function update(): never
    {
        abort(410, 'Изменение цен перенесено в единый раздел «Цены на услуги».');
    }

    public function destroy(): never
    {
        abort(403, 'Исторические цены нельзя удалять.');
    }
}
