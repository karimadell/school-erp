<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Audit Logs</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
        }
        table {
            border-collapse: collapse;
            width: 100%;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
        }
        th {
            background: #f3f3f3;
        }
    </style>
</head>
<body>

<h1>Audit Logs</h1>

@if ($logs->isEmpty())
    <p>No audit logs found.</p>
@else
    <table>
        <thead>
        <tr>
            <th>ID</th>
            <th>Action</th>
            <th>Model</th>
            <th>Model ID</th>
            <th>User ID</th>
            <th>Date</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($logs as $log)
            <tr>
                <td>{{ $log->id }}</td>
                <td>{{ $log->action }}</td>
                <td>{{ $log->model }}</td>
                <td>{{ $log->model_id }}</td>
                <td>{{ $log->user_id ?? '-' }}</td>
                <td>{{ $log->created_at }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

</body>
</html>