<x-filament::page>

<div class="space-y-6">

<div class="bg-white p-4 rounded shadow">

<h2 class="text-lg font-bold mb-4">
Введите оценки
</h2>

<div class="flex gap-4">

<select wire:model="classId" class="border p-2 rounded">

<option value="">Класс</option>

@foreach(\App\Models\SchoolClass::all() as $class)

<option value="{{ $class->id }}">
{{ $class->name }}
</option>

@endforeach

</select>


<select wire:model="subjectId" class="border p-2 rounded">

<option value="">Предмет</option>

@foreach(\App\Models\Subject::all() as $subject)

<option value="{{ $subject->id }}">
{{ $subject->name }}
</option>

@endforeach

</select>


<select wire:model="quarterId" class="border p-2 rounded">

<option value="">Четверть</option>

@foreach(\App\Models\Quarter::all() as $quarter)

<option value="{{ $quarter->id }}">
{{ $quarter->name }}
</option>

@endforeach

</select>

<button
wire:click="loadStudents"
class="bg-primary-600 text-white px-4 py-2 rounded"
>

Загрузить

</button>

</div>

</div>

@if($students)

<div class="bg-white p-6 rounded shadow">

<table class="w-full border">

<thead>

<tr class="bg-gray-100">

<th class="border p-2">ID</th>
<th class="border p-2">Студент</th>
<th class="border p-2">Оценка</th>

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

<input
type="number"
wire:model="grades.{{ $student->id }}"
class="border p-1 w-20 rounded"
>

</td>

</tr>

@endforeach

</tbody>

</table>

<button
wire:click="saveGrades"
class="mt-4 bg-green-600 text-white px-6 py-2 rounded"
>

Сохранить оценки

</button>

</div>

@endif

</div>

</x-filament::page>