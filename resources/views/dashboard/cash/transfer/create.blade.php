@extends('layouts.dashboard')

@section('title', __('app.cash_transfer'))

@section('content')

<div class="container-fluid">

    <div class="row mb-3">
        <div class="col-md-12">
            <h2>{{ __('app.cash_transfer') }}</h2>
        </div>
    </div>

    <div class="card">
        <div class="card-body">

            <form method="POST" action="{{ route('dashboard.cash.transfer.store') }}">
                @csrf

                <div class="row">

                    {{-- From Account --}}
                    <div class="col-md-6 mb-3">
                        <label class="form-label">
                            {{ __('app.from_account') }}
                        </label>

                        <select name="from_account_id" class="form-control" required>
                            <option value="">
                                {{ __('app.select_account') }}
                            </option>

                            @foreach($accounts as $account)
                                <option value="{{ $account->id }}">
                                    {{ $account->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- To Account --}}
                    <div class="col-md-6 mb-3">
                        <label class="form-label">
                            {{ __('app.to_account') }}
                        </label>

                        <select name="to_account_id" class="form-control" required>
                            <option value="">
                                {{ __('app.select_account') }}
                            </option>

                            @foreach($accounts as $account)
                                <option value="{{ $account->id }}">
                                    {{ $account->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Amount --}}
                    <div class="col-md-6 mb-3">
                        <label class="form-label">
                            {{ __('app.amount') }}
                        </label>

                        <input
                            type="number"
                            step="0.01"
                            name="amount"
                            class="form-control"
                            required
                        >
                    </div>

                    {{-- Transfer Date --}}
                    <div class="col-md-6 mb-3">
                        <label class="form-label">
                            {{ __('app.date') }}
                        </label>

                        <input
                            type="date"
                            name="transfer_date"
                            class="form-control"
                            value="{{ date('Y-m-d') }}"
                            required
                        >
                    </div>

                    {{-- Notes --}}
                    <div class="col-md-12 mb-3">
                        <label class="form-label">
                            {{ __('app.notes') }}
                        </label>

                        <textarea
                            name="notes"
                            class="form-control"
                            rows="3"
                        ></textarea>
                    </div>

                </div>

                <div class="mt-3">
                    <button type="submit" class="btn btn-primary">
                        {{ __('app.save') }}
                    </button>

                    <a href="{{ route('dashboard.cash.accounts') }}" class="btn btn-secondary">
                        {{ __('app.cancel') }}
                    </a>
                </div>

            </form>

        </div>
    </div>

</div>

@endsection