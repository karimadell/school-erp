@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
        <div>
            <div class="text-muted small">{{ __('quarters.academic_year') }}</div>
            <h3 class="fw-bold mb-1">{{ __('quarters.title') }} — {{ $academicYear->name }}</h3>
            @if($academicYear->is_active)
                <span class="badge bg-success">{{ __('academic_years.active') }}</span>
            @elseif($academicYear->isUnlocked())
                <span class="badge bg-warning text-dark">{{ __('academic_years.unlocked') }}</span>
            @else
                <span class="badge bg-secondary">{{ __('academic_years.locked_status') }}</span>
            @endif
        </div>
        <div class="d-flex gap-2">
            @if($academicYear->is_active || $academicYear->isUnlocked())
                <a href="{{ route('dashboard.academic-years.quarters.create', $academicYear) }}" class="btn btn-primary">
                    {{ __('quarters.create') }}
                </a>
            @endif
            <a href="{{ route('dashboard.academic-years.index') }}" class="btn btn-secondary">{{ __('quarters.back') }}</a>
        </div>
    </div>

    @if(!$academicYear->is_active && !$academicYear->isUnlocked())
        <div class="alert alert-warning">{{ __('quarters.locked_hint') }}</div>
    @endif
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>{{ __('quarters.order') }}</th><th>{{ __('quarters.name') }}</th>
                    <th>{{ __('quarters.start_date') }}</th><th>{{ __('quarters.end_date') }}</th>
                    <th class="text-end">{{ __('quarters.actions') }}</th>
                </tr></thead>
                <tbody>
                @forelse($quarters as $quarter)
                    <tr>
                        <td>{{ $quarter->order }}</td><td>{{ $quarter->name }}</td>
                        <td>{{ $quarter->start_date?->format('d.m.Y') }}</td>
                        <td>{{ $quarter->end_date?->format('d.m.Y') }}</td>
                        <td class="text-end">
                            @if($academicYear->is_active || $academicYear->isUnlocked())
                                <a href="{{ route('dashboard.academic-years.quarters.edit', [$academicYear, $quarter]) }}" class="btn btn-sm btn-warning">{{ __('quarters.edit') }}</a>
                                <form method="POST" action="{{ route('dashboard.academic-years.quarters.destroy', [$academicYear, $quarter]) }}" class="d-inline" onsubmit="return confirm('{{ __('quarters.confirm_delete') }}')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-danger" type="submit">{{ __('quarters.delete') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">{{ __('quarters.no_data') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
