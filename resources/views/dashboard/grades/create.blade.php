@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0">➕ {{ __('grades.create') }}</h3>

        <a href="{{ $returnStage ? route('dashboard.stages.show', $returnStage) : route('dashboard.grades.index') }}" class="btn btn-secondary">
            {{ $returnStage ? __('grades.back_to_structure') : __('grades.back') }}
        </a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card shadow-sm border-0">
        <div class="card-body">

            <form method="POST" action="{{ route('dashboard.grades.store') }}">
                @csrf
                @if($returnStage)
                    <input type="hidden" name="return_to" value="dashboard.stages.show">
                    <input type="hidden" name="return_stage_id" value="{{ $returnStage->id }}">
                @endif

                <div class="mb-3">
                    <label class="form-label">{{ __('grades.name') }}</label>
                    <input type="text"
                           name="name"
                           class="form-control"
                           value="{{ old('name') }}"
                           placeholder="1 класс"
                           required>
                </div>

                <div class="mb-3">
                    <label class="form-label">{{ __('grades.stage') }}</label>
                    @if($selectedStage)
                        <input type="hidden" name="stage_id" value="{{ $selectedStage->id }}">
                        <input type="text" class="form-control" value="{{ $selectedStage->name }}" readonly>
                    @else
                        <select name="stage_id" class="form-select" required>
                            <option value="">{{ __('grades.select_stage') }}</option>
                            @foreach($stages as $stage)
                                <option value="{{ $stage->id }}" @selected(old('stage_id') == $stage->id)>
                                    {{ $stage->name }}
                                </option>
                            @endforeach
                        </select>
                    @endif
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success">
                        💾 {{ __('grades.save') }}
                    </button>

                    <a href="{{ $returnStage ? route('dashboard.stages.show', $returnStage) : route('dashboard.grades.index') }}" class="btn btn-secondary">
                        {{ __('grades.cancel') }}
                    </a>
                </div>
            </form>

        </div>
    </div>

</div>

@endsection
