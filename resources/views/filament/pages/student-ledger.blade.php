<x-filament::page>

<div class="mb-6">

<select wire:model="studentId" wire:change="loadStudent" class="border rounded px-3 py-2 w-full">

<option value="">Выберите студента</option>

@foreach(\App\Models\Student::all() as $student)

<option value="{{ $student->id }}">

{{ $student->full_name }}

</option>

@endforeach

</select>

</div>

@if($student)

<div class="mb-4">

<h2 class="text-xl font-bold">

{{ $student->full_name }}

</h2>

</div>

<table class="w-full border text-center">

<thead class="bg-gray-100">

<tr>

<th class="border p-2">Дата</th>

<th class="border p-2">Операция</th>

<th class="border p-2">Сумма</th>

</tr>

</thead>

<tbody>

@foreach($invoices as $invoice)

<tr>

<td class="border p-2">

{{ $invoice->created_at->format('Y-m-d') }}

</td>

<td class="border p-2">

Счет №{{ $invoice->id }}

</td>

<td class="border p-2">

{{ number_format($invoice->total_amount,2) }} EGP

</td>

</tr>

@endforeach

</tbody>

</table>

@endif

</x-filament::page>