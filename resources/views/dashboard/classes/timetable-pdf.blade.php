<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Расписание — {{ $class->name_ru ?? $class->name }}</title>

    <style>
        @page {
            margin: 18px;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #111827;
        }

        .meta {
            text-align: center;
            font-size: 11px;
            margin-bottom: 10px;
        }

        table.grid {
            width: 100%;
            border-collapse: collapse;
        }

        table.grid th, table.grid td {
            border: 1px solid #d1d5db;
            padding: 5px;
            text-align: center;
            vertical-align: middle;
        }

        table.grid th {
            background: #111827;
            color: #fff;
            font-weight: bold;
        }

        .lesson-col {
            width: 30px;
        }

        .subject {
            font-weight: bold;
        }

        .teacher {
            color: #4b5563;
            font-size: 9px;
        }

        .time {
            color: #6b7280;
            font-size: 8px;
        }

        .empty-cell {
            color: #d1d5db;
        }
    </style>
</head>

<body>

@include('pdf.partials.document-footer', ['academicYear' => $academicYear ?? null, 'schoolSettings' => $schoolSettings ?? null])
@include('pdf.partials.document-header', ['documentTitle' => 'РАСПИСАНИЕ УРОКОВ', 'schoolSettings' => $schoolSettings ?? null])

<div class="meta">
    Класс: <strong>{{ $class->name_ru ?? $class->name }}</strong>
    @if ($academicYear)
        &nbsp;·&nbsp; Учебный год: <strong>{{ $academicYear->name }}</strong>
    @endif
</div>

<table class="grid">
    <thead>
        <tr>
            <th class="lesson-col">{{ __('timetable.lesson') }}</th>
            @foreach ($days as $day)
                <th>{{ $day->name }}</th>
            @endforeach
        </tr>
    </thead>

    <tbody>
        @foreach ($periods as $period)
            <tr>
                <td class="lesson-col">
                    {{ $period->number ?? $period->name }}
                    @if ($period->start_time)
                        <div class="time">{{ \Illuminate\Support\Str::of($period->start_time)->limit(5, '') }}</div>
                    @endif
                </td>

                @foreach ($days as $day)
                    @php $lesson = $lessons->get($day->id.'-'.$period->id); @endphp
                    <td>
                        @if ($lesson)
                            <div class="subject">{{ $lesson->subject->name_ru }}</div>
                            <div class="teacher">{{ $lesson->teacher->full_name ?? '' }}</div>
                        @else
                            <span class="empty-cell">—</span>
                        @endif
                    </td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>

</body>
</html>
