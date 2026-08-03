@extends('layouts.dashboard')
@section('content')<div class="container-fluid py-3"><h1 class="h3 mb-4">Изменить форму обучения</h1><form method="post" action="{{ route('dashboard.academic.enrollment-modes.update',$enrollmentMode) }}">@csrf @method('PUT') @include('dashboard.enrollment-modes.partials.form')</form></div>@endsection
