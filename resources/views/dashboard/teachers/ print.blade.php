<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>{{ __('teachers.print') }}</title>

    <style>
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            color: #111827;
            margin: 25px;
        }

        .no-print {
            margin-bottom: 20px;
        }

        @media print {
            .no-print {
                display: none;
            }
        }

        .header {
            text-align: center;
            margin-bottom: 25px;
            border-bottom: 2px solid #111827;
            padding-bottom: 15px;
        }

        .title {
            font-size: 24px;
            font-weight: bold;
        }

        .subtitle {
            color: #6b7280;
            margin-top: 5px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th, td {
            border: 1px solid #d1d5db;
            padding: 8px;
            text-align: left;
        }

        th {
            background: #f3f4f6;
            font-weight: bold;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
        }

        .active {
            background: #dcfce7;
            color: #166534;
        }

        .inactive {
            background: #f3f4f6;
            color: #374151;
        }

        .footer {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }

        .signature {
            width: 30%;
            text-align: center;
            border-top: 1px solid #111827;
            padding-top: 8px;
        }
    </style>
</head>

<body>

<div class="no-print">
    <button onclick="window.print()">
        🖨 {{ __('teachers.print') }}
    </button>
</div>

<div class="header">
    <div class="title">Русская школа «Наши традиции»</div>
    <div class="subtitle">{{ __('teachers.title') }} — {{ now()->format('Y-m-d') }}</div>
</div>

<table>
    <thead>
        <tr>
            <th>#</th>
            <th>{{ __('teachers.full_name') }}</th>
            <th>{{ __('teachers.short_name') }}</th>
            <th>{{ __('teachers.subjects') }}</th>
            <th>{{ __('teachers.specialization') }}</th>
            <th>{{ __('teachers.phone') }}</th>
            <th>{{ __('teachers.email') }}</th>
            <th>{{ __('teachers.status') }}</th>
        </tr>
    </thead>

    <tbody>
        @forelse($teachers as $teacher)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $teacher->full_name }}</td>
                <td>{{ $teacher->short_name }}</td>
                <td>
                    @forelse($teacher->subjects as $subject)
                        {{ $subject->name_ru }}@if(!$loop->last), @endif
                    @empty
                        —
                    @endforelse
                </td>
                <td>{{ $teacher->specialization ?? '—' }}</td>
                <td>{{ $teacher->phone ?? '—' }}</td>
                <td>{{ $teacher->email ?? '—' }}</td>
                <td>
                    @if($teacher->is_active)
                        <span class="badge active">{{ __('teachers.active') }}</span>
                    @else
                        <span class="badge inactive">{{ __('teachers.inactive') }}</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="8" style="text-align:center;">
                    {{ __('teachers.no_data') }}
                </td>
            </tr>
        @endforelse
    </tbody>
</table>

<div class="footer">
    <div class="signature">Директор</div>
    <div class="signature">Администратор</div>
    <div class="signature">Печать школы</div>
</div>

<script>
    window.onload = function () {
        window.print();
    };
</script>

</body>
</html>