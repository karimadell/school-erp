@extends('layouts.dashboard')

@section('content')

<div class="container py-4">

    <h3 class="mb-4">💰 Price List</h3>

    <a href="{{ route('dashboard.fee-prices.create') }}" class="btn btn-primary mb-3">
        + إضافة سعر جديد
    </a>

    <table class="table table-bordered">
        <thead>
            <tr>
                <th>الخدمة</th>
                <th>المرحلة</th>
                <th>الفترة</th>
                <th>السعر</th>
                <th>التاريخ</th>
                <th></th>
            </tr>
        </thead>

        <tbody>
            @foreach($prices as $p)
            <tr>
                <td>{{ $p->fee->name_ru }}</td>
                <td>{{ $p->grade_group }}</td>
                <td>{{ $p->payment_period }}</td>
                <td>{{ $p->amount }}</td>
                <td>{{ $p->start_date }}</td>

                <td>
                    <a href="{{ route('dashboard.fee-prices.edit',$p) }}" class="btn btn-sm btn-warning">Edit</a>
                </td>
            </tr>
            @endforeach
        </tbody>

    </table>

    {{ $prices->links() }}

</div>

@endsection