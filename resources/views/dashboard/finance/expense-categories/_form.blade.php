@php
    /** @var \App\Models\ExpenseCategory|null $category */
    $category = $category ?? null;
@endphp

<div class="row g-4">
    <div class="col-md-8">
        <label class="form-label fw-semibold">{{ __('expenses.category_name') }} <span class="text-danger">*</span></label>
        <input type="text" name="name" value="{{ old('name', $category?->name) }}" class="form-control @error('name') is-invalid @enderror" maxlength="255" required>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold">{{ __('expenses.is_active') }}</label>
        <div class="border rounded-3 p-3 bg-light">
            <div class="form-check form-switch">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" class="form-check-input" id="isActive" @checked(old('is_active', $category?->is_active ?? 1) == 1)>
                <label class="form-check-label fw-semibold" for="isActive">{{ __('expenses.is_active') }}</label>
            </div>
        </div>
    </div>
</div>
