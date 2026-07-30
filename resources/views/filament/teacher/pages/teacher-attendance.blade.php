<x-filament::page>

<div class="space-y-6">

<div class="bg-white p-4 rounded shadow">

<h2 class="text-lg font-bold mb-4">
{{ __('attendance.select_class') }}
</h2>

<select wire:model="classId" class="border p-2 rounded">

<option value="">---</option>

@foreach($assignedClasses as $class)

<option value="{{ $class->id }}">
{{ $class->name }}
</option>

@endforeach

</select>

<button
wire:click="loadStudents"
class="ml-2 bg-primary-600 text-white px-4 py-2 rounded"
>
{{ __('attendance.load') }}
</button>

</div>

@if($students)

<div class="bg-white p-6 rounded shadow">

<h2 class="text-lg font-bold mb-4">
{{ __('attendance.title') }}
</h2>

<table class="w-full border">

<thead>

<tr class="bg-gray-100">

<th class="p-2 border">ID</th>

<th class="p-2 border">{{ __('attendance.student') }}</th>

<th class="p-2 border">{{ __('attendance.status') }}</th>

</tr>

</thead>

<tbody>

@foreach($students as $enrollment)

<tr>

<td class="border p-2">
{{ $enrollment->student_id }}
</td>

<td class="border p-2">
{{ $enrollment->student->full_name }}
</td>

<td class="border p-2">

<select wire:model="attendance.{{ $enrollment->id }}" class="border p-1 rounded">

<option value="present">{{ __('attendance.present') }}</option>

<option value="absent">{{ __('attendance.absent') }}</option>

<option value="late">{{ __('attendance.late') }}</option>

<option value="excused">{{ __('attendance.excused') }}</option>

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

{{ __('attendance.save') }}

</button>

</div>

@endif

</div>

</x-filament::page>