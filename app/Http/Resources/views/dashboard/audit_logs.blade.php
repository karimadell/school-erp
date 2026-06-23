<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Audit Logs</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #0f172a;
            color: #e5e7eb;
            padding: 30px;
        }
        h1 {
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: #020617;
        }
        th, td {
            padding: 10px 12px;
            border-bottom: 1px solid #1e293b;
            text-align: left;
            font-size: 14px;
        }
        th {
            background: #020617;
            color: #38bdf8;
            text-transform: uppercase;
            font-size: 12px;
        }
        tr:hover {
            background: #020617;
        }
        .badge {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: bold;
        }
        .created { background: #14532d; color: #bbf7d0; }
        .updated { background: #1e3a8a; color: #bfdbfe; }
        .deleted { background: #7f1d1d; color: #fecaca; }
        .login { background: #065f46; color: #a7f3d0; }
        .logout { background: #334155; color: #e5e7eb; }
        .login_failed { background: #7c2d12; color: #fed7aa; }

        .pagination {
            margin-top: 20px;
        }
    </style>
</head>
<body>

<h1>📝 Audit Logs</h1>

<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Action</th>
            <th>Model</th>
            <th>Model ID</th>
            <th>User ID</th>
            <th>IP</th>
            <th>Date</th>
        </tr>
    </thead>
    <tbody>
    @forelse ($logs as $log)
        <tr>
            <td>{{ $log->id }}</td>
            <td>
                <span class="badge {{ $log->action }}">
                    {{ strtoupper($log->action) }}
                </span>
            </td>
            <td>{{ $log->model }}</td>
            <td>{{ $log->model_id }}</td>
            <td>{{ $log->user_id ?? '-' }}</td>
            <td>{{ $log->ip ?? '-' }}</td>
            <td>{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
        </tr>
    @empty
        <tr>
            <td colspan="7">No audit logs found.</td>
        </tr>
    @endforelse
    </tbody>
</table>

<div class="pagination">
    {{ $logs->links() }}
</div>

</body>
</html>