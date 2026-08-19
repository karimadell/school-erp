@if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<div class="card shadow-sm border-0"><div class="card-body">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="academic_year_id">{{ __('classroom.fields.academic_year') }}</label>
            <select class="form-select" id="academic_year_id" name="academic_year_id" required>
                @foreach($academicYears as $year)
                    <option value="{{ $year->id }}" @selected(old('academic_year_id', $classroom->academic_year_id ?? null) == $year->id)>
                        {{ $year->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="room_type">{{ __('classroom.fields.room_type') }}</label>
            <select class="form-select" id="room_type" name="room_type" required>
                @foreach(\App\Models\PhysicalClassroom::TYPES as $type)
                    <option value="{{ $type }}" @selected(old('room_type', $classroom->room_type ?? '') === $type)>
                        {{ __("classroom.types.$type") }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="code">{{ __('classroom.fields.code') }}</label>
            <input class="form-control" id="code" name="code" required maxlength="255" value="{{ old('code', $classroom->code ?? '') }}">
        </div>
        <div class="col-md-8">
            <label class="form-label" for="name">{{ __('classroom.fields.name') }}</label>
            <input class="form-control" id="name" name="name" required maxlength="255" value="{{ old('name', $classroom->name ?? '') }}">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="building">{{ __('classroom.fields.building') }}</label>
            <input class="form-control" id="building" name="building" maxlength="255" value="{{ old('building', $classroom->building ?? '') }}">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="floor">{{ __('classroom.fields.floor') }}</label>
            <input class="form-control" id="floor" name="floor" maxlength="255" value="{{ old('floor', $classroom->floor ?? '') }}">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="capacity">{{ __('classroom.fields.capacity') }}</label>
            <input class="form-control" id="capacity" name="capacity" type="number" min="1" required value="{{ old('capacity', $classroom->capacity ?? '') }}">
        </div>
        <div class="col-md-12">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked(old('is_active', $classroom->is_active ?? true))>
                <label class="form-check-label" for="is_active">{{ __('classroom.fields.is_active') }}</label>
            </div>
        </div>
        <div class="col-12">
            <label class="form-label" for="notes">{{ __('classroom.fields.notes') }}</label>
            <textarea class="form-control" id="notes" name="notes" rows="2">{{ old('notes', $classroom->notes ?? '') }}</textarea>
        </div>
    </div>
    <div class="mt-4 d-flex gap-2">
        <button class="btn btn-primary" type="submit">{{ __('classroom.save') }}</button>
        <a href="{{ route('dashboard.classrooms.index') }}" class="btn btn-secondary">{{ __('classroom.cancel') }}</a>
    </div>
</div></div>
