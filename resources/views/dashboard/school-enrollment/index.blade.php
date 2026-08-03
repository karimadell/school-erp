@extends('layouts.dashboard')

@section('content')
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
    <div><h1 class="h3 mb-1">Зачисление учеников</h1><p class="text-muted mb-0">Современное оформление ученика, услуг и черновика счёта.</p></div>
    <a href="{{ route('dashboard.school-enrollment.create') }}" class="btn btn-primary">Зачислить ученика</a>
</div>
<div class="card shadow-sm border-0"><div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Ученик</th><th>Учебный год</th><th>Ступень</th><th>Класс</th><th>Группа</th><th>Дата</th></tr></thead>
    <tbody>@forelse($enrollments as $enrollment)<tr>
        <td>{{ $enrollment->student?->full_name }}</td><td>{{ $enrollment->academicYear?->name }}</td>
        <td>{{ $enrollment->stage?->name }}</td><td>{{ $enrollment->grade?->name }}</td><td>{{ $enrollment->schoolClass?->name }}</td>
        <td>{{ $enrollment->date?->format('d.m.Y') }}</td>
    </tr>@empty<tr><td colspan="6" class="text-center text-muted py-5">Зачисления пока не оформлены.</td></tr>@endforelse</tbody>
</table></div></div>
<div class="mt-3">{{ $enrollments->links() }}</div>
@endsection
