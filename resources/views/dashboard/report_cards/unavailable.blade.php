@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

    <h3 class="mb-4">📑 {{ __('report_cards.title') }}</h3>

    <div class="alert alert-info">
        {{ __('report_cards.not_available_yet') }}
    </div>

    <a href="{{ route('dashboard.index') }}" class="btn btn-secondary">
        {{ __('report_cards.back') }}
    </a>

</div>

@endsection
