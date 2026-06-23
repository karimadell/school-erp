@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

<h3 class="mb-4">✏ {{ __('app.edit') }} {{ __('app.classes') }}</h3>

<div class="card">
<div class="card-body">

<form method="POST" action="{{ route('dashboard.classes.update',$class->id) }}">

@csrf
@method('PUT')

<div class="mb-3">
<label class="form-label">{{ __('app.code') }}</label>
<input type="text" name="code" class="form-control"
value="{{ $class->code }}">
</div>

<div class="mb-3">
<label class="form-label">Arabic Name</label>
<input type="text" name="name_ar" class="form-control"
value="{{ $class->name_ar }}">
</div>

<div class="mb-3">
<label class="form-label">Russian Name</label>
<input type="text" name="name_ru" class="form-control"
value="{{ $class->name_ru }}">
</div>

<div class="mb-3">
<label class="form-label">{{ __('app.grade') }}</label>

<select name="grade_id" class="form-control">

@foreach($grades as $grade)

<option value="{{ $grade->id }}"
{{ $class->grade_id==$grade->id?'selected':'' }}>

{{ $grade->name }}

</option>

@endforeach

</select>

</div>

<div class="mb-3">
<label class="form-label">{{ __('app.capacity') }}</label>
<input type="number" name="capacity" class="form-control"
value="{{ $class->capacity }}">
</div>

<div class="form-check mb-3">

<input type="checkbox"
name="is_active"
class="form-check-input"
{{ $class->is_active?'checked':'' }}>

<label class="form-check-label">
{{ __('app.active') }}
</label>

</div>

<button class="btn btn-primary">
{{ __('app.update') }}
</button>

</form>

</div>
</div>

</div>

@endsection