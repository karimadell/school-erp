@extends('layouts.dashboard')

@section('content')

<h1>{{ __('reports.trial_balance') }}</h1>

<table class="table">

    <thead>
        <tr>
            <th>{{ __('reports.code') }}</th>
            <th>{{ __('reports.account') }}</th>
            <th>{{ __('reports.debit') }}</th>
            <th>{{ __('reports.credit') }}</th>
            <th>{{ __('reports.balance') }}</th>
        </tr>
    </thead>

    <tbody>

        @foreach($report as $row)

        <tr>
            <td>{{ $row['code'] }}</td>
            <td>{{ $row['name'] }}</td>
            <td>{{ number_format($row['debit'],2) }}</td>
            <td>{{ number_format($row['credit'],2) }}</td>
            <td>{{ number_format($row['balance'],2) }}</td>
        </tr>

        @endforeach

    </tbody>

</table>

@endsection