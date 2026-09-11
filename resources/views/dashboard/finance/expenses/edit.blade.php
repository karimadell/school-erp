@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1">{{ __('expenses.edit_title') }}</h3>
            <div class="text-muted">{{ $expense->reference_number }}</div>
        </div>
        <a href="{{ route('dashboard.finance.expenses.show', $expense) }}" class="btn btn-outline-secondary">← {{ __('expenses.back') }}</a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <strong>{{ __('expenses.validation_error') }}</strong>
            <ul class="mb-0 mt-2">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <form method="POST" action="{{ route('dashboard.finance.expenses.update', $expense) }}" enctype="multipart/form-data">
                @csrf
                @method('PUT')
                @include('dashboard.finance.expenses._form', ['expense' => $expense])

                <hr class="my-4">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success px-4">{{ __('expenses.save') }}</button>
                    <a href="{{ route('dashboard.finance.expenses.show', $expense) }}" class="btn btn-outline-secondary px-4">{{ __('expenses.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
