@extends('layouts.dashboard')

@section('content')
<div class="container py-4">
    <h3 class="fw-bold mb-4">🏫 {{ __('classroom.edit') }} — {{ $classroom->name }}</h3>
    <form method="POST" action="{{ route('dashboard.classrooms.update', $classroom) }}">
        @csrf
        @method('PUT')
        @include('dashboard.classrooms._form')
    </form>
</div>
@endsection
