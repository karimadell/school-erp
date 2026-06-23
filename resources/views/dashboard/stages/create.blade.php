@extends('layouts.dashboard')

@section('content')
<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold">{{ __('stages.add_stage') }}</h3>

        <a href="{{ route('dashboard.stages.index') }}" class="btn btn-secondary">
            {{ __('stages.back') }}
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
            <form action="{{ route('dashboard.stages.store') }}" method="POST">
                @csrf

                <div class="mb-3">
                    <label for="name" class="form-label">{{ __('stages.name') }}</label>
                    <input
                        type="text"
                        name="name"
                        id="name"
                        class="form-control"
                        value="{{ old('name') }}"
                        required
                    >
                </div>

                <div class="mb-3">
                    <label for="order" class="form-label">{{ __('stages.order') }}</label>
                    <input
                        type="number"
                        name="order"
                        id="order"
                        class="form-control"
                        value="{{ old('order', 0) }}"
                    >
                </div>

                @if(isset($stage) || \Schema::hasColumn('stages', 'is_active'))
                    <div class="form-check mb-3">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="is_active"
                            id="is_active"
                            value="1"
                            {{ old('is_active', 1) ? 'checked' : '' }}
                        >
                        <label class="form-check-label" for="is_active">
                            {{ __('stages.active') }}
                        </label>
                    </div>
                @endif

                <button type="submit" class="btn btn-primary">
                    {{ __('stages.save') }}
                </button>
            </form>
        </div>
    </div>

</div>
@endsection