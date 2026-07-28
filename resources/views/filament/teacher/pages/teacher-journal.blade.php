<x-filament::page>

<div class="space-y-6">

<div class="bg-white p-4 rounded shadow">

<h2 class="text-lg font-bold mb-4">
{{ __('teacher_journal.heading') }}
</h2>

<div class="flex gap-4 flex-wrap items-end">

<div>
<label class="block text-sm mb-1">{{ __('teacher_journal.select_class') }}</label>
<select wire:model="classId" class="border p-2 rounded">
<option value="">—</option>
@foreach($assignedClasses as $class)
<option value="{{ $class->id }}">{{ $class->name }}</option>
@endforeach
</select>
</div>

<div>
<label class="block text-sm mb-1">{{ __('teacher_journal.select_subject') }}</label>
<select wire:model="subjectId" class="border p-2 rounded">
<option value="">—</option>
@foreach($assignedSubjects as $subject)
<option value="{{ $subject->id }}">{{ $subject->name }}</option>
@endforeach
</select>
</div>

<button wire:click="loadEntries" class="bg-primary-600 text-white px-4 py-2 rounded">
{{ __('teacher_journal.load') }}
</button>

</div>

</div>

<div class="bg-white p-4 rounded shadow">

<h3 class="text-md font-bold mb-4">
{{ $editingId ? __('teacher_journal.edit_entry') : __('teacher_journal.add_entry') }}
</h3>

<div class="flex gap-4 flex-wrap items-end">

<div>
<label class="block text-sm mb-1">{{ __('teacher_journal.date') }}</label>
<input type="date" wire:model="date" class="border p-2 rounded">
</div>

<div class="flex-1">
<label class="block text-sm mb-1">{{ __('teacher_journal.title_field') }}</label>
<input type="text" wire:model="lessonTitle" class="border p-2 rounded w-full">
</div>

</div>

<div class="mt-4">
<label class="block text-sm mb-1">{{ __('teacher_journal.notes') }}</label>
<textarea wire:model="notes" class="border p-2 rounded w-full" rows="2"></textarea>
</div>

<div class="mt-4">
<label class="block text-sm mb-1">{{ __('teacher_journal.homework') }}</label>
<textarea wire:model="homework" class="border p-2 rounded w-full" rows="2"></textarea>
</div>

@error('date') <p class="text-red-600 text-sm mt-2">{{ $message }}</p> @enderror
@error('lessonTitle') <p class="text-red-600 text-sm mt-2">{{ $message }}</p> @enderror

<div class="mt-4 flex gap-2">

<button wire:click="saveEntry" class="bg-green-600 text-white px-6 py-2 rounded">
{{ __('teacher_journal.save') }}
</button>

@if($editingId)
<button wire:click="cancelEdit" class="bg-gray-300 px-6 py-2 rounded">
{{ __('teacher_journal.cancel') }}
</button>
@endif

</div>

</div>

<div class="bg-white p-6 rounded shadow">

@if(count($entries) === 0)

<p>{{ __('teacher_journal.no_entries') }}</p>

@else

<table class="w-full border">

<thead>
<tr class="bg-gray-100">
<th class="border p-2">{{ __('teacher_journal.date') }}</th>
<th class="border p-2">{{ __('teacher_journal.title_field') }}</th>
<th class="border p-2">{{ __('teacher_journal.homework') }}</th>
<th class="border p-2"></th>
</tr>
</thead>

<tbody>
@foreach($entries as $entry)
<tr>
<td class="border p-2">{{ $entry->date->format('Y-m-d') }}</td>
<td class="border p-2">{{ $entry->title }}</td>
<td class="border p-2">{{ $entry->homework }}</td>
<td class="border p-2">
<button wire:click="editEntry({{ $entry->id }})" class="text-primary-600">
{{ __('teacher_journal.edit_entry') }}
</button>
</td>
</tr>
@endforeach
</tbody>

</table>

@endif

</div>

</div>

</x-filament::page>
