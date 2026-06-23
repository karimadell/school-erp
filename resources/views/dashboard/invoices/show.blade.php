@extends('layouts.dashboard')

@section('content')

<div class="container-fluid py-4">

    {{-- HEADER --}}
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">

        <div>
            <h3 class="fw-bold mb-1">
                🧾 {{ __('invoices.invoice_number') }} #{{ $invoice->id }}
            </h3>

            <small class="text-muted">
                {{ $invoice->created_at?->format('Y-m-d H:i') }}
            </small>
        </div>

        <div class="d-flex gap-2 flex-wrap">

            <a href="{{ route('dashboard.invoices.print', $invoice) }}"
               target="_blank"
               class="btn btn-dark">
                🖨 {{ __('invoices.print') }}
            </a>

            <a href="{{ route('dashboard.invoices.pdf', $invoice) }}"
               class="btn btn-danger">
                📄 PDF
            </a>

            @php
                $studentName = $invoice->student?->name ?? $invoice->customer_name ?? '—';

                $studentPhone =
                    $invoice->student?->phone ??
                    $invoice->student?->mobile ??
                    $invoice->student?->whatsapp ??
                    '';

                $cleanPhone = preg_replace('/[^0-9]/', '', $studentPhone);

                if (!empty($cleanPhone) && str_starts_with($cleanPhone, '0')) {
                    $cleanPhone = '20' . substr($cleanPhone, 1);
                }

                $netAmount = max(
                    (float) ($invoice->total_amount ?? 0) - (float) ($invoice->discount_amount ?? 0),
                    0
                );

                $whatsappMessage = urlencode(
                    "🧾 Счёт №{$invoice->id}\n" .
                    "Ученик: {$studentName}\n" .
                    "Итого: " . number_format($invoice->total_amount ?? 0, 2) . "\n" .
                    "Скидка: " . number_format($invoice->discount_amount ?? 0, 2) . "\n" .
                    "К оплате: " . number_format($netAmount, 2) . "\n" .
                    "Оплачено: " . number_format($invoice->paid_amount ?? 0, 2) . "\n" .
                    "Остаток: " . number_format($invoice->remaining_amount ?? 0, 2) . "\n\n" .
                    "Спасибо!"
                );
            @endphp

            @if(!empty($cleanPhone))
                <a href="https://wa.me/{{ $cleanPhone }}?text={{ $whatsappMessage }}"
                   target="_blank"
                   class="btn btn-success">
                    🟢 WhatsApp
                </a>
            @else
                <a href="https://wa.me/?text={{ $whatsappMessage }}"
                   target="_blank"
                   class="btn btn-outline-success">
                    🟢 WhatsApp
                </a>
            @endif

            <a href="{{ route('dashboard.invoices.index') }}"
               class="btn btn-secondary">
                ← {{ __('invoices.back') }}
            </a>

        </div>
    </div>

    {{-- SUMMARY --}}
    <div class="row g-3 mb-4">

        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="text-muted">{{ __('invoices.total_amount') }}</div>
                    <h4 class="mb-0">
                        {{ number_format($invoice->total_amount, 2) }}
                    </h4>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="text-muted">{{ __('invoices.discount_amount') }}</div>
                    <h4 class="mb-0 text-warning">
                        {{ number_format($invoice->discount_amount ?? 0, 2) }}
                    </h4>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="text-muted">{{ __('invoices.paid_amount') }}</div>
                    <h4 class="mb-0 text-success">
                        {{ number_format($invoice->paid_amount, 2) }}
                    </h4>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="text-muted">{{ __('invoices.remaining_amount') }}</div>
                    <h4 class="mb-0 text-danger">
                        {{ number_format($invoice->remaining_amount, 2) }}
                    </h4>
                </div>
            </div>
        </div>

    </div>

    {{-- STUDENT INFO --}}
    <div class="card mb-4 shadow-sm border-0">

        <div class="card-header fw-bold">
            👨‍🎓 {{ __('invoices.student') }}
        </div>

        <div class="card-body">

            <div class="row g-3">

                <div class="col-md-4">
                    <strong>{{ __('invoices.student') }}:</strong>
                    {{ $invoice->student?->name ?? $invoice->customer_name ?? '—' }}
                </div>

                <div class="col-md-4">
                    <strong>{{ __('invoices.cash_account') }}:</strong>
                    {{ $invoice->cashAccount?->name ?? '—' }}
                </div>

                <div class="col-md-4">
                    <strong>{{ __('invoices.payment_method') }}:</strong>
                    {{ __('invoices.' . ($invoice->payment_method ?? 'cash')) }}
                </div>

                <div class="col-md-4">
                    <strong>{{ __('invoices.paid_at') }}:</strong>
                    {{ $invoice->paid_at?->format('Y-m-d H:i') ?? '—' }}
                </div>

                <div class="col-md-4">
                    <strong>{{ __('invoices.status') }}:</strong>

                    @if($invoice->status === 'paid')
                        <span class="badge bg-success">
                            {{ __('invoices.paid') }}
                        </span>

                    @elseif($invoice->status === 'partial')
                        <span class="badge bg-warning text-dark">
                            {{ __('invoices.partial') }}
                        </span>

                    @else
                        <span class="badge bg-danger">
                            {{ __('invoices.unpaid') }}
                        </span>
                    @endif
                </div>

                <div class="col-md-4">
                    <strong>{{ __('invoices.discount') }}:</strong>

                    @if(!empty($invoice->discount_type))
                        {{ $invoice->discount_type }}
                        —
                        {{ number_format($invoice->discount_value ?? 0, 2) }}
                    @else
                        —
                    @endif
                </div>

            </div>

        </div>
    </div>

    {{-- SERVICES --}}
    <div class="card mb-4 shadow-sm border-0">

        <div class="card-header fw-bold">
            📋 {{ __('invoices.services') }}
        </div>

        <div class="card-body p-0">

            <table class="table table-bordered mb-0 align-middle">

                <thead class="table-light">
                    <tr>
                        <th style="width:60px;">#</th>
                        <th>{{ __('invoices.service') }}</th>
                        <th>{{ __('invoices.category') ?? 'Категория' }}</th>
                        <th>{{ __('invoices.details') ?? 'Детали' }}</th>
                        <th style="width:180px;">
                            {{ __('invoices.amount') }}
                        </th>
                    </tr>
                </thead>

                <tbody>

                @php
                    $categoryLabels = [
                        'tuition' => 'Обучение',
                        'tuition_regular' => 'Очная форма обучения',
                        'tuition_family' => 'Семейное обучение',
                        'tuition_external' => 'Экстернат',
                        'registration' => 'Регистрационный взнос',
                        'uniform' => 'Школьная форма',
                        'transport' => 'Трансфер',
                        'food' => 'Питание',
                        'other' => 'Другое',
                    ];

                    $periodLabels = [
                        'once' => 'Единовременно',
                        'monthly' => 'Ежемесячно',
                        'weekly' => 'Еженедельно',
                        'daily' => 'Ежедневно',
                        'yearly' => 'Годовая оплата',
                        'quarterly' => 'Раз в 3 месяца',
                        'dynamic' => 'Динамически',
                    ];

                    $itemLabels = [
                        'full_set' => 'Комплект формы',
                        'tshirt' => 'Футболка',
                        'polo' => 'Поло',
                        'pants' => 'Брюки',
                        'jacket' => 'Куртка',
                    ];

                    $optionTypeLabels = [
                        'study' => 'Обучение',
                        'zone' => 'Зона трансфера',
                        'food_type' => 'Тип питания',
                        'uniform' => 'Форма',
                    ];

                    $optionValueLabels = [
                        'monthly' => 'Ежемесячно',
                        'quarterly' => 'Раз в 3 месяца',
                        'yearly' => 'Годовая оплата',
                        'weekly' => 'Еженедельно',
                        'daily' => 'Ежедневно',
                        'first_last_month' => 'Первый и последний месяц',
                    ];
                @endphp

                @forelse($invoice->fees as $fee)

                    @php
                        $details = [];

                        if (!empty($fee->pivot->item)) {
                            $details[] =
                                'Тип: ' .
                                ($itemLabels[$fee->pivot->item] ?? $fee->pivot->item);
                        }

                        if (!empty($fee->pivot->size)) {
                            $details[] =
                                'Размер: ' . $fee->pivot->size;
                        }

                        if (!empty($fee->payment_period)) {
                            $details[] =
                                'Период: ' .
                                ($periodLabels[$fee->payment_period] ?? $fee->payment_period);
                        }

                        if (!empty($fee->pivot->option_type)) {
                            $details[] =
                                'Опция: ' .
                                ($optionTypeLabels[$fee->pivot->option_type]
                                ?? $fee->pivot->option_type);
                        }

                        if (!empty($fee->pivot->option_value)) {
                            $parts = explode(' / ', $fee->pivot->option_value);

                            foreach ($parts as $part) {
                                $details[] =
                                    'Детали: ' .
                                    ($optionValueLabels[$part] ?? $part);
                            }
                        }

                        $categoryText =
                            $categoryLabels[$fee->category]
                            ?? $fee->category
                            ?? '—';
                    @endphp

                    <tr>

                        <td>{{ $loop->iteration }}</td>

                        <td>

                            <strong>
                                {{ $fee->name_ru ?? $fee->name ?? '—' }}
                            </strong>

                            @if(!empty($fee->payment_period))
                                <br>

                                <small class="text-muted">
                                    {{ $periodLabels[$fee->payment_period] ?? $fee->payment_period }}
                                </small>
                            @endif

                        </td>

                        <td>

                            <span class="badge bg-secondary">
                                {{ $categoryText }}
                            </span>

                        </td>

                        <td>

                            @if(count($details))

                                <ul class="mb-0 ps-3">

                                    @foreach($details as $detail)
                                        <li>{{ $detail }}</li>
                                    @endforeach

                                </ul>

                            @else
                                —
                            @endif

                        </td>

                        <td>
                            {{ number_format($fee->pivot->amount ?? 0, 2) }}
                        </td>

                    </tr>

                @empty

                    <tr>
                        <td colspan="5"
                            class="text-center text-muted py-4">
                            {{ __('invoices.no_data') }}
                        </td>
                    </tr>

                @endforelse

                </tbody>

                <tfoot class="table-light">

                    <tr>
                        <th colspan="4" class="text-end">
                            {{ __('invoices.total_amount') }}
                        </th>

                        <th>
                            {{ number_format($invoice->total_amount, 2) }}
                        </th>
                    </tr>

                    <tr>
                        <th colspan="4" class="text-end">
                            {{ __('invoices.discount_amount') }}
                        </th>

                        <th>
                            {{ number_format($invoice->discount_amount ?? 0, 2) }}
                        </th>
                    </tr>

                    <tr>
                        <th colspan="4" class="text-end">
                            {{ __('invoices.net_amount') }}
                        </th>

                        <th>
                            {{ number_format($netAmount, 2) }}
                        </th>
                    </tr>

                </tfoot>

            </table>

        </div>
    </div>

    {{-- RECEIVE PAYMENT --}}
    @if($invoice->status !== 'paid')

        <div class="card mb-4 shadow-sm border-0">

            <div class="card-header fw-bold">
                💰 {{ __('invoices.receive_payment') }}
            </div>

            <div class="card-body">

                <form method="POST"
                      action="{{ route('dashboard.invoices.pay', $invoice) }}">

                    @csrf

                    <div class="row g-3">

                        <div class="col-md-4">

                            <label class="form-label">
                                {{ __('invoices.payment_amount') }}
                            </label>

                            <input type="number"
                                   step="0.01"
                                   min="0.01"
                                   max="{{ $invoice->remaining_amount }}"
                                   name="amount"
                                   class="form-control"
                                   value="{{ $invoice->remaining_amount }}"
                                   required>

                        </div>

                        <div class="col-md-4">

                            <label class="form-label">
                                {{ __('invoices.payment_method') }}
                            </label>

                            <select name="payment_method"
                                    class="form-select"
                                    required>

                                <option value="cash">
                                    {{ __('invoices.cash') }}
                                </option>

                                <option value="bank">
                                    {{ __('invoices.bank') }}
                                </option>

                                <option value="card">
                                    {{ __('invoices.card') }}
                                </option>

                                <option value="transfer">
                                    {{ __('invoices.transfer') }}
                                </option>

                            </select>

                        </div>

                        <div class="col-md-4">

                            <label class="form-label">
                                {{ __('invoices.cash_account') }}
                            </label>

                            <select name="cash_account_id"
                                    class="form-select"
                                    required>

                                @foreach(\App\Models\CashAccount::orderBy('name')->get() as $account)

                                    <option value="{{ $account->id }}"
                                        @selected($invoice->cash_account_id == $account->id)>

                                        {{ $account->name }}

                                    </option>

                                @endforeach

                            </select>

                        </div>

                    </div>

                    <button class="btn btn-success mt-3">
                        💰 {{ __('invoices.receive_payment') }}
                    </button>

                </form>

            </div>
        </div>

    @endif

    {{-- REFUND --}}
    @if(($invoice->paid_amount ?? 0) > 0)

        <div class="card mb-4 shadow-sm border-0">

            <div class="card-header fw-bold">
                ↩️ {{ __('invoices.refund') ?? 'Refund' }}
            </div>

            <div class="card-body">

                <form method="POST"
                      action="{{ route('dashboard.invoices.refund', $invoice) }}">

                    @csrf

                    <div class="row g-3">

                        <div class="col-md-4">

                            <label class="form-label">
                                {{ __('invoices.refund_amount') ?? 'Refund amount' }}
                            </label>

                            <input type="number"
                                   step="0.01"
                                   min="0.01"
                                   max="{{ $invoice->paid_amount }}"
                                   name="amount"
                                   class="form-control"
                                   value="{{ $invoice->paid_amount }}">

                        </div>

                        <div class="col-md-4">

                            <label class="form-label">
                                {{ __('invoices.cash_account') }}
                            </label>

                            <select name="cash_account_id"
                                    class="form-select"
                                    required>

                                @foreach(\App\Models\CashAccount::orderBy('name')->get() as $account)

                                    <option value="{{ $account->id }}"
                                        @selected($invoice->cash_account_id == $account->id)>

                                        {{ $account->name }}

                                    </option>

                                @endforeach

                            </select>

                        </div>

                        <div class="col-md-4 d-flex align-items-end">

                            <button class="btn btn-outline-danger w-100">
                                ↩️ {{ __('invoices.refund') ?? 'Refund' }}
                            </button>

                        </div>

                    </div>

                </form>

            </div>
        </div>

    @endif

</div>

@endsection