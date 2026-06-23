@extends('layouts.dashboard')

@section('content')

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0 fw-bold">➕ {{ __('teachers.create') }}</h3>
            <small class="text-muted">{{ __('teachers.create_hint') }}</small>
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

    <form method="POST" action="{{ route('dashboard.teachers.store') }}">
        @csrf

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-primary text-white fw-bold">
                {{ __('teachers.personal_info') }}
            </div>

            <div class="card-body p-4">
                <div class="row g-4">

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">{{ __('teachers.last_name') }} <span class="text-danger">*</span></label>
                        <input type="text" name="last_name" value="{{ old('last_name') }}"
                               class="form-control form-control-lg @error('last_name') is-invalid @enderror"
                               placeholder="Иванова" required>
                        @error('last_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">{{ __('teachers.first_name') }} <span class="text-danger">*</span></label>
                        <input type="text" name="first_name" value="{{ old('first_name') }}"
                               class="form-control form-control-lg @error('first_name') is-invalid @enderror"
                               placeholder="Мария" required>
                        @error('first_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">{{ __('teachers.patronymic') }}</label>
                        <input type="text" name="patronymic" value="{{ old('patronymic') }}"
                               class="form-control form-control-lg @error('patronymic') is-invalid @enderror"
                               placeholder="Сергеевна">
                        @error('patronymic') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">{{ __('teachers.phone') }}</label>
                        <input type="text" name="phone" value="{{ old('phone') }}"
                               class="form-control @error('phone') is-invalid @enderror"
                               placeholder="+20 100 000 0000">
                        @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">{{ __('teachers.email') }}</label>
                        <input type="email" name="email" value="{{ old('email') }}"
                               class="form-control @error('email') is-invalid @enderror"
                               placeholder="teacher@example.com">
                        @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">{{ __('teachers.hire_date') }}</label>
                        <input type="date" name="hire_date" value="{{ old('hire_date') }}"
                               class="form-control @error('hire_date') is-invalid @enderror">
                        @error('hire_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-dark text-white fw-bold">
                {{ __('teachers.work_info') }}
            </div>

            <div class="card-body p-4">
                <div class="row g-4">

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">{{ __('teachers.specialization') }}</label>
                        <input type="text" name="specialization" value="{{ old('specialization') }}"
                               class="form-control @error('specialization') is-invalid @enderror"
                               placeholder="Учитель начальных классов">
                        @error('specialization') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">{{ __('teachers.status') }}</label>

                        <div class="border rounded-3 p-3 bg-light">
                            <div class="form-check form-switch">
                                <input type="hidden" name="is_active" value="0">

                                <input type="checkbox"
                                       name="is_active"
                                       value="1"
                                       class="form-check-input"
                                       id="isActive"
                                       @checked(old('is_active', 1) == 1)>

                                <label class="form-check-label fw-semibold" for="isActive">
                                    {{ __('teachers.active') }}
                                </label>
                            </div>

                            <div class="text-muted small mt-2">
                                {{ __('teachers.status_hint') }}
                            </div>
                        </div>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label fw-semibold">{{ __('teachers.subjects') }}</label>

                        <div class="border rounded-3 p-3">
                            <div class="row g-2">
                                @forelse($subjects as $subject)
                                    <div class="col-md-3">
                                        <label class="form-check">
                                            <input type="checkbox"
                                                   name="subjects[]"
                                                   value="{{ $subject->id }}"
                                                   class="form-check-input"
                                                   @checked(in_array($subject->id, old('subjects', [])))>
                                            <span class="form-check-label">
                                                {{ $subject->name_ru }}
                                            </span>
                                        </label>
                                    </div>
                                @empty
                                    <div class="text-muted">
                                        {{ __('teachers.no_subjects') }}
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-success px-4"
                    onclick="this.innerHTML='⏳ {{ __('teachers.saving') }}'">
                💾 {{ __('teachers.save') }}
            </button>

            <a href="{{ route('dashboard.teachers.index') }}" class="btn btn-outline-secondary px-4">
                {{ __('teachers.cancel') }}
            </a>
        </div>

    </form>

</div>

@endsection