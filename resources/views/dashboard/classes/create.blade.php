@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0">🏫 {{ __('classes.create') }}</h3>

        <a href="{{ route('dashboard.classes.index') }}" class="btn btn-secondary">
            {{ __('classes.back') }}
        </a>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body">

            <form method="POST" action="{{ route('dashboard.classes.store') }}">
                @csrf

                <div class="row g-3">

                    <div class="col-md-4">
                        <label class="form-label">{{ __('classes.code') }}</label>
                        <input type="text" name="code" class="form-control" placeholder="1-A" required>
                    </div>

                    <div class="col-md-8">
                        <label class="form-label">{{ __('classes.name_ru') }}</label>
                        <input type="text" name="name_ru" class="form-control" placeholder="1 класс" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">{{ __('classes.stage') }}</label>
                        <select id="stageSelect" class="form-select">
                            <option value="">{{ __('classes.select_stage') }}</option>
                            @foreach($stages as $stage)
                                <option value="{{ $stage->id }}">{{ $stage->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">{{ __('classes.grade') }}</label>
                        <select name="grade_id" id="gradeSelect" class="form-select" required>
                            <option value="">{{ __('classes.select_grade') }}</option>
                            @foreach($grades as $grade)
                                <option value="{{ $grade->id }}" data-stage="{{ $grade->stage_id }}">
                                    {{ $grade->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">{{ __('classes.capacity') }}</label>
                        <input type="number" name="capacity" class="form-control" value="25" min="1">
                    </div>

                    <div class="col-md-12">
                        <div class="form-check mt-2">
                            <input type="checkbox" name="is_active" id="is_active" class="form-check-input" checked>
                            <label class="form-check-label" for="is_active">
                                {{ __('classes.active') }}
                            </label>
                        </div>
                    </div>

                </div>

                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-success">
                        💾 {{ __('classes.save') }}
                    </button>

                    <a href="{{ route('dashboard.classes.index') }}" class="btn btn-outline-secondary">
                        {{ __('classes.cancel') }}
                    </a>
                </div>

            </form>

        </div>
    </div>

</div>

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const stageSelect = document.getElementById('stageSelect');
    const gradeSelect = document.getElementById('gradeSelect');

    stageSelect.addEventListener('change', function () {
        const stageId = this.value;

        Array.from(gradeSelect.options).forEach(option => {
            if (!option.value) return;
            option.hidden = stageId && option.dataset.stage !== stageId;
        });

        gradeSelect.value = '';
    });
});
</script>
@endpush