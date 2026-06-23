@extends('dashboard.layouts.app')

@section('content')

<div class="container">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Cash Accounts</h2>

        <a href="{{ route('cash.accounts.create') }}" class="btn btn-primary">
            Add New Account
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif


    <div class="card">
        <div class="card-body">

            <table class="table table-bordered table-striped">

                <thead>
                    <tr>
                        <th>#</th>
                        <th>Account Name</th>
                        <th>Type</th>
                        <th>Parent Account</th>
                        <th>Balance</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                    @foreach($accounts as $account)

                        <tr>

                            <td>{{ $loop->iteration }}</td>

                            <td>{{ $account->name }}</td>

                            <td>
                                @if($account->type == 'main')
                                    <span class="badge bg-primary">Main</span>
                                @else
                                    <span class="badge bg-secondary">Sub</span>
                                @endif
                            </td>

                            <td>
                                {{ $account->parent->name ?? '-' }}
                            </td>

                            <td>
                                {{ number_format($account->balance ,2) }}
                            </td>

                            <td>

                                <a href="{{ route('cash.accounts.edit',$account->id) }}" 
                                   class="btn btn-sm btn-warning">
                                    Edit
                                </a>

                                <form action="{{ route('cash.accounts.destroy',$account->id) }}" 
                                      method="POST" 
                                      style="display:inline-block">

                                    @csrf
                                    @method('DELETE')

                                    <button class="btn btn-sm btn-danger"
                                            onclick="return confirm('Delete account?')">

                                        Delete

                                    </button>

                                </form>

                            </td>

                        </tr>

                    @endforeach

                </tbody>

            </table>

        </div>
    </div>

</div>

@endsection