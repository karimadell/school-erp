@extends('layouts.dashboard')

@section('content')

<div class="container">

<h3 class="mb-4">📄 Student Report Card</h3>

<div class="card mb-4">

<div class="card-body">

<h5>{{ $student->name }}</h5>

<p>
Class: {{ $student->class->name_ar ?? '-' }}
</p>

<p>
Grade: {{ $student->class->grade->name ?? '-' }}
</p>

</div>

</div>

<table class="table table-bordered">

<thead>
<tr>
<th>Subject</th>
<th>Mark</th>
</tr>
</thead>

<tbody>

@foreach($subjects as $subject)

<tr>
<td>{{ $subject->name }}</td>
<td>{{ $subject->pivot->mark ?? '-' }}</td>
</tr>

@endforeach

</tbody>

</table>

<a href="{{ route('dashboard.report_cards.print',$student->id) }}" class="btn btn-success">
🖨 Print Report
</a>

</div>

@endsection