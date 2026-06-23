<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Teachers Report</title>

    <style>
        @page {
            margin: 20px;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #111827;
            position: relative;
        }

        .watermark {
            position: fixed;
            top: 210px;
            left: 250px;
            width: 280px;
            opacity: 0.06;
            z-index: -1;
        }

        .header {
            width: 100%;
            border-bottom: 2px solid #111827;
            padding-bottom: 12px;
            margin-bottom: 18px;
        }

        .logo {
            width: 75px;
            float: left;
        }

        .school-info {
            text-align: center;
        }

        .school-name {
            font-size: 20px;
            font-weight: bold;
        }

        .report-title {
            font-size: 14px;
            color: #4b5563;
            margin-top: 4px;
        }

        .meta {
            text-align: right;
            font-size: 10px;
            margin-top: 6px;
            color: #374151;
        }

        .doc-id {
            font-weight: bold;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th, td {
            border: 1px solid #d1d5db;
            padding: 6px;
            text-align: center;
            vertical-align: middle;
        }

        th {
            background: #111827;
            color: #fff;
            font-weight: bold;
        }

        tbody tr:nth-child(even) {
            background: #f9fafb;
        }

        .text-left {
            text-align: left;
        }

        .subjects {
            text-align: left;
            line-height: 1.4;
        }

        .badge {
            padding: 3px 6px;
            border-radius: 4px;
            font-size: 10px;
            color: #fff;
        }

        .active {
            background: #16a34a;
        }

        .inactive {
            background: #6b7280;
        }

        .empty {
            text-align: center;
            padding: 20px;
            color: #6b7280;
        }

        .footer {
            margin-top: 42px;
            width: 100%;
        }

        .signature {
            width: 45%;
            float: left;
            text-align: center;
        }

        .stamp {
            width: 45%;
            float: right;
            text-align: center;
        }

        .line {
            margin-top: 32px;
            border-top: 1px solid #111827;
            width: 80%;
            margin-left: auto;
            margin-right: auto;
            padding-top: 6px;
        }

        .generated {
            margin-top: 25px;
            text-align: center;
            font-size: 10px;
            color: #6b7280;
        }

        .clearfix {
            clear: both;
        }
    </style>
</head>

<body>

@php
    $documentId = 'TR-' . now()->format('Ymd-His');
    $logoPath = public_path('images/logo.png');
@endphp

@if(file_exists($logoPath))
    <img src="{{ $logoPath }}" class="watermark">
@endif

<div class="header">
    @if(file_exists($logoPath))
        <img src="{{ $logoPath }}" class="logo">
    @endif

    <div class="school-info">
        <div class="school-name">Русская школа «Наши традиции»</div>
        <div class="report-title">Официальный список преподавателей</div>
    </div>

    <div class="meta">
        <div class="doc-id">Документ №: {{ $documentId }}</div>
        <div>Дата печати: {{ now()->format('Y-m-d H:i') }}</div>
        <div>Хургада - Египет</div>
    </div>
</div>

<table>
    <thead>
        <tr>
            <th width="35">#</th>
            <th>ФИО</th>
            <th>Кратко</th>
            <th>Предметы</th>
            <th>Специализация</th>
            <th>Телефон</th>
            <th>Дата найма</th>
            <th>Статус</th>
        </tr>
    </thead>

    <tbody>
        @forelse($teachers as $teacher)
            <tr>
                <td>{{ $loop->iteration }}</td>

                <td class="text-left">
                    {{ $teacher->full_name ?? (($teacher->first_name ?? '') . ' ' . ($teacher->last_name ?? '')) }}
                </td>

                <td>
                    {{ $teacher->short_name ?? '—' }}
                </td>

                <td class="subjects">
                    @forelse($teacher->subjects as $subject)
                        • {{ $subject->name_ru }}<br>
                    @empty
                        —
                    @endforelse
                </td>

                <td>{{ $teacher->specialization ?? '—' }}</td>

                <td>{{ $teacher->phone ?? '—' }}</td>

                <td>
                    {{ $teacher->hire_date ? \Carbon\Carbon::parse($teacher->hire_date)->format('Y-m-d') : '—' }}
                </td>

                <td>
                    @if($teacher->is_active)
                        <span class="badge active">Активен</span>
                    @else
                        <span class="badge inactive">Неактивен</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="8" class="empty">Нет данных</td>
            </tr>
        @endforelse
    </tbody>
</table>

<div class="footer">
    <div class="signature">
        Директор школы
        <div class="line">Подпись</div>
    </div>

    <div class="stamp">
        Печать школы
        <div class="line">&nbsp;</div>
    </div>

    <div class="clearfix"></div>
</div>

<div class="generated">
    Generated by School ERP — {{ now()->format('Y-m-d H:i:s') }}
</div>

</body>
</html>