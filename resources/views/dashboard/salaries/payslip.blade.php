<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
body {
    font-family: DejaVu Sans, sans-serif;
}
.container {
    padding: 20px;
}
.header {
    text-align: center;
    margin-bottom: 30px;
}
.title {
    font-size: 22px;
    font-weight: bold;
}
.table {
    width: 100%;
    border-collapse: collapse;
}
.table td {
    padding: 10px;
    border: 1px solid #ddd;
}
.total {
    font-size: 18px;
    font-weight: bold;
    color: green;
}
</style>
</head>

<body>

<div class="container">

<div class="header">
    <div class="title">Salary Payslip</div>
    <div>{{ now()->format('Y-m-d') }}</div>
</div>

<table class="table">
<tr>
<td>Teacher</td>
<td>{{ $salary->teacher->name }}</td>
</tr>

<tr>
<td>Base Salary</td>
<td>{{ number_format($salary->base_salary,2) }}</td>
</tr>

<tr>
<td>Bonus</td>
<td>{{ number_format($salary->bonus,2) }}</td>
</tr>

<tr>
<td>Deduction</td>
<td>{{ number_format($salary->deduction,2) }}</td>
</tr>

<tr>
<td class="total">Net Salary</td>
<td class="total">{{ number_format($salary->net_salary,2) }}</td>
</tr>

</table>

</div>

</body>
</html>