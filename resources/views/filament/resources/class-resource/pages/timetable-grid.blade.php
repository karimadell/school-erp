<x-filament::page>

<button
wire:click="generateTimetable"
class="bg-green-600 text-white px-4 py-2 rounded mb-4"
>
Generate Smart Timetable
</button>

<table class="w-full border text-center">

<thead class="bg-gray-100">

<tr>

<th class="border p-2">Урок</th>

@foreach($days as $day)

<th class="border p-2">
{{ $day->name_ru }}
</th>

@endforeach

</tr>

</thead>

<tbody>

@foreach($periods as $period)

<tr>

<td class="border p-2 font-bold">

{{ $period->number ?? $period->name }}

</td>

@foreach($days as $day)

@php
$lesson = $this->getLesson($day->id,$period->id);
@endphp

<td
class="border p-2 h-24"
x-data
x-on:drop.prevent="$wire.moveLesson({{ $day->id }},{{ $period->id }})"
x-on:dragover.prevent
>

@if($lesson)

<div
draggable="true"
x-on:dragstart="$wire.startDrag({{ $lesson->id }})"
class="p-2 rounded text-white font-bold cursor-move"
style="background-color: {{ $lesson->subject->color ?? '#6366f1' }}"
>

{{ $lesson->subject->name_ru }}

<div class="text-xs">

{{ $lesson->teacher->first_name }}

</div>

</div>

@endif

<form wire:submit.prevent="saveLesson({{ $day->id }},{{ $period->id }})">

<select
wire:model="selectedSubject.{{ $day->id }}.{{ $period->id }}"
class="border rounded p-1 mt-2 w-full"
>

<option value="">Subject</option>

@foreach($subjects as $subject)

<option value="{{$subject->id}}">

{{$subject->name_ru}}

</option>

@endforeach

</select>


<select
wire:model="selectedTeacher.{{ $day->id }}.{{ $period->id }}"
class="border rounded p-1 mt-1 w-full"
>

<option value="">Teacher</option>

@foreach($subjects->find($selectedSubject[$day->id][$period->id] ?? null)?->teachers ?? [] as $teacher)

<option value="{{$teacher->id}}">

{{$teacher->first_name}}

</option>

@endforeach

</select>


<button
class="bg-blue-500 text-white px-2 py-1 rounded mt-1 w-full"
>

Save

</button>

</form>

</td>

@endforeach

</tr>

@endforeach

</tbody>

</table>

</x-filament::page>