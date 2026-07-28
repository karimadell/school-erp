<x-filament::page>

<div class="space-y-6">

<div class="bg-white p-4 rounded shadow">

<h2 class="text-lg font-bold mb-4">
{{ __('teacher_grades.heading') }}
</h2>

<div class="flex gap-4 flex-wrap items-end">

<div>
<label class="block text-sm mb-1">{{ __('teacher_grades.select_class') }}</label>
<select wire:model="classId" wire:change="loadExams" class="border p-2 rounded">
<option value="">{{ __('teacher_grades.select_class') }}</option>
@foreach($assignedClasses as $class)
<option value="{{ $class->id }}">{{ $class->name }}</option>
@endforeach
</select>
</div>

<div>
<label class="block text-sm mb-1">{{ __('teacher_grades.select_subject') }}</label>
<select wire:model="subjectId" wire:change="loadExams" class="border p-2 rounded">
<option value="">{{ __('teacher_grades.select_subject') }}</option>
@foreach($assignedSubjects as $subject)
<option value="{{ $subject->id }}">{{ $subject->name }}</option>
@endforeach
</select>
</div>

<div>
<label class="block text-sm mb-1">{{ __('teacher_grades.select_exam') }}</label>
<select wire:model="examId" class="border p-2 rounded">
<option value="">{{ __('teacher_grades.select_exam') }}</option>
@foreach($exams as $exam)
<option value="{{ $exam->id }}">{{ $exam->name }}</option>
@endforeach
</select>
</div>

<button wire:click="loadStudents" class="bg-primary-600 text-white px-4 py-2 rounded">
{{ __('teacher_grades.load') }}
</button>

</div>

</div>

<div class="bg-white p-4 rounded shadow">

<h3 class="text-md font-bold mb-4">
{{ __('teacher_grades.new_exam_heading') }}
</h3>

<div class="flex gap-4 flex-wrap items-end">

<div>
<label class="block text-sm mb-1">{{ __('teacher_grades.exam_name') }}</label>
<input type="text" wire:model="newExamName" class="border p-2 rounded">
</div>

<div>
<label class="block text-sm mb-1">{{ __('teacher_grades.exam_date') }}</label>
<input type="date" wire:model="newExamDate" class="border p-2 rounded">
</div>

<div>
<label class="block text-sm mb-1">{{ __('teacher_grades.exam_max_score') }}</label>
<input type="number" wire:model="newExamMaxScore" class="border p-2 rounded w-24">
</div>

<div>
<label class="block text-sm mb-1">{{ __('teacher_grades.exam_quarter') }}</label>
<select wire:model="newExamQuarterId" class="border p-2 rounded">
<option value="">—</option>
@foreach($quarters as $quarter)
<option value="{{ $quarter->id }}">{{ $quarter->name }}</option>
@endforeach
</select>
</div>

<button wire:click="createExam" class="bg-primary-600 text-white px-4 py-2 rounded">
{{ __('teacher_grades.create_exam') }}
</button>

</div>

@error('newExamName') <p class="text-red-600 text-sm mt-2">{{ $message }}</p> @enderror
@error('newExamMaxScore') <p class="text-red-600 text-sm mt-2">{{ $message }}</p> @enderror
@error('newExamQuarterId') <p class="text-red-600 text-sm mt-2">{{ $message }}</p> @enderror

</div>

@if($students)

<div class="bg-white p-6 rounded shadow">

@if(count($students) === 0)
<p>{{ __('teacher_grades.no_exam_selected') }}</p>
@else

<table class="w-full border">

<thead>
<tr class="bg-gray-100">
<th class="border p-2">ID</th>
<th class="border p-2">{{ __('teacher_grades.student') }}</th>
<th class="border p-2">{{ __('teacher_grades.score') }}</th>
</tr>
</thead>

<tbody>
@foreach($students as $student)
<tr>
<td class="border p-2">{{ $student->id }}</td>
<td class="border p-2">{{ $student->full_name }}</td>
<td class="border p-2">
<input type="number" wire:model="grades.{{ $student->id }}" class="border p-1 w-20 rounded">
</td>
</tr>
@endforeach
</tbody>

</table>

<button wire:click="saveGrades" class="mt-4 bg-green-600 text-white px-6 py-2 rounded">
{{ __('teacher_grades.save') }}
</button>

@endif

</div>

@endif

</div>

</x-filament::page>
