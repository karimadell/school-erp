@if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<div class="card shadow-sm border-0"><div class="card-body">
    <div class="alert alert-light border">
        <strong>{{ __('bell_schedule.fields.name') }}:</strong> {{ $bellSchedule->name }}
    </div>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label" for="period_number">{{ __('bell_schedule.fields.period_number') }}</label>
            <input class="form-control" id="period_number" name="period_number" type="number" min="1" required value="{{ old('period_number', $period->period_number ?? '') }}">
        </div>
        <div class="col-md-8">
            <label class="form-label" for="label">{{ __('bell_schedule.fields.label') }}</label>
            <input class="form-control" id="label" name="label" maxlength="255" value="{{ old('label', $period->label ?? '') }}">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="starts_at">{{ __('bell_schedule.fields.starts_at') }}</label>
            <input class="form-control" id="starts_at" name="starts_at" type="time" required value="{{ old('starts_at', isset($period) ? \Illuminate\Support\Carbon::parse($period->starts_at)->format('H:i') : '') }}">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="ends_at">{{ __('bell_schedule.fields.ends_at') }}</label>
            <input class="form-control" id="ends_at" name="ends_at" type="time" required value="{{ old('ends_at', isset($period) ? \Illuminate\Support\Carbon::parse($period->ends_at)->format('H:i') : '') }}">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="break_after_minutes">{{ __('bell_schedule.fields.break_after_minutes') }}</label>
            <input class="form-control" id="break_after_minutes" name="break_after_minutes" type="number" min="0" required value="{{ old('break_after_minutes', $period->break_after_minutes ?? 0) }}">
        </div>
        <div class="col-md-4 d-flex align-items-end">
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked(old('is_active', $period->is_active ?? true))>
                <label class="form-check-label" for="is_active">{{ __('bell_schedule.fields.is_active') }}</label>
            </div>
        </div>
    </div>
    <div class="mt-4 d-flex gap-2">
        <button class="btn btn-primary" type="submit">{{ __('bell_schedule.periods.save') }}</button>
        <a href="{{ route('dashboard.bell-schedules.edit', $bellSchedule) }}" class="btn btn-secondary">{{ __('bell_schedule.periods.cancel') }}</a>
    </div>
</div></div>
