@extends('layouts.dashboard')

@section('content')
<div class="container py-4">

    <div class="mb-4">
        <h3 class="mb-1">🛠️ {{ __('administration.title') }}</h3>
        <div class="text-muted">{{ __('administration.subtitle') }}</div>
    </div>

    @if($canManageSchoolSettings || $canViewAuditLogs)
        <h6 class="text-uppercase text-muted small fw-bold mb-3">{{ __('administration.section_system') }}</h6>
        <div class="row g-3 mb-4">
            @if($canManageSchoolSettings)
                <div class="col-md-4">
                    <a href="{{ route('dashboard.settings.school.edit') }}" class="card shadow-sm border-0 text-decoration-none h-100">
                        <div class="card-body">
                            <div class="fw-bold text-dark">⚙️ {{ __('administration.school_settings') }}</div>
                            <div class="text-muted small mt-1">{{ __('administration.school_settings_hint') }}</div>
                        </div>
                    </a>
                </div>
            @endif
            @if($canViewAuditLogs)
                <div class="col-md-4">
                    <a href="{{ route('dashboard.admin.audit.logs.index') }}" class="card shadow-sm border-0 text-decoration-none h-100">
                        <div class="card-body">
                            <div class="fw-bold text-dark">📜 {{ __('administration.audit_log') }}</div>
                            <div class="text-muted small mt-1">{{ __('administration.audit_log_hint') }}</div>
                        </div>
                    </a>
                </div>
            @endif
        </div>
    @endif

    @if($canViewTimetableInfrastructure)
        <h6 class="text-uppercase text-muted small fw-bold mb-3">{{ __('administration.section_academic') }}</h6>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <a href="{{ route('dashboard.bell-schedules.index') }}" class="card shadow-sm border-0 text-decoration-none h-100">
                    <div class="card-body">
                        <div class="fw-bold text-dark">🔔 {{ __('administration.bell_schedules') }}</div>
                        <div class="text-muted small mt-1">{{ __('administration.bell_schedules_hint') }}</div>
                    </div>
                </a>
            </div>
            <div class="col-md-4">
                <a href="{{ route('dashboard.academic-calendars.index') }}" class="card shadow-sm border-0 text-decoration-none h-100">
                    <div class="card-body">
                        <div class="fw-bold text-dark">📅 {{ __('administration.academic_calendar') }}</div>
                        <div class="text-muted small mt-1">{{ __('administration.academic_calendar_hint') }}</div>
                    </div>
                </a>
            </div>
            <div class="col-md-4">
                <a href="{{ route('dashboard.classrooms.index') }}" class="card shadow-sm border-0 text-decoration-none h-100">
                    <div class="card-body">
                        <div class="fw-bold text-dark">🏫 {{ __('administration.classrooms') }}</div>
                        <div class="text-muted small mt-1">{{ __('administration.classrooms_hint') }}</div>
                    </div>
                </a>
            </div>
        </div>
    @endif

    @if($canManageUsers || $canManageRoles)
        <h6 class="text-uppercase text-muted small fw-bold mb-3">{{ __('administration.section_technical') }}</h6>
        <div class="alert alert-light border small">{{ __('administration.technical_hint') }}</div>
        <div class="row g-3">
            @if($canManageUsers)
                <div class="col-md-4">
                    <a href="{{ route('filament.admin.resources.users.index') }}" class="card shadow-sm border-0 text-decoration-none h-100">
                        <div class="card-body">
                            <div class="fw-bold text-dark">👤 {{ __('administration.users') }}</div>
                            <div class="text-muted small mt-1">{{ __('administration.users_hint') }}</div>
                        </div>
                    </a>
                </div>
            @endif
            @if($canManageRoles)
                <div class="col-md-4">
                    <a href="{{ route('filament.admin.resources.roles.index') }}" class="card shadow-sm border-0 text-decoration-none h-100">
                        <div class="card-body">
                            <div class="fw-bold text-dark">🛡️ {{ __('administration.roles') }}</div>
                            <div class="text-muted small mt-1">{{ __('administration.roles_hint') }}</div>
                        </div>
                    </a>
                </div>
            @endif
        </div>
    @endif

</div>
@endsection
