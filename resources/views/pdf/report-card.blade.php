<!DOCTYPE html>
<html lang="ru">
<head>

<meta charset="UTF-8">

<style>

body{
    font-family: DejaVu Sans;
    font-size:14px;
}

.header{
    text-align:center;
    margin-bottom:20px;
}

.title{
    font-size:22px;
    font-weight:bold;
}

.info{
    margin-top:20px;
}

table{
    width:100%;
    border-collapse:collapse;
    margin-top:20px;
}

th,td{
    border:1px solid #000;
    padding:6px;
    text-align:center;
}

th{
    background:#f2f2f2;
}

.subject{
    text-align:left;
}

.footer{
    margin-top:40px;
}

</style>

</head>

<body>

<div class="header">

<div class="title">
Табель успеваемости
</div>

</div>


<div class="info">

<strong>Ученик:</strong>
{{ $student->full_name }}

<br>

<strong>Класс:</strong>
{{ $student->class->name ?? '-' }}

<br>

<strong>Дата:</strong>
{{ date('d.m.Y') }}

</div>


<table>

<thead>

<tr>

<th>Предмет</th>

<th>1 четверть</th>

<th>2 четверть</th>

<th>3 четверть</th>

<th>4 четверть</th>

<th>Год</th>

</tr>

</thead>


<tbody>

@foreach($subjects as $subject)

<tr>

<td class="subject">
{{ $subject->name_ru ?? $subject->name }}
</td>

<td>
{{ $student->quarterGrade($subject->id,1) ?? '-' }}
</td>

<td>
{{ $student->quarterGrade($subject->id,2) ?? '-' }}
</td>

<td>
{{ $student->quarterGrade($subject->id,3) ?? '-' }}
</td>

<td>
{{ $student->quarterGrade($subject->id,4) ?? '-' }}
</td>

<td>
{{ $student->yearGrade($subject->id) ?? '-' }}
</td>

</tr>

@endforeach

</tbody>

</table>


<div class="footer">

<br><br>

Директор школы ______________________

<br><br>

Классный руководитель ______________________

</div>

</body>

</html>