<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">

    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #111;
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #111;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        .logo {
            width: 75px;
            margin-bottom: 6px;
        }

        h2 {
            margin: 0;
            font-size: 20px;
        }

        .meta {
            margin-top: 5px;
            color: #555;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #111827;
            color: white;
            border: 1px solid #555;
            padding: 7px;
            text-align: center;
        }

        td {
            border: 1px solid #777;
            padding: 6px;
            vertical-align: top;
            text-align: center;
            height: 75px;
        }

        .period {
            background: #f3f4f6;
            font-weight: bold;
            width: 120px;
        }

        .subject {
            font-weight: bold;
            color: #1f2937;
        }

        .teacher {
            margin-top: 4px;
            font-size: 10px;
        }

        .room {
            margin-top: 3px;
            color: #555;
            font-size: 10px;
        }

        .empty {
            color: #aaa;
        }

        .signatures {
            margin-top: 35px;
            display: table;
            width: 100%;
        }

        .signature {
            display: table-cell;
            text-align: center;
            width: 33%;
            padding-top: 30px;
        }

        .line {
            border-top: 1px solid #111;
            width: 170px;
            margin: 0 auto 6px;
        }
    </style>
</head>

<body>

<div class="header">
    @if(file_exists(public_path('images/logo.png')))
        <img src="{{ public_path('images/logo.png') }}" class="logo">
    @endif

    <h2>Русская школа «Наши традиции»</h2>

    <div class="meta">
        Расписание класса:
        <strong>{{ $class->name_ru ?? $class->code }}</strong>
        —
        {{ now()->format('Y-m-d') }}
    </div>
</div>

<table>
    <thead>
        <tr>
            <th>Урок</th>

            @foreach($days as $day)
                <th>{{ $day->name_ru ?? $day->name }}</th>
            @endforeach
        </tr>
    </thead>

    <tbody>
        @foreach($periods as $period)
            <tr>
                <td class="period">
                    Урок {{ $period->number }}

                    <br>

                    <small>
                        {{ $period->start_time ?? '' }}
                        @if(!empty($period->start_time) || !empty($period->end_time))
                            -
                        @endif
                        {{ $period->end_time ?? '' }}
                    </small>
                </td>

                @foreach($days as $day)
                    @php
                        $key = $day->id . '_' . $period->id;
                        $lesson = $timetable->get($key);
                    @endphp

                    <td>
                        @if($lesson)
                            <div class="subject">
                                {{ $lesson->subject->name_ru ?? '-' }}
                            </div>

                            <div class="teacher">
                                {{ $lesson->teacher->short_name ?? $lesson->teacher->full_name ?? '-' }}
                            </div>

                            @if($lesson->room)
                                <div class="room">
                                    Кабинет: {{ $lesson->room }}
                                </div>
                            @endif
                        @else
                            <span class="empty">—</span>
                        @endif
                    </td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>

<div class="signatures">
    <div class="signature">
        <div class="line"></div>
        Директор
    </div>

    <div class="signature">
        <div class="line"></div>
        Администратор
    </div>

    <div class="signature">
        <div class="line"></div>
        Печать школы
    </div>
</div>

</body>
</html>