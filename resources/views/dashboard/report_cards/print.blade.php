<!DOCTYPE html>
<html lang="ru">

<head>

<meta charset="UTF-8">

<title>Табель успеваемости</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<script src="https://cdn.jsdelivr.net/npm/qrcode/build/qrcode.min.js"></script>

<style>

body{
font-family:"Times New Roman",serif;
padding:40px;
}

.report{
border:3px solid #000;
padding:40px;
}

.header{
text-align:center;
margin-bottom:30px;
}

.school{
font-size:22px;
font-weight:bold;
}

.title{
font-size:28px;
font-weight:bold;
margin-top:10px;
}

.info{
margin-top:20px;
font-size:18px;
}

table{
margin-top:30px;
}

th{
text-align:center;
}

.average{
font-size:20px;
font-weight:bold;
margin-top:20px;
}

.footer{
margin-top:60px;
}

.stamp{
width:120px;
height:120px;
border:2px dashed #000;
display:flex;
align-items:center;
justify-content:center;
font-size:12px;
}

</style>

</head>


<body onload="window.print()">

<div class="report">

<div class="header">

<div class="school">
ГОСУДАРСТВЕННАЯ ШКОЛА
</div>

<div class="title">
ТАБЕЛЬ УСПЕВАЕМОСТИ
</div>

</div>


<div class="info">

<p><strong>Ученик:</strong> {{ $student->name }}</p>

<p><strong>Класс:</strong> {{ $student->class->name_ru ?? '-' }}</p>

<p><strong>Учебный год:</strong> 2025 / 2026</p>

</div>


@php
$total=0;
$count=0;
@endphp


<table class="table table-bordered">

<thead>

<tr>
<th width="60%">Предмет</th>
<th width="20%">Оценка</th>
<th width="20%">Комментарий</th>
</tr>

</thead>

<tbody>

@foreach($subjects as $subject)

<tr>

<td>{{ $subject->name_ru ?? $subject->name }}</td>

<td class="text-center">
{{ $subject->pivot->mark ?? '-' }}
</td>

<td></td>

</tr>

@php
if($subject->pivot->mark){
$total += $subject->pivot->mark;
$count++;
}
@endphp

@endforeach

</tbody>

</table>


@php
$avg = $count ? round($total/$count,2) : 0;
@endphp


<div class="average">
Средний балл: {{ $avg }}
</div>


<div class="average">

@if($avg >= 4.5)

Оценка: Отлично

@elseif($avg >= 3.5)

Оценка: Хорошо

@elseif($avg >= 2.5)

Оценка: Удовлетворительно

@else

Оценка: Неудовлетворительно

@endif

</div>


<div class="footer row">

<div class="col-4">

Директор школы  
<br><br>
_________________

</div>


<div class="col-4 text-center">

<div class="stamp">
ПЕЧАТЬ ШКОЛЫ
</div>

</div>


<div class="col-4 text-end">

Дата  
<br><br>
{{ date('d.m.Y') }}

</div>

</div>


<div class="mt-4 text-center">

<div id="qrcode"></div>

</div>


</div>


<script>

QRCode.toCanvas(document.getElementById('qrcode'),
"{{ url()->current() }}",
function(){});

</script>

</body>

</html>