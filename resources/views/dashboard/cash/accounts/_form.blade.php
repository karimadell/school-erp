@php
    $isEdit = isset($account) && $account;
@endphp

<div class="row">

    {{-- Account Name --}}
    <div class="col-md-6 mb-3">
        <label class="form-label">{{ __('cash_accounts.name') }}</label>

        <input type="text"
               name="name"
               class="form-control @error('name') is-invalid @enderror"
               value="{{ old('name', $account->name ?? '') }}"
               required>

        @error('name')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>


    {{-- Account Type --}}
    <div class="col-md-6 mb-3">

        <label class="form-label">{{ __('cash_accounts.type') }}</label>

        <select name="type"
                class="form-control @error('type') is-invalid @enderror"
                id="accountType"
                required>

            <option value="cash" {{ old('type', $account->type ?? '') == 'cash' ? 'selected' : '' }}>
                {{ __('cash_accounts.type_cash') }}
            </option>
            <option value="bank" {{ old('type', $account->type ?? '') == 'bank' ? 'selected' : '' }}>
                {{ __('cash_accounts.type_bank') }}
            </option>
            <option value="owner_cash" {{ old('type', $account->type ?? '') == 'owner_cash' ? 'selected' : '' }}>
                {{ __('cash_accounts.type_owner_cash') }}
            </option>
            <option value="instapay" {{ old('type', $account->type ?? '') == 'instapay' ? 'selected' : '' }}>
                {{ __('cash_accounts.type_instapay') }}
            </option>

        </select>

        @error('type')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror

    </div>


    {{-- Parent Account --}}
    <div class="col-md-6 mb-3" id="parentAccountBox">

        <label class="form-label">{{ __('cash_accounts.parent_account') }}</label>

        <select name="parent_id" class="form-control @error('parent_id') is-invalid @enderror">

            <option value="">{{ __('cash_accounts.select_parent') }}</option>

            @foreach($mainAccounts as $mainAccount)

                <option value="{{ $mainAccount->id }}"
                    {{ (string) old('parent_id', $account->parent_id ?? '') === (string) $mainAccount->id ? 'selected' : '' }}>
                    {{ $mainAccount->name }}
                </option>

            @endforeach

        </select>

        @error('parent_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror

    </div>


    {{-- Balance --}}
    <div class="col-md-6 mb-3">

        <label class="form-label">{{ __('cash_accounts.opening_balance') }}</label>

        @if($isEdit)

            <input type="number"
                   class="form-control"
                   step="0.01"
                   value="{{ number_format((float) $account->balance, 2, '.', '') }}"
                   disabled>

            <div class="form-text">{{ __('cash_accounts.balance_readonly_hint') }}</div>

        @else

            <input type="number"
                   name="balance"
                   class="form-control @error('balance') is-invalid @enderror"
                   value="{{ old('balance', 0) }}">

            @error('balance')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror

        @endif

    </div>

</div>
