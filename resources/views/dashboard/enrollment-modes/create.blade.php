@extends('layouts.dashboard')
@section('content')<div class="container-fluid py-3"><h1 class="h3 mb-4">Добавить форму обучения</h1><form method="post" action="{{ route('dashboard.academic.enrollment-modes.store') }}">@csrf @include('dashboard.enrollment-modes.partials.form')</form></div>@endsection
