@if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<div class="card shadow-sm border-0"><div class="card-body">
    <div class="alert alert-light border">
        <strong>{{ __('quarters.academic_year') }}:</strong> {{ $academicYear->name }}
        ({{ $academicYear->start_date->format('d.m.Y') }}–{{ $academicYear->end_date->format('d.m.Y') }})
    </div>
    <div class="row g-3">
        <div class="col-md-8"><label class="form-label" for="name">{{ __('quarters.name') }}</label><input class="form-control" id="name" name="name" required value="{{ old('name', $quarter->name ?? '') }}"></div>
        <div class="col-md-4"><label class="form-label" for="order">{{ __('quarters.order') }}</label><input class="form-control" id="order" name="order" type="number" min="1" max="255" required value="{{ old('order', $quarter->order ?? 1) }}"></div>
        <div class="col-md-6"><label class="form-label" for="start_date">{{ __('quarters.start_date') }}</label><input class="form-control" id="start_date" name="start_date" type="date" required min="{{ $academicYear->start_date->format('Y-m-d') }}" max="{{ $academicYear->end_date->format('Y-m-d') }}" value="{{ old('start_date', isset($quarter) ? $quarter->start_date?->format('Y-m-d') : '') }}"></div>
        <div class="col-md-6"><label class="form-label" for="end_date">{{ __('quarters.end_date') }}</label><input class="form-control" id="end_date" name="end_date" type="date" required min="{{ $academicYear->start_date->format('Y-m-d') }}" max="{{ $academicYear->end_date->format('Y-m-d') }}" value="{{ old('end_date', isset($quarter) ? $quarter->end_date?->format('Y-m-d') : '') }}"></div>
    </div>
    <div class="mt-4 d-flex gap-2"><button class="btn btn-primary" type="submit">{{ __('quarters.save') }}</button><a href="{{ route('dashboard.academic-years.quarters.index', $academicYear) }}" class="btn btn-secondary">{{ __('quarters.cancel') }}</a></div>
</div></div>
