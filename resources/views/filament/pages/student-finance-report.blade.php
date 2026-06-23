<x-filament::page>

<div class="mb-6">

<x-filament::input
type="number"
wire:model="studentId"
placeholder="Student ID"
/>

<x-filament::button wire:click="loadStudent">
Загрузить
</x-filament::button>

</div>


@if($student)

<div class="mb-6">

<h2 class="text-xl font-bold">
{{ $student->full_name }}
</h2>

</div>


<div class="grid grid-cols-3 gap-6 mb-6">

<div class="bg-white p-6 rounded shadow">
Общий счет
<br>
<b>{{ number_format($total,2) }} EGP</b>
</div>

<div class="bg-white p-6 rounded shadow">
Оплачено
<br>
<b>{{ number_format($paid,2) }} EGP</b>
</div>

<div class="bg-white p-6 rounded shadow">
Долг
<br>
<b>{{ number_format($due,2) }} EGP</b>
</div>

</div>


<x-filament::button wire:click="downloadPdf">
Скачать PDF
</x-filament::button>

@endif

</x-filament::page>