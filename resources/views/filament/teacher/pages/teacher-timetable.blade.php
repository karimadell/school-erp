<x-filament::page>

<div class="bg-white p-6 rounded shadow">

<h2 class="text-xl font-bold mb-4">
{{ __('teacher_timetable.heading') }}
</h2>

@if(count($lessons) === 0)

<p>{{ __('teacher_timetable.no_lessons') }}</p>

@else

<table class="w-full border">

<thead>

<tr class="bg-gray-100">

<th class="border p-2">{{ __('teacher_timetable.day') }}</th>
<th class="border p-2">{{ __('teacher_timetable.period') }}</th>
<th class="border p-2">{{ __('teacher_timetable.subject') }}</th>
<th class="border p-2">{{ __('teacher_timetable.class_label') }}</th>

</tr>

</thead>

<tbody>

@foreach($lessons as $lesson)

<tr>

<td class="border p-2">
{{ $lesson->day->name }}
</td>

<td class="border p-2">
{{ $lesson->period->number }} ({{ $lesson->period->start_time }}–{{ $lesson->period->end_time }})
</td>

<td class="border p-2">
{{ $lesson->subject->name }}
</td>

<td class="border p-2">
{{ $lesson->schoolClass->name }}
</td>

</tr>

@endforeach

</tbody>

</table>

@endif

</div>

</x-filament::page>
