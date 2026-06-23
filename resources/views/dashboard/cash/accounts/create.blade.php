@extends('dashboard.layouts.app')

@section('content')

<div class="container">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Add Cash Account</h2>

        <a href="{{ route('cash.accounts.index') }}" class="btn btn-secondary">
            Back
        </a>
    </div>

    <div class="card">
        <div class="card-body">

            <form method="POST" action="{{ route('cash.accounts.store') }}">

                @csrf

                <div class="row">

                    {{-- Account Name --}}
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Account Name</label>

                        <input type="text"
                               name="name"
                               class="form-control"
                               required>
                    </div>


                    {{-- Account Type --}}
                    <div class="col-md-6 mb-3">

                        <label class="form-label">Account Type</label>

                        <select name="type"
                                class="form-control"
                                id="accountType"
                                required>

                            <option value="main">Main Account</option>
                            <option value="sub">Sub Account</option>

                        </select>

                    </div>


                    {{-- Parent Account --}}
                    <div class="col-md-6 mb-3" id="parentAccountBox">

                        <label class="form-label">Parent Account</label>

                        <select name="parent_id" class="form-control">

                            <option value="">Select Parent</option>

                            @foreach($mainAccounts as $account)

                                <option value="{{ $account->id }}">
                                    {{ $account->name }}
                                </option>

                            @endforeach

                        </select>

                    </div>


                    {{-- Balance --}}
                    <div class="col-md-6 mb-3">

                        <label class="form-label">Opening Balance</label>

                        <input type="number"
                               name="balance"
                               class="form-control"
                               value="0">

                    </div>

                </div>


                <button type="submit" class="btn btn-success">
                    Save Account
                </button>

            </form>

        </div>
    </div>

</div>

@endsection



@section('scripts')

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

@endsection