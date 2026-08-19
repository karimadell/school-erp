@if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<div class="card shadow-sm border-0"><div class="card-body">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="academic_year_id">{{ __('academic_calendar.fields.academic_year') }}</label>
            <select class="form-select" id="academic_year_id" name="academic_year_id" required>
                @foreach($academicYears as $year)
                    <option value="{{ $year->id }}" @selected(old('academic_year_id', $academicCalendar->academic_year_id ?? null) == $year->id)>
                        {{ $year->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="default_bell_schedule_id">{{ __('academic_calendar.fields.default_bell_schedule_id') }}</label>
            <select class="form-select" id="default_bell_schedule_id" name="default_bell_schedule_id">
                <option value="">—</option>
                @foreach($bellSchedules as $schedule)
                    <option value="{{ $schedule->id }}" @selected(old('default_bell_schedule_id', $academicCalendar->default_bell_schedule_id ?? null) == $schedule->id)>
                        {{ $schedule->name }} ({{ __('bell_schedule.fields.shift') }} {{ $schedule->shift }})
                    </option>
                @endforeach
            </select>
            <div class="form-text">{{ __('academic_calendar.help.default_bell_schedule_id') }}</div>
        </div>
        <div class="col-12">
            <label class="form-label">{{ __('academic_calendar.fields.weekly_days_off') }}</label>
            @php $selectedDays = old('weekly_days_off', $academicCalendar->weekly_days_off ?? ['fri', 'sat']); @endphp
            <div class="d-flex flex-wrap gap-3">
                @foreach(\App\Models\AcademicCalendar::WEEKDAYS as $day)
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="day-{{ $day }}" name="weekly_days_off[]" value="{{ $day }}" @checked(in_array($day, $selectedDays))>
                        <label class="form-check-label" for="day-{{ $day }}">{{ __("academic_calendar.weekdays.$day") }}</label>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    <div class="mt-4 d-flex gap-2">
        <button class="btn btn-primary" type="submit">{{ __('academic_calendar.save') }}</button>
        <a href="{{ route('dashboard.academic-calendars.index') }}" class="btn btn-secondary">{{ __('academic_calendar.cancel') }}</a>
    </div>
</div></div>
