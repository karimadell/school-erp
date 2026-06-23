<x-filament::page>

<div class="space-y-6">

<x-filament::card>

<h2 class="text-xl font-bold">
Финансовый отчет студента
</h2>

<div class="flex gap-3 mt-4">

<x-filament::input
type="number"
wire:model="studentId"
placeholder="ID студента"
/>

<x-filament::button wire:click="loadStudent">
Загрузить
</x-filament::button>

</div>

</x-filament::card>


@if($student)

<x-filament::card>

<h3 class="text-lg font-bold">
{{ $student->full_name }}
</h3>

<p>
Класс: {{ $student->class->name ?? '-' }}
</p>

</x-filament::card>


<x-filament::grid columns="3" class="gap-4">

<x-filament::card>

<h3>Начислено</h3>

<div class="text-2xl font-bold">
{{ number_format($total,2) }} EGP
</div>

</x-filament::card>


<x-filament::card>

<h3>Оплачено</h3>

<div class="text-2xl font-bold text-green-600">
{{ number_format($paid,2) }} EGP
</div>

</x-filament::card>


<x-filament::card>

<h3>Остаток</h3>

<div class="text-2xl font-bold text-red-600">
{{ number_format($balance,2) }} EGP
</div>

</x-filament::card>

</x-filament::grid>


<x-filament::card>

<table class="w-full">

<thead>

<tr class="border-b">

<th>ID</th>
<th>Дата</th>
<th>Сумма</th>
<th>Статус</th>

</tr>

</thead>

<tbody>

@foreach($invoices as $invoice)

<tr class="border-b">

<td>{{ $invoice->id }}</td>

<td>{{ $invoice->created_at }}</td>

<td>{{ $invoice->total_amount }} EGP</td>

<td>

@if($invoice->status == 'paid')

<span class="text-green-600">
Оплачен
</span>

@else

<span class="text-red-600">
Не оплачен
</span>

@endif

</td>

</tr>

@endforeach

</tbody>

</table>

</x-filament::card>

@endif

</div>

</x-filament::page>