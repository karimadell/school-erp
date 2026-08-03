<!doctype html>
<html lang="ru">
<head><meta charset="UTF-8"><style>
@page{margin:25mm 14mm 18mm}body{font-family:"DejaVu Sans",sans-serif;color:#20252b;font-size:10px}header{text-align:center;border-bottom:2px solid #263b58;padding-bottom:12px;margin-bottom:18px}.logo{height:62px}.school{font-size:18px;font-weight:bold}.title{font-size:24px;font-weight:bold;margin-top:6px}.year{font-size:16px;font-weight:bold;margin-bottom:6px}.meta{color:#59636f}.section{margin:15px 0}.section h2{background:#263b58;color:#fff;font-size:13px;padding:7px 9px;margin:0}table{width:100%;border-collapse:collapse;page-break-inside:auto}tr{page-break-inside:avoid}th,td{border:1px solid #c8ced6;padding:6px;text-align:left;vertical-align:top}th{background:#edf1f5}.amount{text-align:right;white-space:nowrap}.muted{color:#747d87}.page-number:after{content:counter(page)}footer{position:fixed;bottom:-12mm;width:100%;color:#777}.footer-left{float:left}.footer-right{float:right}
</style></head>
<body>
<footer><span class="footer-left">Сформировано автоматически в School ERP.</span><span class="footer-right">Страница <span class="page-number"></span></span></footer>
<header>@if(is_file($logoPath))<img class="logo" src="{{ $logoPath }}">@endif<div class="school">ЦЕНТР «НАШИ ТРАДИЦИИ»</div><div class="title">ПРАЙС</div><div class="year">{{ str_replace('/', '–', $year->name) }} учебный год</div><div class="meta">Дата формирования: {{ $generatedAt->format('d.m.Y') }} · Валюта: EGP</div></header>

@forelse($sections as $heading=>$rows)
<section class="section"><h2>{{ $heading }}</h2>
@if(in_array($heading,['ОБУЧЕНИЕ','ТРАНСПОРТ'],true))
<table><thead><tr><th>{{ $heading==='ТРАНСПОРТ' ? 'Транспортная зона' : 'Группа обучения' }}</th><th class="amount">Год</th><th class="amount">Часть / месяц</th><th>Другие варианты</th></tr></thead><tbody>
@foreach($rows as $row)<tr><td>{{ $row['label'] }}</td><td class="amount">@if($row['yearly']){{ number_format((float)$row['yearly']->amount,2,'.',' ') }} EGP @else<span class="muted">—</span>@endif</td><td class="amount">@if($row['monthly']){{ number_format((float)$row['monthly']->amount,2,'.',' ') }} EGP @else<span class="muted">—</span>@endif</td><td>@forelse($row['other'] as $price){{ \App\Services\Finance\AcademicYearPriceListService::periodLabel($price->payment_period) ?: 'Вариант' }}: {{ number_format((float)$price->amount,2,'.',' ') }} EGP<br>@empty<span class="muted">—</span>@endforelse</td></tr>@endforeach
</tbody></table>
@else
<table><thead><tr><th>{{ $heading==='ШКОЛЬНАЯ ФОРМА' ? 'Позиция и размер' : ($heading==='ПИТАНИЕ' ? 'План / блюдо' : 'Тариф') }}</th><th>Период</th><th class="amount">Стоимость</th><th>Срок действия</th></tr></thead><tbody>
@foreach($rows as $row)@php($price=$row['price'])<tr><td>{{ $row['label'] }}</td><td>{{ \App\Services\Finance\AcademicYearPriceListService::periodLabel($price->payment_period) ?: '—' }}</td><td class="amount">{{ number_format((float)$price->amount,2,'.',' ') }} EGP</td><td>{{ $price->start_date->format('d.m.Y') }} — {{ $price->end_date?->format('d.m.Y') ?? 'без ограничения' }}</td></tr>@endforeach
</tbody></table>
@endif
</section>
@empty
<p>Для выбранных условий тарифы не найдены.</p>
@endforelse
</body></html>
