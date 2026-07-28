<x-filament::page>

<div class="bg-white p-6 rounded shadow">

<h2 class="text-xl font-bold mb-4">
{{ __('teacher_salary.heading') }}
</h2>

@if(count($salaries) === 0)

<p>{{ __('teacher_salary.no_records') }}</p>

@else

<table class="w-full border">

<thead>

<tr class="bg-gray-100">

<th class="border p-2">{{ __('teacher_salary.month') }}</th>
<th class="border p-2">{{ __('teacher_salary.base_salary') }}</th>
<th class="border p-2">{{ __('teacher_salary.bonus') }}</th>
<th class="border p-2">{{ __('teacher_salary.deductions') }}</th>
<th class="border p-2">{{ __('teacher_salary.net_salary') }}</th>

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

@endif

</div>

</x-filament::page>