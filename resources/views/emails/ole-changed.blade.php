<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body>
    <h2>🔐 Role Updated</h2>

    <p>Hello <strong>{{ $user->name }}</strong>,</p>

    <p>Your account role has been updated.</p>

    <ul>
        <li><strong>Old Role:</strong> {{ $oldRole }}</li>
        <li><strong>New Role:</strong> {{ $newRole }}</li>
        <li><strong>Changed By:</strong> {{ $changedBy }}</li>
        <li><strong>Date:</strong> {{ now()->format('Y-m-d H:i') }}</li>
    </ul>

    <p>If you think this is a mistake, please contact the administrator.</p>

    <hr>
    <small>School ERP System</small>
</body>
</html>