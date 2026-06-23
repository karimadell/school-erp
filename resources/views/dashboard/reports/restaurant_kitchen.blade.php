@extends('layouts.dashboard')

@section('content')

<style>
@media print {

    body {
        background: #fff;
    }

    .no-print {
        display: none;
    }

    .print-container {
        width: 100%;
        margin: 0;
        padding: 0;
    }

}

/* Layout */
.print-container {
    max-width: 900px;
    margin: auto;
    background: #fff;
    padding: 20px;
    border: 1px solid #ddd;
}

/* Header */
.report-header {
    text-align: center;
    margin-bottom: 20px;
}

.report-header h2 {
    margin: 5px 0;
}

.report-header small {
    color: #666;
}

/* Table */
.table th {
    background: #f1f1f1;
}

.signature {
    margin-top: 40px;
    display: flex;
    justify-content: space-between;
}
</style>


<div class="container py-4 no-print">

    <h3 class="mb-4">🍽 Kitchen Report</h3>

    {{-- Filter --}}
    <form method="GET" class="row mb-4">

        <div class="col-md-3">
            <input type="date" name="date" value="{{ $date }}" class="form-control">
        </div>

        <div class="col-md-3">
            <button class="btn btn-primary">🔍 Search</button>
            <a href="{{ route('dashboard.reports.restaurant.kitchen') }}" class="btn btn-secondary">Reset</a>
        </div>

        <div class="col-md-6 text-end">
            <button onclick="window.print()" class="btn btn-dark">🖨 Print</button>
        </div>

    </form>

</div>


{{-- ================= PRINT AREA ================= --}}
<div class="print-container">

    {{-- HEADER --}}
    <div class="report-header">

        {{-- ضع اللوجو هنا --}}
        {{-- <img src="/logo.png" height="60"> --}}

        <h2>School Name</h2>
        <h4>Kitchen Daily Report</h4>

        <small>Date: {{ $date }}</small>

    </div>


    {{-- TABLE --}}
    <table class="table table-bordered text-center">

        <thead>
            <tr>
                <th>#</th>
                <th>Class</th>
                <th>Students</th>
                <th>Meals</th>
            </tr>
        </thead>

        <tbody>

        @php $total = 0; @endphp

        @forelse($data as $index => $row)

            @php $total += $row->total; @endphp

            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $row->class_name }}</td>
                <td>{{ $row->total }}</td>
                <td>{{ $row->total }}</td>
            </tr>

        @empty

            <tr>
                <td colspan="4">No Data Available</td>
            </tr>

        @endforelse

        </tbody>

        <tfoot>
            <tr class="fw-bold">
                <td colspan="3">Total Meals</td>
                <td>{{ $total }}</td>
            </tr>
        </tfoot>

    </table>


    {{-- SIGNATURE --}}
    <div class="signature">

        <div>
            <strong>Kitchen Manager</strong><br>
            ______________________
        </div>

        <div>
            <strong>School Administration</strong><br>
            ______________________
        </div>

    </div>

</div>

@endsection