<x-filament::page>

<div class="mb-6 flex gap-2">

<select wire:model="studentId" wire:change="loadStudent" class="border rounded px-3 py-2 w-full">

<option value="">Select Student</option>

@foreach(\App\Models\Student::all() as $student)

<option value="{{ $student->id }}">
{{ $student->full_name }}
</option>

@endforeach

</select>

@if($student)

<button
wire:click="downloadPdf"
class="bg-green-600 text-white px-4 py-2 rounded"
>

Download PDF

</button>

@endif

</div>


@if($student)

<table class="w-full border text-center">

<thead class="bg-gray-100">

<tr>

<th class="border p-2">Subject</th>
<th class="border p-2">Q1</th>
<th class="border p-2">Q2</th>
<th class="border p-2">Q3</th>
<th class="border p-2">Q4</th>
<th class="border p-2">Year</th>

</tr>

</thead>

<tbody>

@foreach($subjects as $subject)

<tr>

<td class="border p-2">
{{ $subject->name_ru }}
</td>

<td class="border p-2">
{{ $this->getQuarterScore($subject->id,1) }}
</td>

<td class="border p-2">
{{ $this->getQuarterScore($subject->id,2) }}
</td>

<td class="border p-2">
{{ $this->getQuarterScore($subject->id,3) }}
</td>

<td class="border p-2">
{{ $this->getQuarterScore($subject->id,4) }}
</td>

<td class="border p-2 font-bold">
{{ $this->getYearScore($subject->id) }}
</td>

</tr>

@endforeach

</tbody>

</table>

@endif

</x-filament::page>