<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">

    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #000;
        }

        .header {
            width: 100%;
            border-bottom: 2px solid #000;
            padding-bottom: 12px;
            margin-bottom: 15px;
        }

        .title {
            font-size: 24px;
            font-weight: bold;
            text-align: right;
        }

        .school {
            font-size: 13px;
            line-height: 1.5;
        }

        .info-table,
        .items-table,
        .summary-table,
        .payments-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }

        .info-table td,
        .items-table th,
        .items-table td,
        .summary-table td,
        .payments-table th,
        .payments-table td {
            border: 1px solid #555;
            padding: 7px;
            vertical-align: top;
        }

        .items-table th,
        .payments-table th {
            background: #f2f2f2;
            font-weight: bold;
        }

        .section-title {
            font-size: 15px;
            font-weight: bold;
            margin-top: 18px;
            margin-bottom: 6px;
        }

        .right {
            text-align: right;
        }

        .center {
            text-align: center;
        }

        .summary-table {
            width: 45%;
            margin-left: auto;
        }

        .bold {
            font-weight: bold;
        }

        .small {
            font-size: 10px;
            color: #333;
        }

        ul {
            margin: 0;
            padding-left: 14px;
        }
    </style>
</head>

<body>

@php
    $invoiceNumber = 'INV-' . $invoice->created_at?->format('Y') . '-' . str_pad($invoice->id, 4, '0', STR_PAD_LEFT);

    $statusText = match ($invoice->status) {
        'paid' => 'Оплачен',
        'partial' => 'Частично оплачен',
        default => 'Не оплачен',
    };

    $paymentMethodText = match ($invoice->payment_method) {
        'cash' => 'Наличные',
        'bank' => 'Банк',
        'card' => 'Карта',
        'transfer' => 'Перевод',
        default => $invoice->payment_method ?? '—',
    };

    $categoryLabels = [
        'tuition' => 'Обучение',
        'tuition_regular' => 'Очная форма обучения',
        'tuition_family' => 'Семейная форма обучения',
        'tuition_external' => 'Экстернат',
        'registration' => 'Регистрационный взнос',
        'uniform' => 'Школьная форма',
        'transport' => 'Транспорт',
        'food' => 'Питание',
        'extra_classes' => 'Дополнительные занятия',
        'books' => 'Книги',
        'activity' => 'Активности',
        'other' => 'Другое',
    ];

    $periodLabels = [
        'once' => 'Единовременно',
        'monthly' => 'Ежемесячно',
        'weekly' => 'Еженедельно',
        'daily' => 'Ежедневно',
        'yearly' => 'Годовая оплата',
        'quarterly' => 'Каждые 3 месяца',
        'term' => 'За четверть',
        'package' => 'Пакет',
        'dynamic' => 'Динамически',
    ];

    $optionTypeLabels = [
        'study' => 'Обучение',
        'zone' => 'Зона трансфера',
        'food_type' => 'Тип питания',
        'hours' => 'Часы',
        'uniform' => 'Форма',
    ];

    $optionValueLabels = [
        'daily' => 'Ежедневно',
        'weekly' => 'Еженедельно',
        'monthly' => 'Ежемесячно',
        'yearly' => 'Годовая оплата',
        'full_day' => 'Полный день',
        'half_day' => 'Половина дня',
        'first_last_month' => 'Первый и последний месяц',
    ];

    $itemLabels = [
        'tshirt' => 'Футболка',
        'polo' => 'Поло',
        'jacket' => 'Куртка',
        'full_set' => 'Комплект',
        'pants' => 'Брюки',
        'skirt' => 'Юбка',
    ];

    $studentName = $invoice->student?->name ?? $invoice->customer_name ?? '—';
    $studentPhone = $invoice->student?->phone ?? '—';
    $studentGrade = $invoice->student?->grade?->name_ru ?? $invoice->student?->grade?->name ?? '—';
    $netAmount = ($invoice->total_amount ?? 0) - ($invoice->discount_amount ?? 0);
@endphp

<table class="header">
    <tr>
        <td class="school">
            <strong>Русская школа «Наши традиции»</strong><br>
            Египет, Хургада, район Мубарак 6, вилла 219<br>
            Тел.: 01062172809 / 01577782735<br>
            Email: info@school-erp.com
        </td>
        <td class="title">
            СЧЁТ / INVOICE<br>
            <span style="font-size:13px;">{{ $invoiceNumber }}</span>
        </td>
    </tr>
</table>

<div class="section-title">Информация о счете</div>

<table class="info-table">
    <tr>
        <td><strong>Дата:</strong> {{ $invoice->created_at?->format('d.m.Y H:i') }}</td>
        <td><strong>Статус:</strong> {{ $statusText }}</td>
    </tr>
    <tr>
        <td><strong>Способ оплаты:</strong> {{ $paymentMethodText }}</td>
        <td><strong>Касса:</strong> {{ $invoice->cashAccount?->name ?? '—' }}</td>
    </tr>
</table>

<div class="section-title">Информация о клиенте</div>

<table class="info-table">
    <tr>
        <td><strong>Ученик:</strong> {{ $studentName }}</td>
        <td><strong>Класс:</strong> {{ $studentGrade }}</td>
    </tr>
    <tr>
        <td><strong>Телефон:</strong> {{ $studentPhone }}</td>
        <td><strong>Примечание:</strong> {{ $invoice->note ?? '—' }}</td>
    </tr>
</table>

<div class="section-title">Услуги и платежи</div>

<table class="items-table">
    <thead>
        <tr>
            <th class="center" style="width:35px;">#</th>
            <th>Услуга</th>
            <th>Категория</th>
            <th>Детали</th>
            <th class="right" style="width:90px;">Сумма</th>
        </tr>
    </thead>

    <tbody>
        @forelse($invoice->fees as $fee)
            @php
                $details = [];

                if (!empty($fee->pivot->item)) {
                    $details[] = 'Тип: ' . ($itemLabels[$fee->pivot->item] ?? $fee->pivot->item);
                }

                if (!empty($fee->pivot->size)) {
                    $details[] = 'Размер: ' . $fee->pivot->size;
                }

                if (!empty($fee->payment_period)) {
                    $details[] = 'Период: ' . ($periodLabels[$fee->payment_period] ?? $fee->payment_period);
                }

                if (!empty($fee->pivot->option_type)) {
                    $details[] = 'Опция: ' . ($optionTypeLabels[$fee->pivot->option_type] ?? $fee->pivot->option_type);
                }

                if (!empty($fee->pivot->option_value)) {
                    $parts = explode(' / ', $fee->pivot->option_value);

                    foreach ($parts as $part) {
                        $details[] = 'Детали: ' . ($optionValueLabels[$part] ?? $part);
                    }
                }

                $categoryText = $categoryLabels[$fee->category] ?? $fee->category ?? '—';
            @endphp

            <tr>
                <td class="center">{{ $loop->iteration }}</td>

                <td>
                    <strong>{{ $fee->name_ru ?? $fee->name ?? '—' }}</strong>
                </td>

                <td>{{ $categoryText }}</td>

                <td>
                    @if(count($details))
                        <ul>
                            @foreach($details as $detail)
                                <li>{{ $detail }}</li>
                            @endforeach
                        </ul>
                    @else
                        —
                    @endif
                </td>

                <td class="right">{{ number_format($fee->pivot->amount ?? 0, 2) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="5" class="center">Нет данных</td>
            </tr>
        @endforelse
    </tbody>
</table>

<table class="summary-table">
    <tr>
        <td>Итого:</td>
        <td class="right">{{ number_format($invoice->total_amount ?? 0, 2) }}</td>
    </tr>
    <tr>
        <td>Скидка:</td>
        <td class="right">{{ number_format($invoice->discount_amount ?? 0, 2) }}</td>
    </tr>
    <tr>
        <td>Оплачено:</td>
        <td class="right">{{ number_format($invoice->paid_amount ?? 0, 2) }}</td>
    </tr>
    <tr>
        <td>Остаток:</td>
        <td class="right">{{ number_format($invoice->remaining_amount ?? 0, 2) }}</td>
    </tr>
    <tr>
        <td class="bold">К оплате:</td>
        <td class="right bold">{{ number_format($netAmount, 2) }}</td>
    </tr>
</table>

@if($invoice->payments && $invoice->payments->count())
    <div class="section-title">История оплат</div>

    <table class="payments-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Дата и время</th>
                <th>Сумма</th>
                <th>Способ оплаты</th>
                <th>Касса</th>
                <th>Примечание</th>
            </tr>
        </thead>

        <tbody>
            @foreach($invoice->payments as $payment)
                @php
                    $paymentMethod = match ($payment->payment_method) {
                        'cash' => 'Наличные',
                        'bank' => 'Банк',
                        'card' => 'Карта',
                        'transfer' => 'Перевод',
                        'refund' => 'Возврат',
                        default => $payment->payment_method ?? '—',
                    };
                @endphp

                <tr>
                    <td class="center">{{ $loop->iteration }}</td>
                    <td>{{ $payment->paid_at?->format('d.m.Y H:i') ?? '—' }}</td>
                    <td class="right">{{ number_format($payment->amount ?? 0, 2) }}</td>
                    <td>{{ $paymentMethod }}</td>
                    <td>{{ $payment->cashAccount?->name ?? '—' }}</td>
                    <td>{{ $payment->reference ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<br><br>

<table style="width:100%;">
    <tr>
        <td>
            Подпись кассира: ______________________
        </td>
        <td class="right">
            Спасибо, что выбрали нашу школу!
        </td>
    </tr>
</table>

</body>
</html>