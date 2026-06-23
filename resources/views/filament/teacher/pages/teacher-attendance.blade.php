<x-filament::page>

<div class="space-y-6">

<div class="bg-white p-4 rounded shadow">

<h2 class="text-lg font-bold mb-4">
Выберите класс
</h2>

<select wire:model="classId" class="border p-2 rounded">

<option value="">---</option>

@foreach(\App\Models\SchoolClass::all() as $class)

<option value="{{ $class->id }}">
{{ $class->name }}
</option>

@endforeach

</select>

<button
wire:click="loadStudents"
class="ml-2 bg-primary-600 text-white px-4 py-2 rounded"
>
Загрузить
</button>

</div>

@if($students)

<div class="bg-white p-6 rounded shadow">

<h2 class="text-lg font-bold mb-4">
Посещаемость
</h2>

<table class="w-full border">

<thead>

<tr class="bg-gray-100">

<th class="p-2 border">ID</th>

<th class="p-2 border">Студент</th>

<th class="p-2 border">Статус</th>

</tr>

</thead>

<tbody>

@foreach($students as $student)

<tr>

<td class="border p-2">
{{ $student->id }}
</td>

<td class="border p-2">
{{ $student->full_name }}
</td>

<td class="border p-2">

<select wire:model="attendance.{{ $student->id }}" class="border p-1 rounded">

<option value="present">Присутствует</option>

<option value="absent">Отсутствует</option>

<option value="late">Опоздал</option>

</select>

</td>

</tr>

@endforeach

</tbody>

</table>

<button
wire:click="saveAttendance"
class="mt-4 bg-green-600 text-white px-6 py-2 rounded"
>

Сохранить

</button>

</div>

@endif

</div>

</x-filament::page>