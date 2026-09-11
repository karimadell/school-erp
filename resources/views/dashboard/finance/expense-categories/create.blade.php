@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-1">{{ __('expenses.category_create') }}</h3>
        <a href="{{ route('dashboard.finance.expense-categories.index') }}" class="btn btn-outline-secondary">← {{ __('expenses.back') }}</a>
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
        <div class="card-body p-4">
            <form method="POST" action="{{ route('dashboard.finance.expense-categories.store') }}">
                @csrf
                @include('dashboard.finance.expense-categories._form', ['category' => null])

                <hr class="my-4">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success px-4">{{ __('expenses.save') }}</button>
                    <a href="{{ route('dashboard.finance.expense-categories.index') }}" class="btn btn-outline-secondary px-4">{{ __('expenses.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
