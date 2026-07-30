@extends('layouts.dashboard')

@section('title', __('cash.transfers'))

@section('content')

<div class="container-fluid">

    <div class="row mb-3">
        <div class="col-md-12">
            <h2>{{ __('cash.transfers') }}</h2>
        </div>
    </div>

    <div class="card">
        <div class="card-body">

            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('cash.receipt_number') }}</th>
                        <th>{{ __('cash.from_account') }}</th>
                        <th>{{ __('cash.to_account') }}</th>
                        <th>{{ __('cash.amount') }}</th>
                        <th>{{ __('cash.transfer_date') }}</th>
                        <th>{{ __('cash.notes') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($transfers as $transfer)
                        <tr>
                            <td>{{ $transfer->receipt_number }}</td>
                            <td>{{ $transfer->fromAccount->name ?? '' }}</td>
                            <td>{{ $transfer->toAccount->name ?? '' }}</td>
                            <td>{{ $transfer->amount }}</td>
                            <td>{{ $transfer->transfer_date }}</td>
                            <td>{{ $transfer->notes }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">{{ __('app.no_records_found') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            {{ $transfers->links() }}

        </div>
    </div>

</div>

@endsection
