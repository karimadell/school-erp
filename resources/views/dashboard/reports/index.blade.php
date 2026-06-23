@extends('layouts.app')

@section('content')
<div class="container">

    <h3 class="mb-4">تقرير الخزنة</h3>

    <!-- 🔍 الفلترة -->
    <form method="GET" class="row mb-4">

        <div class="col-md-3">
            <label>الخزنة</label>
            <select name="account_id" class="form-control">
                <option value="">كل الخزن</option>
                @foreach($accounts as $acc)
                    <option value="{{ $acc->id }}" {{ $accountId == $acc->id ? 'selected' : '' }}>
                        {{ $acc->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="col-md-3">
            <label>من تاريخ</label>
            <input type="date" name="from_date" value="{{ $from }}" class="form-control">
        </div>

        <div class="col-md-3">
            <label>إلى تاريخ</label>
            <input type="date" name="to_date" value="{{ $to }}" class="form-control">
        </div>

        <div class="col-md-3 d-flex align-items-end">
            <button class="btn btn-primary w-100">عرض</button>
        </div>

    </form>

    <!-- 💰 الملخص -->
    <div class="row text-center mb-4">

        <div class="col-md-3">
            <div class="card p-3 bg-light">
                <h6>رصيد أول المدة</h6>
                <strong>{{ number_format($openingBalance, 2) }}</strong>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card p-3 bg-success text-white">
                <h6>إجمالي الداخل</h6>
                <strong>{{ number_format($totalIn, 2) }}</strong>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card p-3 bg-danger text-white">
                <h6>إجمالي الخارج</h6>
                <strong>{{ number_format($totalOut, 2) }}</strong>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card p-3 bg-dark text-white">
                <h6>الرصيد النهائي</h6>
                <strong>{{ number_format($closingBalance, 2) }}</strong>
            </div>
        </div>

    </div>

    <!-- 📋 جدول العمليات -->
    <div class="card">
        <div class="card-body p-0">

            <table class="table table-bordered mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الخزنة</th>
                        <th>النوع</th>
                        <th>المبلغ</th>
                        <th>ملاحظات</th>
                        <th>التاريخ</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse($transactions as $t)
                        <tr>
                            <td>{{ $t->id }}</td>
                            <td>{{ $t->account->name ?? '-' }}</td>
                            <td>
                                @if($t->type == 'in')
                                    <span class="badge bg-success">دخل</span>
                                @else
                                    <span class="badge bg-danger">خرج</span>
                                @endif
                            </td>
                            <td>{{ number_format($t->amount, 2) }}</td>
                            <td>{{ $t->notes }}</td>
                            <td>{{ $t->created_at->format('Y-m-d') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center">لا توجد بيانات</td>
                        </tr>
                    @endforelse
                </tbody>

            </table>

        </div>
    </div>

</div>
@endsection