<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Teacher Profile</title>

    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #000;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
        }

        .title {
            font-size: 18px;
            font-weight: bold;
        }

        .subtitle {
            font-size: 13px;
            color: #555;
        }

        .info {
            margin-bottom: 15px;
        }

        .info div {
            margin-bottom: 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            border: 1px solid #ccc;
            padding: 6px;
            text-align: center;
        }

        th {
            background: #f2f2f2;
        }

        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 11px;
            color: #777;
        }
    </style>
</head>

<body>

{{-- Header --}}
<div class="header">
    <div class="title">Русская школа «Наши традиции»</div>
    <div class="subtitle">Карточка преподавателя</div>
</div>

{{-- Info --}}
<div class="info">
    <div><strong>ФИО:</strong> {{ $teacher->full_name }}</div>
    <div><strong>Телефон:</strong> {{ $teacher->phone ?? '—' }}</div>
    <div><strong>Email:</strong> {{ $teacher->email ?? '—' }}</div>
    <div><strong>Специализация:</strong> {{ $teacher->specialization ?? '—' }}</div>
    <div><strong>Дата найма:</strong> {{ $teacher->hire_date?->format('Y-m-d') ?? '—' }}</div>
</div>

{{-- Subjects --}}
<div class="info">
    <strong>Предметы:</strong><br>
    @foreach($teacher->subjects as $subject)
        • {{ $subject->name_ru }} <br>
    @endforeach
</div>

{{-- Schedule --}}
<h3>Расписание</h3>

<table>
    <thead>
        <tr>
            <th>Урок</th>
            @foreach($days as $day)
                <th>{{ $day->name_ru }}</th>
            @endforeach
        </tr>
    </thead>

    <tbody>
        @foreach($periods as $period)
            <tr>
                <td>{{ $period->number }}</td>

                @foreach($days as $day)

                    @php
                        $lesson = $teacher->timetables
                            ->where('day_id', $day->id)
                            ->where('period_id', $period->id)
                            ->first();
                    @endphp

                    <td>
                        @if($lesson)
                            {{ $lesson->subject->name_ru }}<br>
                            <small>{{ $lesson->class->name_ru ?? '' }}</small>
                        @else
                            —
                        @endif
                    </td>

                @endforeach

            </tr>
        @endforeach
    </tbody>
</table>

<div class="footer">
    Generated at {{ now()->format('Y-m-d H:i') }}
</div>

</body>
</html>