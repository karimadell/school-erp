<h2>Финансовый отчет школы</h2>

<p>Общий доход: {{ number_format($totalIncome,2) }} EGP</p>

<p>Оплаченные счета: {{ $paidInvoices }}</p>

<p>Неоплаченные счета: {{ $unpaidInvoices }}</p>

<p>Баланс кассы: {{ number_format($cashBalance,2) }} EGP</p>

<hr>

<h3>Последние счета</h3>

<table width="100%" border="1" cellspacing="0" cellpadding="6">

<tr>
<th>ID</th>
<th>Студент</th>
<th>Сумма</th>
<th>Статус</th>
</tr>

@foreach($invoices as $invoice)

<tr>
<td>{{ $invoice->id }}</td>
<td>{{ $invoice->student->full_name ?? '-' }}</td>
<td>{{ $invoice->total_amount }}</td>
<td>{{ $invoice->status }}</td>
</tr>

@endforeach

</table>