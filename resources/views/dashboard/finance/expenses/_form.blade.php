@php
    /** @var \App\Models\Expense|null $expense */
    $expense = $expense ?? null;
@endphp

<div class="row g-4">
    <div class="col-md-8">
        <label class="form-label fw-semibold">{{ __('expenses.title') }} <span class="text-danger">*</span></label>
        <input type="text" name="title" value="{{ old('title', $expense?->title) }}" class="form-control @error('title') is-invalid @enderror" maxlength="255" required>
        @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    @if (! $expense)
        <div class="col-md-4">
            <label class="form-label fw-semibold">{{ __('expenses.status') }} <span class="text-danger">*</span></label>
            <select name="status" class="form-select @error('status') is-invalid @enderror" required>
                <option value="{{ \App\Models\Expense::STATUS_DRAFT }}" @selected(old('status') === \App\Models\Expense::STATUS_DRAFT)>{{ __('expenses.status_draft') }}</option>
                <option value="{{ \App\Models\Expense::STATUS_PAID }}" @selected(old('status', \App\Models\Expense::STATUS_PAID) === \App\Models\Expense::STATUS_PAID)>{{ __('expenses.status_paid') }}</option>
            </select>
            <div class="form-text">{{ __('expenses.status_help') }}</div>
            @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    @endif

    <div class="col-md-4">
        <label class="form-label fw-semibold">{{ __('expenses.category') }}</label>
        <select name="expense_category_id" class="form-select @error('expense_category_id') is-invalid @enderror">
            <option value="">—</option>
            @foreach($categories as $category)
                <option value="{{ $category->id }}" @selected((int) old('expense_category_id', $expense?->expense_category_id) === $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
        @error('expense_category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold">{{ __('expenses.payee') }}</label>
        <select name="payee_id" class="form-select @error('payee_id') is-invalid @enderror">
            <option value="">—</option>
            @foreach($payees as $payee)
                <option value="{{ $payee->id }}" @selected((int) old('payee_id', $expense?->payee_id) === $payee->id)>{{ $payee->name }}</option>
            @endforeach
        </select>
        @error('payee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold">{{ __('expenses.amount') }} <span class="text-danger">*</span></label>
        <input type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount', $expense?->amount) }}" class="form-control @error('amount') is-invalid @enderror" required>
        @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-2">
        <label class="form-label fw-semibold">{{ __('expenses.currency') }} <span class="text-danger">*</span></label>
        <input type="text" name="currency" value="{{ old('currency', $expense?->currency ?? 'EGP') }}" maxlength="3" class="form-control @error('currency') is-invalid @enderror" required>
        @error('currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-3">
        <label class="form-label fw-semibold">{{ __('expenses.expense_date') }} <span class="text-danger">*</span></label>
        <input type="date" name="expense_date" value="{{ old('expense_date', optional($expense?->expense_date)->format('Y-m-d') ?? now()->format('Y-m-d')) }}" class="form-control @error('expense_date') is-invalid @enderror" required>
        @error('expense_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-3">
        <label class="form-label fw-semibold">{{ __('expenses.cash_account') }} <span class="text-danger">*</span></label>
        <select name="cash_account_id" class="form-select @error('cash_account_id') is-invalid @enderror" required>
            <option value="">—</option>
            @foreach($cashAccounts as $account)
                <option value="{{ $account->id }}" @selected((int) old('cash_account_id', $expense?->cash_account_id) === $account->id)>{{ $account->name }}</option>
            @endforeach
        </select>
        @error('cash_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold">{{ __('expenses.payment_method') }}</label>
        <select name="payment_method" class="form-select @error('payment_method') is-invalid @enderror">
            <option value="">—</option>
            @foreach($methodLabels as $value => $label)
                <option value="{{ $value }}" @selected(old('payment_method', $expense?->payment_method) === $value)>{{ $label }}</option>
            @endforeach
        </select>
        @error('payment_method')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        <label class="form-label fw-semibold">{{ __('expenses.external_reference') }}</label>
        <input type="text" name="external_reference" value="{{ old('external_reference', $expense?->external_reference) }}" maxlength="255" class="form-control @error('external_reference') is-invalid @enderror">
        @error('external_reference')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <label class="form-label fw-semibold">{{ __('expenses.description') }}</label>
        <textarea name="description" rows="2" class="form-control @error('description') is-invalid @enderror">{{ old('description', $expense?->description) }}</textarea>
        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <label class="form-label fw-semibold">{{ __('expenses.notes') }}</label>
        <textarea name="notes" rows="2" class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $expense?->notes) }}</textarea>
        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <label class="form-label fw-semibold">{{ __('expenses.attachment') }}</label>
        <input type="file" name="attachment" class="form-control @error('attachment') is-invalid @enderror">
        @error('attachment')<div class="invalid-feedback">{{ $message }}</div>@enderror
        @if($expense?->attachment_path)
            <div class="form-text">
                {{ __('expenses.attachment') }}: {{ $expense->attachment_name }}
                — <a href="{{ route('dashboard.finance.expenses.attachment', $expense) }}">{{ __('expenses.attachment_download') }}</a>
            </div>
        @endif
    </div>
</div>
