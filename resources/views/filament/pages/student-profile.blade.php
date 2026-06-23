<x-filament::page>

<div class="mb-6">

<select wire:model="studentId" wire:change="loadStudent" class="border rounded px-3 py-2 w-full">

<option value="">Select Student</option>

@foreach(\App\Models\Student::orderBy('first_name')->get() as $studentOption)

<option value="{{ $studentOption->id }}">
{{ $studentOption->full_name }}
</option>

@endforeach

</select>

</div>


@if($student)

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">

<div class="border rounded p-4">
<div class="text-sm text-gray-500">Student</div>
<div class="text-lg font-bold">{{ $student->full_name }}</div>
</div>

<div class="border rounded p-4">
<div class="text-sm text-gray-500">Class</div>
<div class="text-lg font-bold">{{ $student->class?->name ?? '-' }}</div>
</div>

<div class="border rounded p-4">
<div class="text-sm text-gray-500">Average</div>
<div class="text-lg font-bold">{{ $student->averageGrade() }}</div>
</div>

</div>


<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">

<div class="border rounded p-4">
<div class="text-sm text-gray-500">Present</div>
<div class="text-lg font-bold text-green-600">
{{ $this->getAttendanceCount('present') }}
</div>
</div>

<div class="border rounded p-4">
<div class="text-sm text-gray-500">Absent</div>
<div class="text-lg font-bold text-red-600">
{{ $this->getAttendanceCount('absent') }}
</div>
</div>

<div class="border rounded p-4">
<div class="text-sm text-gray-500">Late</div>
<div class="text-lg font-bold text-yellow-600">
{{ $this->getAttendanceCount('late') }}
</div>
</div>

</div>


<div class="mb-4">

<a
href="{{ \App\Filament\Pages\ReportCard::getUrl() }}"
class="inline-block bg-blue-600 text-white px-4 py-2 rounded"
>

Open Report Card

</a>

</div>


<table class="w-full border text-center">

<thead class="bg-gray-100">

<tr>

<th class="border p-2">Subject</th>
<th class="border p-2">Q1</th>
<th class="border p-2">Q2</th>
<th class="border p-2">Q3</th>
<th class="border p-2">Q4</th>
<th class="border p-2">Average</th>

</tr>

</thead>


<tbody>

@foreach(\App\Models\Subject::where('is_active', true)->get() as $subject)

<tr>

<td class="border p-2">
{{ $subject->name_ru }}
</td>


@for($q = 1; $q <= 4; $q++)

<td class="border p-2">

@php
$grade = $student->grades
->where('subject_id', $subject->id)
->where('quarter_id', $q)
->first();
@endphp

{{ $grade?->score ?? '-' }}

</td>

@endfor


<td class="border p-2 font-bold">

{{ $this->getSubjectAverage($subject->id) }}

</td>

</tr>

@endforeach

</tbody>

</table>

@endif

</x-filament::page>