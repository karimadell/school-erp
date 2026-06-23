@extends('layouts.dashboard')

@section('content')

<style>

/* Print optimization */
@media print {
    .no-print {
        display: none !important;
    }

    body {
        background: #fff;
    }

    .print-area {
        padding: 0;
    }
}

.report-header{
    border-bottom:2px solid #000;
    margin-bottom:20px;
    padding-bottom:10px;
}

.report-title{
    font-size:24px;
    font-weight:bold;
}

.summary-box{
    font-size:18px;
    font-weight:600;
}

</style>

<div class="container print-area">

<div class="no-print mb-4">

<h3 class="mb-3">🍽 Restaurant Daily Report</h3>

<form class="row g-2">

<div class="col-md-3">
<input type="date" name="date" value="{{ $date }}" class="form-control">
</div>

<div class="col-md-2">
<button class="btn btn-primary w-100">
Search
</button>
</div>

<div class="col-md-2">
<button onclick="window.print()" type="button" class="btn btn-success w-100">
🖨 Print
</button>
</div>

</form>

</div>


<div class="report-header text-center">

<div class="report-title">Restaurant Meal List</div>

<div>Date: {{ $date }}</div>

</div>


<div class="row mb-3">

<div class="col-md-4 summary-box">
Total Students: {{ $totalStudents }}
</div>

</div>


<div class="card">

<div class="card-body">

<table class="table table-bordered table-striped">

<thead>
<tr>
<th style="width:80px">#</th>
<th>Student Name</th>
<th>Class</th>
</tr>
</thead>

<tbody>

@forelse($students as $s)

<tr>
<td>{{ $loop->iteration }}</td>
<td>{{ $s->student->name }}</td>
<td>{{ $s->student->class->name_ar ?? '-' }}</td>
</tr>

@empty

<tr>
<td colspan="3" class="text-center">No students found</td>
</tr>

@endforelse

</tbody>

</table>

</div>

</div>


<div class="mt-4 text-end">

Prepared by Kitchen: ______________________

</div>


</div>

@endsection