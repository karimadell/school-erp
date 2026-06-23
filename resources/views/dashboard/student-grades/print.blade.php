<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('student_grades.print_report') }}</title>

    <style>
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            direction: {{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }};
            padding: 20px;
            color: #111;
            font-size: 13px;
        }

        .print-btn {
            margin-bottom: 20px;
            padding: 8px 16px;
            border: 0;
            background: #111827;
            color: white;
            border-radius: 6px;
            cursor: pointer;
        }

        .school-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 3px solid #111;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .school-title {
            text-align: center;
            flex: 1;
        }

        .school-title h2 {
            margin: 0;
            font-size: 22px;
            font-weight: bold;
        }

        .school-title div {
            margin-top: 5px;
            font-size: 14px;
        }

        .logo-box {
            width: 100px;
            height: 90px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo-box img {
            max-height: 80px;
        }

        .report-title {
            text-align: center;
            margin: 20px 0;
        }

        .report-title h3 {
            margin: 0;
            font-size: 20px;
            text-decoration: underline;
        }

        .meta {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px 20px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .meta div {
            border-bottom: 1px dotted #999;
            padding-bottom: 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
        }

        th, td {
            border: 1px solid #000;
            padding: 7px;
            text-align: center;
            vertical-align: middle;
        }

        th {
            background: #f1f1f1;
            font-weight: bold;
        }

        .footer-signatures {
            display: flex;
            justify-content: space-between;
            margin-top: 45px;
            font-size: 14px;
        }

        .signature {
            width: 30%;
            text-align: center;
            border-top: 1px solid #000;
            padding-top: 8px;
        }

        @media print {
            .print-btn {
                display: none;
            }

            body {
                padding: 0;
            }

            @page {
                size: A4 landscape;
                margin: 12mm;
            }
        }
    </style>
</head>

<body>

<button onclick="window.print()" class="print-btn">
    🖨 {{ __('student_grades.print') }}
</button>

<div class="school-header">

    {{-- LOGO LEFT --}}
    <div class="logo-box">
        <img src="{{ asset('images/logo.png') }}">
    </div>

    {{-- TITLE --}}
    <div class="school-title">
        <h2>Русская школа «Наши традиции»</h2>
        <div>Хургада — Египет</div>
        <div>School ERP Report</div>
    </div>

    {{-- LOGO RIGHT --}}
    <div class="logo-box">
        <img src="{{ asset('images/logo.png') }}">
    </div>

</div>

<div class="report-title">
    <h3>{{ __('student_grades.print_report') }}</h3>
    <div>{{ now()->format('Y-m-d H:i') }}</div>
</div>

<div class="meta">
    <div>
        <strong>{{ __('student_grades.class') }}:</strong>
        {{ $class->name ?? '-' }}
    </div>

    <div>
        <strong>{{ __('student_grades.print') }}:</strong>
        {{ now()->format('Y-m-d') }}
    </div>

    @if($subject)
        <div>
            <strong>{{ __('student_grades.subject') }}:</strong>
            {{ $subject->name_ru ?? $subject->name ?? '-' }}
        </div>
    @endif

    @if($exam)
        <div>
            <strong>{{ __('student_grades.exam') }}:</strong>
            {{ $exam->name ?? $exam->title ?? '-' }}
        </div>
    @endif

    @if($quarter)
        <div>
            <strong>{{ __('student_grades.quarter') }}:</strong>
            {{ $quarter->name ?? $quarter->title ?? '-' }}
        </div>
    @endif
</div>

<table>
    <thead>
        <tr>
            <th>#</th>

            @foreach($data['columns'] as $column)
                <th>{{ __('student_grades.columns.' . $column) }}</th>
            @endforeach
        </tr>
    </thead>

    <tbody>
        @foreach($students as $index => $student)
            @php
                $grade = $grades->get($student->id);
            @endphp

            <tr>
                <td>{{ $index + 1 }}</td>

                @foreach($data['columns'] as $column)
                    <td>
                        @switch($column)

                            @case('student_name')
                                {{ $student->full_name }}
                                @break

                            @case('short_name')
                                {{ $student->short_name }}
                                @break

                            @case('class')
                                {{ $student->class->name ?? '-' }}
                                @break

                            @case('phone')
                                {{ $student->parent_phone ?? $student->phone ?? '-' }}
                                @break

                            @case('email')
                                {{ $student->email ?? '-' }}
                                @break

                            @case('nationality')
                                {{ $student->nationality ?? '-' }}
                                @break

                            @case('birth_date')
                                {{ optional($student->birth_date)->format('Y-m-d') ?? '-' }}
                                @break

                            @case('gender')
                                {{ $student->gender ? __('students.' . $student->gender) : '-' }}
                                @break

                            @case('subject')
                                {{ $subject->name_ru ?? $subject->name ?? '-' }}
                                @break

                            @case('exam')
                                {{ $exam->name ?? $exam->title ?? '-' }}
                                @break

                            @case('quarter')
                                {{ $quarter->name ?? $quarter->title ?? '-' }}
                                @break

                            @case('score')
                                {{ $grade->score ?? '-' }}
                                @break

                            @case('note')
                                {{ $grade->note ?? '-' }}
                                @break

                        @endswitch
                    </td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>

<div class="footer-signatures">
    <div class="signature">Классный руководитель</div>
    <div class="signature">Администратор</div>
    <div class="signature">Директор</div>
</div>

</body>
</html>