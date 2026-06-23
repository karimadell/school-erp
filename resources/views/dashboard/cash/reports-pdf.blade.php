<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cash Report</title>

    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #000;
        }

        h2, h3 {
            margin: 0 0 10px 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }

        th, td {
            border: 1px solid #444;
            padding: 6px;
            text-align: left;
        }

        th {
            background: #f0f0f0;
        }

        .in {
            color: green;
            font-weight: bold;
        }

        .out {
            color: red;
            font-weight: bold;
        }

        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 10px;
            color: #666;
        }
    </style>
</head>
<body>

    <h2>💰 Cash Report</h2>

    <hr>

    {{-- ================= Daily Report ================= --}}
    <h3>📅 Daily Report — {{ $date }}</h3>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Account</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Description</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            @forelse($daily as $t)
                <tr>
                    <td>{{ $t->id }}</td>
                    <td>{{ $t->cashAccount->name ?? '-' }}</td>
                    <td class="{{ $t->type }}">
                        {{ strtoupper($t->type) }}
                    </td>
                    <td>{{ number_format($t->amount, 2) }}</td>
                    <td>{{ $t->description }}</td>
                    <td>{{ $t->created_at->format('Y-m-d H:i') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="text-align:center;">
                        No daily transactions
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- ================= Monthly Report ================= --}}
    <h3>🗓 Monthly Report — {{ $month }}</h3>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Account</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Description</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            @forelse($monthly as $t)
                <tr>
                    <td>{{ $t->id }}</td>
                    <td>{{ $t->cashAccount->name ?? '-' }}</td>
                    <td class="{{ $t->type }}">
                        {{ strtoupper($t->type) }}
                    </td>
                    <td>{{ number_format($t->amount, 2) }}</td>
                    <td>{{ $t->description }}</td>
                    <td>{{ $t->created_at->format('Y-m-d H:i') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="text-align:center;">
                        No monthly transactions
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Generated on {{ now()->format('Y-m-d H:i') }}
    </div>

</body>
</html>