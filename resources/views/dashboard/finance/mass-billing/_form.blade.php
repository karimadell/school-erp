@php
    $batch = $batch ?? null;
    $selectedClassIds = collect(old('class_ids', $selectedClassIds ?? []))->map(fn ($id) => (int) $id);
    $includeStudentIds = collect(old('include_student_ids', $includeStudentIds ?? []))->map(fn ($id) => (int) $id);
    $excludeStudentIds = collect(old('exclude_student_ids', $excludeStudentIds ?? []))->map(fn ($id) => (int) $id);
    $targetMode = old('target_mode', $batch?->target_mode ?? \App\Models\BillingBatch::TARGET_MODE_CLASSES);

    $req = '<span class="text-danger" aria-hidden="true">*</span>';

    $classOptions = $classes->map(fn ($class) => [
        'id' => $class->id,
        'label' => $class->name_ru ?? $class->code,
    ])->all();

    $studentOptions = $students->map(fn ($student) => [
        'id' => $student->id,
        'label' => __('mass_billing.student_label', [
            'name' => $student->full_name,
            'class' => $student->class?->name_ru ?? __('mass_billing.student_no_class'),
        ]),
    ])->all();
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

<p class="text-muted small mb-3">{{ __('mass_billing.required_legend') }}</p>

<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label" for="academic_year_id">{{ __('mass_billing.fields.academic_year') }} {!! $req !!}</label>
        <select name="academic_year_id" id="academic_year_id" class="form-select @error('academic_year_id') is-invalid @enderror" required>
            <option value="">—</option>
            @foreach($years as $year)
                <option value="{{ $year->id }}" @selected((int) old('academic_year_id', $batch?->academic_year_id) === $year->id)>{{ $year->name }}</option>
            @endforeach
        </select>
        @error('academic_year_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-6">
        <label class="form-label" for="fee_id">{{ __('mass_billing.fields.service') }} {!! $req !!}</label>
        <select name="fee_id" id="fee_id" class="form-select @error('fee_id') is-invalid @enderror" required>
            <option value="">—</option>
            @foreach($fees as $fee)
                <option value="{{ $fee->id }}" @selected((int) old('fee_id', $batch?->fee_id) === $fee->id)>{{ $fee->name_ru }}</option>
            @endforeach
        </select>
        @error('fee_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label" for="quantity">{{ __('mass_billing.fields.quantity') }} {!! $req !!}</label>
        <input type="number" name="quantity" id="quantity" min="1" step="1" class="form-control @error('quantity') is-invalid @enderror" value="{{ old('quantity', $batch?->quantity ?? 1) }}" required>
        @error('quantity')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label" for="issue_date">{{ __('mass_billing.fields.issue_date') }} {!! $req !!}</label>
        <input type="date" name="issue_date" id="issue_date" class="form-control @error('issue_date') is-invalid @enderror" value="{{ old('issue_date', $batch?->issue_date?->toDateString()) }}" required>
        @error('issue_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label" for="due_date">{{ __('mass_billing.fields.due_date') }} {!! $req !!}</label>
        <input type="date" name="due_date" id="due_date" class="form-control @error('due_date') is-invalid @enderror" value="{{ old('due_date', $batch?->due_date?->toDateString()) }}" required>
        @error('due_date')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <label class="form-label" for="description">{{ __('mass_billing.fields.description') }}</label>
        <input type="text" name="description" id="description" maxlength="1000" class="form-control @error('description') is-invalid @enderror" value="{{ old('description', $batch?->description) }}">
        @error('description')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <span class="form-label d-block" id="target_mode_label">{{ __('mass_billing.fields.target_mode') }} {!! $req !!}</span>
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
        <label class="form-label" for="class_ids">{{ __('mass_billing.fields.classes') }} {!! $req !!}</label>
        @include('dashboard.finance.mass-billing._searchable-checklist', [
            'name' => 'class_ids[]',
            'inputId' => 'class_ids',
            'options' => $classOptions,
            'selected' => $selectedClassIds,
            'searchPlaceholder' => __('mass_billing.search.classes_placeholder'),
            'emptyText' => __('mass_billing.search.no_classes'),
            'groupLabel' => __('mass_billing.fields.classes'),
            'countLabel' => __('mass_billing.search.selected_count'),
            'invalid' => $errors->has('class_ids') || $errors->has('class_ids.*'),
        ])
        @error('class_ids')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        @error('class_ids.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-6">
        <span class="form-label d-block">{{ __('mass_billing.fields.individual_students') }}</span>
        <div class="row g-3">
            <div class="col-sm-6">
                <label class="form-label small text-muted" for="include_student_ids">{{ __('mass_billing.fields.include_students') }}</label>
                @include('dashboard.finance.mass-billing._searchable-checklist', [
                    'name' => 'include_student_ids[]',
                    'inputId' => 'include_student_ids',
                    'options' => $studentOptions,
                    'selected' => $includeStudentIds,
                    'searchPlaceholder' => __('mass_billing.search.students_placeholder'),
                    'emptyText' => __('mass_billing.search.no_students'),
                    'groupLabel' => __('mass_billing.fields.include_students'),
                    'countLabel' => __('mass_billing.search.selected_count'),
                    'invalid' => $errors->has('include_student_ids') || $errors->has('include_student_ids.*'),
                ])
            </div>
            <div class="col-sm-6">
                <label class="form-label small text-muted" for="exclude_student_ids">{{ __('mass_billing.fields.exclude_students') }}</label>
                @include('dashboard.finance.mass-billing._searchable-checklist', [
                    'name' => 'exclude_student_ids[]',
                    'inputId' => 'exclude_student_ids',
                    'options' => $studentOptions,
                    'selected' => $excludeStudentIds,
                    'searchPlaceholder' => __('mass_billing.search.students_placeholder'),
                    'emptyText' => __('mass_billing.search.no_students'),
                    'groupLabel' => __('mass_billing.fields.exclude_students'),
                    'countLabel' => __('mass_billing.search.selected_count'),
                    'invalid' => $errors->has('exclude_student_ids') || $errors->has('exclude_student_ids.*'),
                ])
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    (function () {
        document.querySelectorAll('[data-mb-checklist]').forEach(function (box) {
            var search = box.querySelector('[data-mb-search]');
            var items = box.querySelectorAll('[data-mb-item]');
            var countEl = box.querySelector('[data-mb-count]');
            var noResults = box.querySelector('[data-mb-noresults]');
            var template = countEl ? countEl.getAttribute('data-mb-template') : '';

            function updateCount() {
                if (!countEl) {
                    return;
                }
                var n = box.querySelectorAll('input[type="checkbox"]:checked').length;
                countEl.textContent = template.replace(':count', n);
            }

            function filter() {
                var q = (search.value || '').trim().toLowerCase();
                var visible = 0;
                items.forEach(function (item) {
                    var match = item.getAttribute('data-label').indexOf(q) !== -1;
                    item.classList.toggle('d-none', !match);
                    if (match) {
                        visible++;
                    }
                });
                if (noResults) {
                    noResults.classList.toggle('d-none', visible !== 0 || items.length === 0);
                }
            }

            if (search) {
                search.addEventListener('input', filter);
            }
            box.addEventListener('change', updateCount);
            updateCount();
        });
    })();
</script>
@endpush
