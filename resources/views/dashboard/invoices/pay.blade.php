@extends('layouts.dashboard')

@section('content')
<div class="container">
    <h2 class="mb-4">
        دفع فاتورة رقم #{{ $invoice->id }}
    </h2>

    {{-- Invoice summary --}}
    <div class="card mb-4">
        <div class="card-body">
            <p><strong>العميل / الطالب:</strong> {{ $invoice->customer_name }}</p>
            <p><strong>إجمالي المبلغ:</strong>
                {{ number_format($invoice->total_amount, 2) }}
            </p>

            <hr>

            <strong>تفاصيل الرسوم:</strong>
            <ul class="mt-2">
                @foreach($invoice->items as $item)
                    <li>
                        {{ $item->description }}
                        — {{ number_format($item->amount, 2) }}
                    </li>
                @endforeach
            </ul>
        </div>
    </div>

    {{-- Pay form --}}
    <form action="{{ route('dashboard.invoices.pay', $invoice) }}" method="POST">
        @csrf

        <div class="mb-3">
            <label class="form-label">اختر الخزنة / الحساب</label>
            <select name="cash_account_id"
                    class="form-select"
                    required>
                <option value="">-- اختر --</option>
                @foreach($cashAccounts as $account)
                    <option value="{{ $account->id }}">
                        {{ $account->name }}
                        (الرصيد الحالي:
                        {{ number_format($account->balance, 2) }})
                    </option>
                @endforeach
            </select>
        </div>

        <div class="mt-4">
            <button type="submit"
                    class="btn btn-success"
                    onclick="return confirm('تأكيد دفع الفاتورة؟')">
                تأكيد الدفع
            </button>

            <a href="{{ route('dashboard.invoices.index') }}"
               class="btn btn-secondary">
                إلغاء
            </a>
        </div>
    </form>
</div>
@endsection