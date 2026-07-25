@extends('layouts.dashboard')

@section('content')

<div class="container">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>{{ __('cash_accounts.edit_title') }}</h2>

        <a href="{{ route('dashboard.cash.accounts') }}" class="btn btn-secondary">
            {{ __('cash_accounts.cancel') }}
        </a>
    </div>

    <div class="card">
        <div class="card-body">

            <form method="POST" action="{{ route('dashboard.cash.accounts.update', $account->id) }}">

                @csrf
                @method('PUT')

                @include('dashboard.cash.accounts._form', ['account' => $account, 'mainAccounts' => $mainAccounts])

                <button type="submit" class="btn btn-success">
                    {{ __('cash_accounts.save_changes') }}
                </button>

            </form>

        </div>
    </div>

</div>

@endsection



@push('scripts')

<script>

    let typeSelect = document.getElementById("accountType")
    let parentBox = document.getElementById("parentAccountBox")

    function toggleParent(){

        if(typeSelect.value == "main"){
            parentBox.style.display = "none"
        }else{
            parentBox.style.display = "block"
        }

    }

    toggleParent()

    typeSelect.addEventListener("change", toggleParent)

</script>

@endpush
