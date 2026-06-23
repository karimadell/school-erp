{{-- resources/views/dashboard/invoices/print.blade.php --}}

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Счёт № {{ $invoice->id }}</title>

    <style>
        *{
            box-sizing:border-box;
        }

        body{
            font-family: DejaVu Sans, Arial, sans-serif;
            background:#fff;
            color:#000;
            margin:0;
            padding:28px;
            font-size:13px;
            line-height:1.45;
        }

        .watermark{
            position:fixed;
            top:42%;
            left:50%;
            transform:translate(-50%, -50%) rotate(-28deg);
            font-size:86px;
            font-weight:900;
            color:rgba(0,0,0,.08);
            z-index:0;
            white-space:nowrap;
            pointer-events:none;
        }

        .page{
            width:100%;
            max-width:1050px;
            margin:0 auto;
            background:transparent;
            position:relative;
            z-index:1;
        }

        .top{
            display:table;
            width:100%;
            border-bottom:2px solid #000;
            padding-bottom:18px;
            margin-bottom:18px;
        }

        .school{
            display:table-cell;
            width:60%;
            vertical-align:top;
        }

        .school-row{
            display:table;
            width:100%;
        }

        .logo-box{
            display:table-cell;
            width:95px;
            vertical-align:top;
            padding-right:18px;
        }

        .logo{
            width:82px;
            height:82px;
            object-fit:contain;
        }

        .school-text{
            display:table-cell;
            vertical-align:top;
        }

        .school h1{
            font-size:28px;
            line-height:1.15;
            margin:0 0 8px;
            font-weight:800;
        }

        .school p{
            margin:3px 0;
            font-size:13px;
        }

        .invoice-head{
            display:table-cell;
            width:40%;
            text-align:right;
            vertical-align:top;
        }

        .invoice-head h2{
            font-size:34px;
            margin:0 0 12px;
            font-weight:900;
            letter-spacing:1px;
        }

        .meta-table{
            width:100%;
            border-collapse:collapse;
            margin-left:auto;
        }

        .meta-table td{
            padding:4px 0;
            border-bottom:1px solid #999;
            font-size:13px;
        }

        .meta-table td:first-child{
            text-align:left;
            color:#222;
        }

        .meta-table td:last-child{
            text-align:right;
            font-weight:700;
        }

        .section-title{
            font-size:15px;
            font-weight:800;
            text-transform:uppercase;
            margin:18px 0 8px;
        }

        .client-box{
            width:100%;
            border:1px solid #777;
            border-collapse:collapse;
            margin-bottom:18px;
        }

        .client-box td{
            width:50%;
            padding:9px 12px;
            border:1px solid #777;
            vertical-align:top;
        }

        .label{
            display:inline-block;
            min-width:120px;
            font-weight:700;
        }

        .value{
            font-weight:700;
        }

        .items{
            width:100%;
            border-collapse:collapse;
            margin-top:8px;
        }

        .items th{
            border:1px solid #555;
            padding:8px 9px;
            background:#f2f2f2;
            font-weight:800;
            text-align:left;
        }

        .items td{
            border:1px solid #555;
            padding:8px 9px;
            vertical-align:top;
        }

        .items .num{
            width:45px;
            text-align:center;
        }

        .items .service{
            width:34%;
        }

        .items .category{
            width:22%;
        }

        .items .details{
            width:30%;
        }

        .items .amount{
            width:14%;
            text-align:right;
            white-space:nowrap;
        }

        .service-name{
            font-weight:800;
        }

        .period{
            display:block;
            color:#333;
            font-style:italic;
            margin-top:3px;
        }

        .details-list{
            margin:0;
            padding-left:16px;
        }

        .details-list li{
            margin-bottom:2px;
        }

        .summary-wrap{
            width:100%;
            margin-top:16px;
        }

        .summary{
            width:410px;
            margin-left:auto;
            border-collapse:collapse;
        }

        .summary td{
            border:1px solid #555;
            padding:7px 10px;
        }

        .summary td:first-child{
            text-align:right;
            font-weight:700;
        }

        .summary td:last-child{
            text-align:right;
            width:145px;
            font-weight:700;
        }

        .summary .grand td{
            font-weight:900;
            background:#f2f2f2;
        }

        .payments{
            width:100%;
            border-collapse:collapse;
            margin-top:8px;
        }

        .payments th,
        .payments td{
            border:1px solid #777;
            padding:7px 8px;
        }

        .payments th{
            background:#f2f2f2;
            font-weight:800;
        }

        .note{
            border:1px solid #777;
            padding:10px 12px;
            margin-top:18px;
        }

        .footer{
            margin-top:34px;
            display:table;
            width:100%;
        }

        .signature{
            display:table-cell;
            width:50%;
            vertical-align:bottom;
        }

        .thanks{
            display:table-cell;
            width:50%;
            text-align:right;
            vertical-align:bottom;
            font-weight:700;
        }

        .signature-line{
            display:inline-block;
            width:280px;
            border-bottom:1px solid #000;
            margin-left:8px;
        }

        .actions{
            text-align:center;
            margin-top:22px;
        }

        .btn{
            display:inline-block;
            padding:10px 18px;
            border-radius:6px;
            text-decoration:none;
            color:#fff;
            background:#111827;
            margin:0 5px;
            font-weight:700;
        }

        .btn-secondary{
            background:#555;
        }

        @media print{
            body{
                padding:12mm;
            }

            .actions{
                display:none;
            }

            .page{
                max-width:none;
            }
        }
    </style>
</head>

<body>

<div class="watermark">
    НАШИ ТРАДИЦИИ
</div>

@php

    $logoPath = public_path('images/logo.png');

    $invoiceNumber =
        'INV-' .
        $invoice->created_at?->format('Y') .
        '-' .
        str_pad($invoice->id, 4, '0', STR_PAD_LEFT);

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
        'zone' => 'Зона',
        'food_type' => 'Питание',
        'hours' => 'Часы',
        'uniform' => 'Форма',
    ];

    $optionValueLabels = [
        'daily' => 'Ежедневно',
        'weekly' => 'Еженедельно',
        'monthly' => 'Ежемесячно',
        'quarterly' => 'Каждые 3 месяца',
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

    $studentName =
        $invoice->student?->name ??
        $invoice->customer_name ??
        '—';

    $studentPhone =
        $invoice->student?->phone ??
        '—';

    $studentGrade =
        $invoice->student?->grade?->name_ru ??
        $invoice->student?->grade?->name ??
        '—';

    $invoiceNote =
        $invoice->note ??
        '—';

    $netAmount = max(
        (float)($invoice->total_amount ?? 0)
        -
        (float)($invoice->discount_amount ?? 0),
        0
    );

@endphp

<div class="page">

    <div class="top">

        <div class="school">

            <div class="school-row">

                <div class="logo-box">

                    @if(file_exists($logoPath))

                        <img
                            src="data:image/png;base64,{{ base64_encode(file_get_contents($logoPath)) }}"
                            class="logo"
                            alt="Logo">

                    @else

                        <div style="font-size:12px;">
                            Logo
                        </div>

                    @endif

                </div>

                <div class="school-text">

                    <h1>
                        Русская школа<br>
                        «Наши традиции»
                    </h1>

                    <p>
                        Египет, Хургада, район Мубарак 6, вилла 219
                    </p>

                    <p>
                        Тел.: 01062172809 / 01577782735
                    </p>

                    <p>
                        Email: info@school-erp.com
                    </p>

                </div>

            </div>

        </div>

        <div class="invoice-head">

            <h2>
                СЧЁТ / INVOICE
            </h2>

            <table class="meta-table">

                <tr>
                    <td>№ счёта:</td>
                    <td>{{ $invoiceNumber }}</td>
                </tr>

                <tr>
                    <td>Дата:</td>
                    <td>{{ $invoice->created_at?->format('d.m.Y H:i') }}</td>
                </tr>

                <tr>
                    <td>Статус:</td>
                    <td>{{ $statusText }}</td>
                </tr>

                <tr>
                    <td>Способ оплаты:</td>
                    <td>{{ $paymentMethodText }}</td>
                </tr>

                <tr>
                    <td>Касса:</td>
                    <td>{{ $invoice->cashAccount?->name ?? '—' }}</td>
                </tr>

            </table>

        </div>

    </div>

    <div class="section-title">
        Информация о клиенте
    </div>

    <table class="client-box">

        <tr>

            <td>

                <div>
                    <span class="label">Ученик:</span>

                    <span class="value">
                        {{ $studentName }}
                    </span>
                </div>

                <div style="margin-top:8px;">
                    <span class="label">Класс:</span>
                    {{ $studentGrade }}
                </div>

            </td>

            <td>

                <div>
                    <span class="label">Телефон:</span>
                    {{ $studentPhone }}
                </div>

                <div style="margin-top:8px;">
                    <span class="label">Примечание:</span>
                    {{ $invoiceNote }}
                </div>

            </td>

        </tr>

    </table>

</div>

</body>
</html>