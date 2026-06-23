<h2>Финансовый отчет студента</h2>

<p>Имя: {{ $student->full_name }}</p>

<p>Всего: {{ $total }}</p>

<p>Оплачено: {{ $paid }}</p>

<p>Долг: {{ $due }}</p>

<hr>

<table width="100%" border="1" cellspacing="0" cellpadding="6">

<tr>
<th>ID</th>
<th>Сумма</th>
<th>Статус</th>
</tr>

@foreach($invoices as $invoice)

<tr>
<td>{{ $invoice->id }}</td>
<td>{{ $invoice->total_amount }}</td>
<td>{{ $invoice->status }}</td>
</tr>

@endforeach

</table>