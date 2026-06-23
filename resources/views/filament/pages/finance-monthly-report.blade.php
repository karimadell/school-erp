<x-filament::page>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">

<div class="bg-white p-6 rounded-xl shadow">

<h3 class="text-lg font-bold">
Доход за месяц
</h3>

<p class="text-3xl font-bold mt-3 text-green-600">
{{ number_format($income,2) }} EGP
</p>

</div>


<div class="bg-white p-6 rounded-xl shadow">

<h3 class="text-lg font-bold">
Расходы за месяц
</h3>

<p class="text-3xl font-bold mt-3 text-red-600">
{{ number_format($expenses,2) }} EGP
</p>

</div>


<div class="bg-white p-6 rounded-xl shadow">

<h3 class="text-lg font-bold">
Прибыль
</h3>

<p class="text-3xl font-bold mt-3 text-blue-600">
{{ number_format($income - $expenses,2) }} EGP
</p>

</div>

</div>

</x-filament::page>