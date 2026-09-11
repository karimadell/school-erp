@php
    /** @var \App\Models\Payee|null $payee */
    $payee = $payee ?? null;
@endphp

<div class="row g-4">
    <div class="col-md-6">
        <label class="form-label fw-semibold">{{ __('expenses.payee_name') }} <span class="text-danger">*</span></label>
        <input type="text" name="name" value="{{ old('name', $payee?->name) }}" class="form-control @error('name') is-invalid @enderror" maxlength="255" required>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-3">
        <label class="form-label fw-semibold">{{ __('expenses.payee_phone') }}</label>
        <input type="text" name="phone" value="{{ old('phone', $payee?->phone) }}" class="form-control @error('phone') is-invalid @enderror" maxlength="50">
        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-3">
        <label class="form-label fw-semibold">{{ __('expenses.is_active') }}</label>
        <div class="border rounded-3 p-3 bg-light">
            <div class="form-check form-switch">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" class="form-check-input" id="isActive" @checked(old('is_active', $payee?->is_active ?? 1) == 1)>
                <label class="form-check-label fw-semibold" for="isActive">{{ __('expenses.is_active') }}</label>
            </div>
        </div>
    </div>

    <div class="col-12">
        <label class="form-label fw-semibold">{{ __('expenses.notes') }}</label>
        <textarea name="notes" rows="2" class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $payee?->notes) }}</textarea>
        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>
