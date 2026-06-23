<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Student Report</title>

    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #333;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
        }

        .logo {
            height: 80px;
            margin-bottom: 10px;
        }

        .title {
            font-size: 18px;
            font-weight: bold;
        }

        .info {
            margin-top: 5px;
            font-size: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th {
            background: #2c3e50;
            color: #fff;
            padding: 6px;
            border: 1px solid #ddd;
            font-size: 11px;
        }

        td {
            padding: 6px;
            border: 1px solid #ddd;
            text-align: center;
        }

        tr:nth-child(even) {
            background: #f2f2f2;
        }

    </style>
</head>
<body>

<div class="header">

    <img src="{{ public_path('images/logo.png') }}" class="logo">

    <div class="title">
        {{ $class->grade->stage->name ?? '' }} /
        {{ $class->grade->name ?? '' }} /
        {{ $class->name_ru }}
    </div>

    <div class="info">
        {{ $subject?->name_ru ?? '' }}
        @if($exam) | {{ $exam->name }} @endif
        @if($quarter) | {{ $quarter->name }} @endif
    </div>

</div>

<table>
    <thead>
        <tr>

            @foreach($data['columns'] as $column)
                <th>
                    {{ __('student_grades.columns.' . $column) }}
                </th>
            @endforeach

        </tr>
    </thead>

    <tbody>

        @foreach($students as $student)
            @php
                $grade = $grades->get($student->id);
            @endphp

            <tr>

                @foreach($data['columns'] as $column)

                    <td>

                        @switch($column)

                            @case('student_name')
                                {{ $student->full_name }}
                                @break

                            @case('short_name')
                                {{ $student->last_name_ru }} {{ mb_substr($student->first_name_ru,0,1) }}.
                                @break

                            @case('class')
                                {{ $student->class->name_ru ?? '' }}
                                @break

                            @case('phone')
                                {{ $student->phone }}
                                @break

                            @case('email')
                                {{ $student->email }}
                                @break

                            @case('nationality')
                                {{ $student->nationality }}
                                @break

                            @case('birth_date')
                                {{ $student->birth_date }}
                                @break

                            @case('gender')
                                {{ $student->gender }}
                                @break

                            @case('subject')
                                {{ $subject?->name_ru }}
                                @break

                            @case('exam')
                                {{ $exam?->name }}
                                @break

                            @case('quarter')
                                {{ $quarter?->name }}
                                @break

                            @case('score')
                                {{ $grade?->score ?? '-' }}
                                @break

                            @case('note')
                                {{ $grade?->note }}
                                @break

                        @endswitch

                    </td>

                @endforeach

            </tr>
        @endforeach

    </tbody>
</table>

</body>
</html>