@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

<h3 class="mb-4">💰 {{ __('app.debts') }}</h3>

{{-- ================= Total Debt ================= --}}
@php
$totalDebts = $students->sum('total_debt');
@endphp

<div class="alert alert-danger fw-bold">
💰 {{ __('app.total_debt') }}:
{{ number_format($totalDebts,2) }}
</div>

{{-- ================= Filter ================= --}}
<form method="GET" class="row mb-4">

    <div class="col-md-4">
        <input type="text"
               name="student_name"
               placeholder="{{ __('app.student') }}"
               value="{{ request('student_name') }}"
               class="form-control">
    </div>

    <div class="col-md-3 d-flex gap-2">
        <button class="btn btn-primary">
            {{ __('app.search') }}
        </button>

        <a href="{{ route('dashboard.debts.index') }}" class="btn btn-secondary">
            {{ __('app.reset') }}
        </a>
    </div>

</form>

{{-- ================= Table ================= --}}
<div class="card shadow-sm">

<div class="card-body">

<table class="table table-bordered table-striped align-middle">

<thead class="table-light">
<tr>
<th>#</th>
<th>{{ __('app.student') }}</th>
<th>{{ __('app.class') }}</th>
<th>{{ __('app.total_debt') }}</th>
<th>{{ __('app.details') }}</th>
<th>{{ __('app.notes') }}</th>
</tr>
</thead>

<tbody>

@forelse($students as $index => $s)

<tr>

<td>{{ $index + 1 }}</td>

<td class="fw-semibold">{{ $s->name }}</td>

<td>{{ $s->class->name_ar ?? '-' }}</td>

<td class="text-danger fw-bold">
{{ number_format($s->total_debt,2) }}
</td>

{{-- زر التفاصيل --}}
<td>
<a href="{{ route('dashboard.debts.show', $s->id) }}" 
   class="btn btn-sm btn-info">
   👁 {{ __('app.details') }}
</a>
</td>

{{-- الملاحظات --}}
<td style="max-width:200px;">
{{ \Illuminate\Support\Str::limit($s->debt_notes, 50) ?? '-' }}
</td>

</tr>

@empty

<tr>
<td colspan="6" class="text-center text-muted">
{{ __('app.no_records_found') }}
</td>
</tr>

@endforelse

</tbody>

</table>

</div>

</div>

</div>

@endsection