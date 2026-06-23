@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

<h3 class="mb-4">🔥 {{ __('app.assign_fee_bulk') }}</h3>

<div class="card shadow-sm">
<div class="card-body">

<form method="POST" action="{{ route('dashboard.fees.bulk.assign') }}">
@csrf

<div class="row g-3">

{{-- Fee --}}
<div class="col-md-6">
<label class="form-label">{{ __('app.fee') }}</label>

<select name="fee_id" class="form-control" required>
<option value="">-- {{ __('app.select_fee') }} --</option>

@foreach($fees as $f)
<option value="{{ $f->id }}">
{{ $f->name_ru }} - {{ number_format($f->amount,2) }}
</option>
@endforeach

</select>
</div>

{{-- Class --}}
<div class="col-md-6">
<label class="form-label">{{ __('app.class') }}</label>

<select name="class_id" class="form-control">
<option value="">{{ __('app.all_classes') }}</option>

@foreach($classes as $c)
<option value="{{ $c->id }}">
{{ $c->name_ar }}
</option>
@endforeach

</select>
</div>

{{-- Students --}}
<div class="col-12">
<label class="form-label">{{ __('app.students') }}</label>

<select name="students[]" multiple class="form-control" style="height:220px;">

@foreach($students as $s)
<option value="{{ $s->id }}">
{{ $s->name }}
</option>
@endforeach

</select>

<small class="text-muted">
⚠️ لو لم تختار طلاب → سيتم التطبيق على كل الطلاب
</small>

</div>

{{-- Submit --}}
<div class="col-12 mt-3">

<button class="btn btn-success px-4">
🚀 {{ __('app.save') }}
</button>

<a href="{{ route('dashboard.fees.index') }}" class="btn btn-secondary">
↩ {{ __('app.cancel') }}
</a>

</div>

</div>

</form>

</div>
</div>

</div>

@endsection