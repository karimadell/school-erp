@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1">✏️ {{ __('subjects.edit') }}</h3>
            <div class="text-muted">{{ __('subjects.edit_hint') }}</div>
        </div>

        <a href="{{ route('dashboard.subjects.index') }}" class="btn btn-outline-secondary">
            ← {{ __('subjects.back') }}
        </a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger shadow-sm border-0">
            <strong>{{ __('subjects.validation_error') }}</strong>
            <ul class="mb-0 mt-2">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card shadow-sm border-0">
        <div class="card-header bg-warning fw-bold">
            {{ __('subjects.subject_info') }}
        </div>

        <div class="card-body p-4">

            <form method="POST" action="{{ route('dashboard.subjects.update', $subject->id) }}">
                @csrf
                @method('PUT')

                <div class="row g-4">

                    <div class="col-md-8">
                        <label class="form-label fw-semibold">
                            {{ __('subjects.name_ru') }}
                            <span class="text-danger">*</span>
                        </label>

                        <input type="text"
                               name="name_ru"
                               value="{{ old('name_ru', $subject->name_ru) }}"
                               class="form-control form-control-lg @error('name_ru') is-invalid @enderror"
                               placeholder="{{ __('subjects.name_placeholder') }}"
                               required>

                        @error('name_ru')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            {{ __('subjects.status') }}
                        </label>

                        <div class="border rounded-3 p-3 bg-light">
                            <div class="form-check form-switch">
                                <input type="hidden" name="is_active" value="0">

                                <input type="checkbox"
                                       name="is_active"
                                       value="1"
                                       class="form-check-input"
                                       id="isActive"
                                       @checked(old('is_active', $subject->is_active ?? 1) == 1)>

                                <label class="form-check-label fw-semibold" for="isActive">
                                    {{ __('subjects.active') }}
                                </label>
                            </div>

                            <div class="text-muted small mt-2">
                                {{ __('subjects.status_hint') }}
                            </div>
                        </div>
                    </div>

                </div>

                <hr class="my-4">

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success px-4">
                        💾 {{ __('subjects.save') }}
                    </button>

                    <a href="{{ route('dashboard.subjects.index') }}" class="btn btn-outline-secondary px-4">
                        {{ __('subjects.cancel') }}
                    </a>
                </div>

            </form>

        </div>
    </div>

</div>

@endsection