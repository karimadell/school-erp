@php
    /** @var \App\Models\RevenueCategory|null $lockedCategory */
    $lockedCategory = $lockedCategory ?? null;
@endphp

<div class="row g-4">
    <div class="col-md-4">
        <label class="form-label fw-semibold">{{ __('revenues.category') }} <span class="text-danger">*</span></label>
        @if($lockedCategory)
            <input type="hidden" name="revenue_category_id" value="{{ $lockedCategory->id }}">
            <input type="text" class="form-control" value="{{ $lockedCategory->name_ru }}" disabled>
        @else
            <select name="revenue_category_id" class="form-select @error('revenue_category_id') is-invalid @enderror" required>
                <option value="">—</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}" @selected((int) old('revenue_category_id') === $category->id)>{{ $category->name_ru }}</option>
                @endforeach
            </select>
        @endif
        @error('revenue_category_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold">{{ __('revenues.amount') }} <span class="text-danger">*</span></label>
        <input type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount') }}" class="form-control @error('amount') is-invalid @enderror" required>
        @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold">{{ __('revenues.revenue_date') }} <span class="text-danger">*</span></label>
        <input type="date" name="revenue_date" value="{{ old('revenue_date', now()->format('Y-m-d')) }}" class="form-control @error('revenue_date') is-invalid @enderror" required>
        @error('revenue_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold">{{ __('revenues.cash_account') }} <span class="text-danger">*</span></label>
        <select name="cash_account_id" class="form-select @error('cash_account_id') is-invalid @enderror" required>
            <option value="">—</option>
            @foreach($cashAccounts as $account)
                <option value="{{ $account->id }}" @selected((int) old('cash_account_id') === $account->id)>{{ $account->name }}</option>
            @endforeach
        </select>
        @error('cash_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold">{{ __('revenues.payment_method') }} <span class="text-danger">*</span></label>
        <select name="payment_method" class="form-select @error('payment_method') is-invalid @enderror" required>
            <option value="">—</option>
            @foreach($methodLabels as $value => $label)
                <option value="{{ $value }}" @selected(old('payment_method') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        @error('payment_method')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold">{{ __('revenues.payer_name') }}</label>
        <input type="text" name="payer_name" value="{{ old('payer_name') }}" maxlength="255" class="form-control @error('payer_name') is-invalid @enderror">
        @error('payer_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold">{{ __('revenues.status') }} <span class="text-danger">*</span></label>
        <select name="status" class="form-select @error('status') is-invalid @enderror" required>
            <option value="{{ \App\Models\RevenueEntry::STATUS_DRAFT }}" @selected(old('status') === \App\Models\RevenueEntry::STATUS_DRAFT)>{{ __('revenues.status_draft') }}</option>
            <option value="{{ \App\Models\RevenueEntry::STATUS_POSTED }}" @selected(old('status', \App\Models\RevenueEntry::STATUS_POSTED) === \App\Models\RevenueEntry::STATUS_POSTED)>{{ __('revenues.status_posted') }}</option>
        </select>
        <div class="form-text">{{ __('revenues.status_help') }}</div>
        @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <label class="form-label fw-semibold">{{ __('revenues.description') }}</label>
        <textarea name="description" rows="2" class="form-control @error('description') is-invalid @enderror">{{ old('description') }}</textarea>
        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <label class="form-label fw-semibold">{{ __('revenues.notes') }}</label>
        <textarea name="notes" rows="2" class="form-control @error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <label class="form-label fw-semibold">{{ __('revenues.attachment') }}</label>
        <input type="file" name="attachment" class="form-control @error('attachment') is-invalid @enderror">
        @error('attachment')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>
