@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1">{{ $lockedCategory ? __('revenues.donation_page_title') : __('revenues.other_page_title') }}</h3>
        </div>
        <a href="{{ route('dashboard.finance.income.index') }}" class="btn btn-outline-secondary">← {{ __('revenues.back') }}</a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <strong>{{ __('revenues.validation_error') }}</strong>
            <ul class="mb-0 mt-2">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <form method="POST" action="{{ route('dashboard.finance.income.revenue.store') }}" enctype="multipart/form-data">
                @csrf
                @include('dashboard.finance.income.revenue._form')

                <hr class="my-4">
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success px-4">{{ __('revenues.save') }}</button>
                    <a href="{{ route('dashboard.finance.income.index') }}" class="btn btn-outline-secondary px-4">{{ __('revenues.cancel') }}</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
