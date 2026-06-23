@extends('layouts.dashboard')

@section('content')

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0 fw-bold">✏️ {{ __('teachers.edit') }}</h3>
            <small class="text-muted">{{ $teacher->full_name }}</small>
        </div>

        <a href="{{ route('dashboard.teachers.index') }}" class="btn btn-outline-secondary">
            ← {{ __('teachers.back') }}
        </a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger shadow-sm border-0">
            <strong>{{ __('teachers.validation_error') }}</strong>
            <ul class="mb-0 mt-2">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('dashboard.teachers.update', $teacher->id) }}">
        @csrf
        @method('PUT')

        <!-- PERSONAL -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-primary text-white fw-bold">
                {{ __('teachers.personal_info') }}
            </div>

            <div class="card-body p-4">
                <div class="row g-4">

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">{{ __('teachers.last_name') }}</label>
                        <input type="text" name="last_name"
                               value="{{ old('last_name', $teacher->last_name) }}"
                               class="form-control form-control-lg">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">{{ __('teachers.first_name') }}</label>
                        <input type="text" name="first_name"
                               value="{{ old('first_name', $teacher->first_name) }}"
                               class="form-control form-control-lg">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">{{ __('teachers.patronymic') }}</label>
                        <input type="text" name="patronymic"
                               value="{{ old('patronymic', $teacher->patronymic) }}"
                               class="form-control form-control-lg">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">{{ __('teachers.phone') }}</label>
                        <input type="text" name="phone"
                               value="{{ old('phone', $teacher->phone) }}"
                               class="form-control">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">{{ __('teachers.email') }}</label>
                        <input type="email" name="email"
                               value="{{ old('email', $teacher->email) }}"
                               class="form-control">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">{{ __('teachers.hire_date') }}</label>
                        <input type="date" name="hire_date"
                               value="{{ old('hire_date', optional($teacher->hire_date)->format('Y-m-d')) }}"
                               class="form-control">
                    </div>

                </div>
            </div>
        </div>

        <!-- WORK -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-dark text-white fw-bold">
                {{ __('teachers.work_info') }}
            </div>

            <div class="card-body p-4">
                <div class="row g-4">

                    <div class="col-md-6">
                        <label class="form-label">{{ __('teachers.specialization') }}</label>
                        <input type="text" name="specialization"
                               value="{{ old('specialization', $teacher->specialization) }}"
                               class="form-control">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">{{ __('teachers.status') }}</label>

                        <div class="border rounded p-3 bg-light">
                            <input type="hidden" name="is_active" value="0">

                            <div class="form-check form-switch">
                                <input type="checkbox"
                                       name="is_active"
                                       value="1"
                                       class="form-check-input"
                                       id="isActive"
                                       @checked(old('is_active', $teacher->is_active))>

                                <label class="form-check-label fw-semibold" for="isActive">
                                    {{ __('teachers.active') }}
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- SUBJECTS -->
                    <div class="col-md-12">
                        <label class="form-label fw-semibold">{{ __('teachers.subjects') }}</label>

                        <div class="border rounded p-3">
                            <div class="row g-2">

                                @foreach($subjects as $subject)
                                    <div class="col-md-3">
                                        <label class="form-check">

                                            <input type="checkbox"
                                                   name="subjects[]"
                                                   value="{{ $subject->id }}"
                                                   class="form-check-input"
                                                   @checked(
                                                       in_array(
                                                           $subject->id,
                                                           old('subjects', $teacher->subjects->pluck('id')->toArray())
                                                       )
                                                   )>

                                            <span class="form-check-label">
                                                {{ $subject->name_ru }}
                                            </span>

                                        </label>
                                    </div>
                                @endforeach

                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- ACTIONS -->
        <div class="d-flex gap-2">

            <button type="submit" class="btn btn-success px-4">
                💾 {{ __('teachers.update') }}
            </button>

            <a href="{{ route('dashboard.teachers.index') }}" class="btn btn-outline-secondary px-4">
                {{ __('teachers.cancel') }}
            </a>

        </div>

    </form>

</div>

@endsection