@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

    <h2 class="mb-4">Admin Dashboard</h2>

    <div class="row g-3">

        <div class="col-md-3">
            <div class="card p-3">
                <h6>Users</h6>
                <h3>{{ $usersCount ?? 0 }}</h3>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card p-3">
                <h6>Students</h6>
                <h3>{{ $studentsCount ?? 0 }}</h3>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card p-3">
                <h6>Invoices</h6>
                <h3>{{ $invoicesCount ?? 0 }}</h3>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card p-3">
                <h6>Cash Accounts</h6>
                <h3>{{ $cashAccountsCount ?? 0 }}</h3>
            </div>
        </div>

    </div>

</div>

@endsection