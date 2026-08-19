@if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<div class="card shadow-sm border-0"><div class="card-body">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="academic_year_id">{{ __('bell_schedule.fields.academic_year') }}</label>
            <select class="form-select" id="academic_year_id" name="academic_year_id" required>
                @foreach($academicYears as $year)
                    <option value="{{ $year->id }}" @selected(old('academic_year_id', $bellSchedule->academic_year_id ?? null) == $year->id)>
                        {{ $year->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="name">{{ __('bell_schedule.fields.name') }}</label>
            <input class="form-control" id="name" name="name" required maxlength="255" value="{{ old('name', $bellSchedule->name ?? '') }}">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="shift">{{ __('bell_schedule.fields.shift') }}</label>
            <input class="form-control" id="shift" name="shift" type="number" min="1" required value="{{ old('shift', $bellSchedule->shift ?? 1) }}">
        </div>
        <div class="col-md-4 d-flex align-items-center">
            <div class="form-check form-switch mt-4">
                <input class="form-check-input" type="checkbox" role="switch" id="is_default" name="is_default" value="1" @checked(old('is_default', $bellSchedule->is_default ?? false))>
                <label class="form-check-label" for="is_default">{{ __('bell_schedule.fields.is_default') }}</label>
            </div>
        </div>
        <div class="col-md-4 d-flex align-items-center">
            <div class="form-check form-switch mt-4">
                <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked(old('is_active', $bellSchedule->is_active ?? true))>
                <label class="form-check-label" for="is_active">{{ __('bell_schedule.fields.is_active') }}</label>
            </div>
        </div>
        <div class="form-text">{{ __('bell_schedule.help.default') }}</div>
        <div class="col-12">
            <label class="form-label" for="notes">{{ __('bell_schedule.fields.notes') }}</label>
            <textarea class="form-control" id="notes" name="notes" rows="2">{{ old('notes', $bellSchedule->notes ?? '') }}</textarea>
        </div>
    </div>
    <div class="mt-4 d-flex gap-2">
        <button class="btn btn-primary" type="submit">{{ __('bell_schedule.save') }}</button>
        <a href="{{ route('dashboard.bell-schedules.index') }}" class="btn btn-secondary">{{ __('bell_schedule.cancel') }}</a>
    </div>
</div></div>
