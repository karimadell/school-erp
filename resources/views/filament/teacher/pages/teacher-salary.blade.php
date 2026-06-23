<x-filament::page>

<div class="bg-white p-6 rounded shadow">

<h2 class="text-xl font-bold mb-4">
Моя зарплата
</h2>

<table class="w-full border">

<thead>

<tr class="bg-gray-100">

<th class="border p-2">Месяц</th>
<th class="border p-2">Базовая зарплата</th>
<th class="border p-2">Бонус</th>
<th class="border p-2">Вычеты</th>
<th class="border p-2">Итого</th>

</tr>

</thead>

<tbody>

@foreach($salaries as $salary)

<tr>

<td class="border p-2">
{{ $salary->salary_month->format('Y-m') }}
</td>

<td class="border p-2">
{{ number_format($salary->base_salary,2) }}
</td>

<td class="border p-2">
{{ number_format($salary->bonus,2) }}
</td>

<td class="border p-2">
{{ number_format($salary->deductions,2) }}
</td>

<td class="border p-2 font-bold">
{{ number_format($salary->net_salary,2) }}
</td>

</tr>

@endforeach

</tbody>

</table>

</div>

</x-filament::page>