<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Список преподавателей</title>

    <style>
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 13px;
            color: #111827;
            margin: 25px;
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 25px;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 12px;
        }

        .logo {
            width: 90px;
            height: auto;
        }

        .school-info {
            text-align: right;
        }

        .school-name {
            font-size: 20px;
            font-weight: bold;
        }

        .school-address {
            font-size: 13px;
            color: #6b7280;
            margin-top: 4px;
        }

        .title {
            text-align: center;
            font-size: 22px;
            font-weight: bold;
            margin: 25px 0 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            border: 1px solid #d1d5db;
            padding: 8px;
            text-align: center;
        }

        th {
            background: #0d6efd;
            color: #ffffff;
            font-weight: bold;
        }

        tbody tr:nth-child(even) {
            background: #f9fafb;
        }

        .footer {
            margin-top: 35px;
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #374151;
        }

        .signature {
            width: 30%;
            text-align: center;
            border-top: 1px solid #111827;
            padding-top: 8px;
        }

        .generated {
            margin-top: 25px;
            text-align: center;
            font-size: 12px;
            color: #6b7280;
        }

        .no-print {
            margin-bottom: 20px;
        }

        @media print {
            .no-print {
                display: none;
            }

            body {
                margin: 10mm;
            }
        }
    </style>
</head>

<body>

<div class="no-print">
    <button onclick="window.print()">
        🖨 Печать
    </button>
</div>

<div class="header">
    <div>
        <img src="{{ asset('images/logo.png') }}" class="logo" alt="School Logo">
    </div>

    <div class="school-info">
        <div class="school-name">Русская школа «Наши традиции»</div>
        <div class="school-address">Хургада - Египет</div>
    </div>
</div>

<div class="title">
    👨‍🏫 Список преподавателей
</div>

<table>
    <thead>
        <tr>
            <th>#</th>
            <th>ФИО</th>
            <th>Краткое имя</th>
            <th>Email</th>
            <th>Телефон</th>
            <th>Специализация</th>
            <th>Статус</th>
        </tr>
    </thead>

    <tbody>
        @forelse($teachers as $index => $teacher)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $teacher->full_name }}</td>
                <td>{{ $teacher->short_name }}</td>
                <td>{{ $teacher->email ?? '—' }}</td>
                <td>{{ $teacher->phone ?? '—' }}</td>
                <td>{{ $teacher->specialization ?? '—' }}</td>
                <td>
                    {{ $teacher->is_active ? 'Активен' : 'Неактивен' }}
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7">Нет данных</td>
            </tr>
        @endforelse
    </tbody>
</table>

<div class="footer">
    <div class="signature">Директор</div>
    <div class="signature">Администратор</div>
    <div class="signature">Печать школы</div>
</div>

<div class="generated">
    Generated at {{ now()->format('Y-m-d H:i') }}
</div>

</body>
</html>