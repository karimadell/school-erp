@php
    $batch = $batch ?? null;
    $selectedClassIds = collect(old('class_ids', $selectedClassIds ?? []))->map(fn ($id) => (int) $id);
    $includeStudentIds = collect(old('include_student_ids', $includeStudentIds ?? []))->map(fn ($id) => (int) $id);
    $excludeStudentIds = collect(old('exclude_student_ids', $excludeStudentIds ?? []))->map(fn ($id) => (int) $id);
    $targetMode = old('target_mode', $batch?->target_mode ?? \App\Models\BillingBatch::TARGET_MODE_CLASSES);
@endphp

@if($errors->any())
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0 ps-3">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label" for="academic_year_id">{{ __('mass_billing.fields.academic_year') }}</label>
        <select name="academic_year_id" id="academic_year_id" class="form-select @error('academic_year_id') is-invalid @enderror" required>
            <option value="">—</option>
            @foreach($years as $year)
                <option value="{{ $year->id }}" @selected((int) old('academic_year_id', $batch?->academic_year_id) === $year->id)>{{ $year->name }}</option>
            @endforeach
        </select>
        @error('academic_year_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-6">
        <label class="form-label" for="fee_id">{{ __('mass_billing.fields.service') }}</label>
        <select name="fee_id" id="fee_id" class="form-select @error('fee_id') is-invalid @enderror" required>
            <option value="">—</option>
            @foreach($fees as $fee)
                <option value="{{ $fee->id }}" @selected((int) old('fee_id', $batch?->fee_id) === $fee->id)>{{ $fee->name_ru }}</option>
            @endforeach
        </select>
        @error('fee_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label" for="quantity">{{ __('mass_billing.fields.quantity') }}</label>
        <input type="number" name="quantity" id="quantity" min="1" step="1" class="form-control @error('quantity') is-invalid @enderror" value="{{ old('quantity', $batch?->quantity ?? 1) }}" required>
        @error('quantity')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label" for="issue_date">{{ __('mass_billing.fields.issue_date') }}</label>
        <input type="date" name="issue_date" id="issue_date" class="form-control @error('issue_date') is-invalid @enderror" value="{{ old('issue_date', $batch?->issue_date?->toDateString()) }}" required>
        @error('issue_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label" for="due_date">{{ __('mass_billing.fields.due_date') }}</label>
        <input type="date" name="due_date" id="due_date" class="form-control @error('due_date') is-invalid @enderror" value="{{ old('due_date', $batch?->due_date?->toDateString()) }}" required>
        @error('due_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <label class="form-label" for="description">{{ __('mass_billing.fields.description') }}</label>
        <input type="text" name="description" id="description" maxlength="1000" class="form-control @error('description') is-invalid @enderror" value="{{ old('description', $batch?->description) }}">
        @error('description')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <span class="form-label d-block" id="target_mode_label">{{ __('mass_billing.fields.target_mode') }}</span>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="target_mode" id="target_mode_classes" value="{{ \App\Models\BillingBatch::TARGET_MODE_CLASSES }}" @checked($targetMode === \App\Models\BillingBatch::TARGET_MODE_CLASSES)>
            <label class="form-check-label" for="target_mode_classes">{{ __('mass_billing.fields.target_mode_classes') }}</label>
        </div>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="target_mode" id="target_mode_all" value="{{ \App\Models\BillingBatch::TARGET_MODE_ALL }}" @checked($targetMode === \App\Models\BillingBatch::TARGET_MODE_ALL)>
            <label class="form-check-label" for="target_mode_all">{{ __('mass_billing.fields.target_mode_all') }}</label>
        </div>
        @error('target_mode')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-6">
        <label class="form-label" for="class_ids">{{ __('mass_billing.fields.classes') }}</label>
        <select name="class_ids[]" id="class_ids" class="form-select @error('class_ids') is-invalid @enderror @error('classes') is-invalid @enderror" multiple size="6">
            @foreach($classes as $class)
                <option value="{{ $class->id }}" @selected($selectedClassIds->contains($class->id))>{{ $class->name_ru ?? $class->code }}</option>
            @endforeach
        </select>
        @error('class_ids')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        @error('class_ids.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-6">
        <span class="form-label d-block">{{ __('mass_billing.fields.individual_students') }}</span>
        <div class="row g-2">
            <div class="col-sm-6">
                <label class="form-label small text-muted" for="include_student_ids">{{ __('mass_billing.fields.include_students') }}</label>
                <select name="include_student_ids[]" id="include_student_ids" class="form-select @error('include_student_ids') is-invalid @enderror" multiple size="6">
                    @foreach($students as $student)
                        <option value="{{ $student->id }}" @selected($includeStudentIds->contains($student->id))>{{ $student->full_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6">
                <label class="form-label small text-muted" for="exclude_student_ids">{{ __('mass_billing.fields.exclude_students') }}</label>
                <select name="exclude_student_ids[]" id="exclude_student_ids" class="form-select @error('exclude_student_ids') is-invalid @enderror" multiple size="6">
                    @foreach($students as $student)
                        <option value="{{ $student->id }}" @selected($excludeStudentIds->contains($student->id))>{{ $student->full_name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>
</div>
