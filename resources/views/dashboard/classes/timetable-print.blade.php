<!DOCTYPE html>
<html lang="ru" dir="ltr">
<head>
    <meta charset="UTF-8">
    <title>Расписание — {{ $class->name_ru ?? $class->name }}</title>

    <style>
        @page {
            size: landscape;
            margin: 14mm;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            color: #111827;
            margin: 0;
            padding: 24px;
        }

        .print-actions {
            text-align: right;
            margin-bottom: 16px;
        }

        .print-actions button {
            font-size: 14px;
            padding: 8px 16px;
            border: 1px solid #111827;
            border-radius: 6px;
            background: #111827;
            color: #fff;
            cursor: pointer;
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #111827;
            padding-bottom: 10px;
            margin-bottom: 16px;
        }

        .school-name {
            font-size: 20px;
            font-weight: 700;
        }

        .doc-title {
            font-size: 15px;
            font-weight: 600;
            margin-top: 4px;
        }

        .meta {
            font-size: 13px;
            color: #374151;
            margin-top: 4px;
        }

        table.grid {
            width: 100%;
            border-collapse: collapse;
        }

        table.grid th, table.grid td {
            border: 1px solid #9ca3af;
            padding: 6px;
            text-align: center;
            vertical-align: middle;
        }

        table.grid th {
            background: #111827;
            color: #fff;
        }

        .lesson-col {
            width: 40px;
        }

        .subject {
            font-weight: 700;
        }

        .teacher {
            color: #4b5563;
            font-size: 12px;
        }

        .empty-cell {
            color: #d1d5db;
        }

        @media print {
            .print-actions {
                display: none;
            }

            body {
                padding: 0;
            }
        }
    </style>
</head>

<body>

    <div class="print-actions">
        <button type="button" onclick="window.print()">{{ __('timetable.print') }}</button>
    </div>

    <div class="header">
        <div class="school-name">{{ $schoolSettings->school_name ?? config('app.name') }}</div>
        <div class="doc-title">РАСПИСАНИЕ УРОКОВ</div>
        <div class="meta">
            Класс: {{ $class->name_ru ?? $class->name }}
            @if ($academicYear)
                &nbsp;·&nbsp; Учебный год: {{ $academicYear->name }}
            @endif
        </div>
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
                    <td class="lesson-col">{{ $period->number ?? $period->name }}</td>

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
