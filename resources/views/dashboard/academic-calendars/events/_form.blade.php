@if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<div class="card shadow-sm border-0"><div class="card-body">
    <div class="alert alert-light border">
        <strong>{{ __('academic_calendar.fields.academic_year') }}:</strong> {{ $academicCalendar->academicYear?->name }}
    </div>
    <div class="row g-3">
        <div class="col-md-8">
            <label class="form-label" for="name">{{ __('academic_calendar.fields.name') }}</label>
            <input class="form-control" id="name" name="name" required maxlength="255" value="{{ old('name', $event->name ?? '') }}">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="type">{{ __('academic_calendar.fields.type') }}</label>
            <select class="form-select" id="type" name="type" required>
                @foreach(\App\Models\CalendarEvent::TYPES as $type)
                    <option value="{{ $type }}" @selected(old('type', $event->type ?? '') === $type)>
                        {{ __("academic_calendar.types.$type") }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="start_date">{{ __('academic_calendar.fields.start_date') }}</label>
            <input class="form-control" id="start_date" name="start_date" type="date" required value="{{ old('start_date', isset($event) ? $event->start_date?->format('Y-m-d') : '') }}">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="end_date">{{ __('academic_calendar.fields.end_date') }}</label>
            <input class="form-control" id="end_date" name="end_date" type="date" required value="{{ old('end_date', isset($event) ? $event->end_date?->format('Y-m-d') : '') }}">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="effect">{{ __('academic_calendar.fields.effect') }}</label>
            <select class="form-select" id="effect" name="effect">
                <option value="">—</option>
                @foreach(\App\Models\CalendarEvent::EFFECTS as $effect)
                    <option value="{{ $effect }}" @selected(old('effect', $event->effect ?? '') === $effect)>
                        {{ __("academic_calendar.effects.$effect") }}
                    </option>
                @endforeach
            </select>
            <div class="form-text">{{ __('academic_calendar.types.teaching_override') }} / {{ __('academic_calendar.types.school_event') }}</div>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="bell_schedule_id">{{ __('academic_calendar.fields.bell_schedule_id') }}</label>
            <select class="form-select" id="bell_schedule_id" name="bell_schedule_id">
                <option value="">—</option>
                @foreach($bellSchedules as $schedule)
                    <option value="{{ $schedule->id }}" @selected(old('bell_schedule_id', $event->bell_schedule_id ?? null) == $schedule->id)>
                        {{ $schedule->name }} ({{ __('bell_schedule.fields.shift') }} {{ $schedule->shift }})
                    </option>
                @endforeach
            </select>
            <div class="form-text">{{ __('academic_calendar.types.bell_schedule_override') }}</div>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="shift">{{ __('academic_calendar.fields.shift') }}</label>
            <input class="form-control" id="shift" name="shift" type="number" min="1" value="{{ old('shift', $event->shift ?? '') }}">
        </div>
        <div class="col-md-12">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked(old('is_active', $event->is_active ?? true))>
                <label class="form-check-label" for="is_active">{{ __('academic_calendar.fields.is_active') }}</label>
            </div>
        </div>
        <div class="col-12">
            <label class="form-label" for="notes">{{ __('academic_calendar.fields.notes') }}</label>
            <textarea class="form-control" id="notes" name="notes" rows="2">{{ old('notes', $event->notes ?? '') }}</textarea>
        </div>
    </div>
    <div class="mt-4 d-flex gap-2">
        <button class="btn btn-primary" type="submit">{{ __('academic_calendar.events.save') }}</button>
        <a href="{{ route('dashboard.academic-calendars.edit', $academicCalendar) }}" class="btn btn-secondary">{{ __('academic_calendar.events.cancel') }}</a>
    </div>
</div></div>
