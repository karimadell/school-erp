@extends('layouts.dashboard')

@section('content')

<div class="container">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>{{ __('cash_accounts.index_title') }}</h2>

        <a href="{{ route('dashboard.cash.accounts.create') }}" class="btn btn-primary">
            {{ __('cash_accounts.add_new') }}
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
                        <th>{{ __('cash_accounts.name') }}</th>
                        <th>{{ __('cash_accounts.column_type') }}</th>
                        <th>{{ __('cash_accounts.parent_account') }}</th>
                        <th>{{ __('cash_accounts.column_balance') }}</th>
                        <th>{{ __('app.actions') }}</th>
                    </tr>
                </thead>

                <tbody>

                    @foreach($accounts as $account)

                        <tr>

                            <td>{{ $loop->iteration }}</td>

                            <td>{{ $account->name }}</td>

                            <td>
                                @if($account->type == 'main')
                                    <span class="badge bg-primary">{{ __('cash_accounts.badge_main') }}</span>
                                @else
                                    <span class="badge bg-secondary">{{ __('cash_accounts.badge_sub') }}</span>
                                @endif
                            </td>

                            <td>
                                {{ $account->parent->name ?? '-' }}
                            </td>

                            <td>
                                {{ number_format($account->balance ,2) }}
                            </td>

                            <td>

                                <a href="{{ route('dashboard.cash.accounts.edit',$account->id) }}"
                                   class="btn btn-sm btn-warning">
                                    {{ __('app.edit') }}
                                </a>

                                <form action="{{ route('dashboard.cash.accounts.destroy',$account->id) }}"
                                      method="POST" 
                                      style="display:inline-block">

                                    @csrf
                                    @method('DELETE')

                                    <button class="btn btn-sm btn-danger"
                                            onclick="return confirm('{{ __('cash_accounts.confirm_delete') }}')">

                                        {{ __('app.delete') }}

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