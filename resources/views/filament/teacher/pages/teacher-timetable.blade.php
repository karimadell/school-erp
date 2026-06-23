<x-filament::page>

<div class="bg-white p-6 rounded shadow">

<h2 class="text-xl font-bold mb-4">
Расписание преподавателя
</h2>

<table class="w-full border">

<thead>

<tr class="bg-gray-100">

<th class="border p-2">День</th>
<th class="border p-2">Урок</th>
<th class="border p-2">Предмет</th>
<th class="border p-2">Класс</th>

</tr>

</thead>

<tbody>

@foreach($lessons as $lesson)

<tr>

<td class="border p-2">
{{ $lesson->day->name }}
</td>

<td class="border p-2">
{{ $lesson->period->name }}
</td>

<td class="border p-2">
{{ $lesson->subject->name }}
</td>

<td class="border p-2">
{{ $lesson->class->name }}
</td>

</tr>

@endforeach

</tbody>

</table>

</div>

</x-filament::page>