@extends('layouts.dashboard')

@section('content')

<div class="container">

<h3 class="mb-4">📑 Report Cards</h3>

<table class="table table-bordered table-striped">

<thead>
<tr>
<th>ID</th>
<th>{{ __('app.name') }}</th>
<th>{{ __('app.classes') }}</th>
<th>{{ __('app.actions') }}</th>
</tr>
</thead>

<tbody>

@foreach($students as $student)

<tr>

<td>{{ $student->id }}</td>

<td>{{ $student->name }}</td>

<td>{{ $student->class->name_ar ?? '-' }}</td>

<td>

<a href="{{ route('dashboard.report_cards.show',$student->id) }}" class="btn btn-primary btn-sm">
View
</a>

<a href="{{ route('dashboard.report_cards.print',$student->id) }}" class="btn btn-success btn-sm">
Print
</a>

</td>

</tr>

@endforeach

</tbody>

</table>

{{ $students->links() }}

</div>

@endsection