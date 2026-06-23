@extends('layouts.dashboard')

@section('content')
<div class="container">
    <h2 class="mb-4">تعديل فاتورة رقم #{{ $invoice->id }}</h2>

    <form action="{{ route('dashboard.invoices.update', $invoice) }}"
          method="POST">
        @csrf
        @method('PUT')

        {{-- Customer --}}
        <div class="mb-3">
            <label class="form-label">اسم العميل</label>
            <input type="text"
                   name="customer_name"
                   class="form-control"
                   value="{{ $invoice->customer_name }}"
                   required>
        </div>

        {{-- Payment Method (readonly) --}}
        <div class="mb-4">
            <label class="form-label">طريقة الدفع</label>
            <input type="text"
                   class="form-control"
                   value="{{ $invoice->cashAccount?->name }}"
                   readonly>
        </div>

        <hr>

        {{-- Fees Checklist --}}
        <h5 class="mb-3">الرسوم</h5>

        <table class="table table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th width="60">اختيار</th>
                    <th>الرسوم</th>
                    <th width="180">المبلغ</th>
                </tr>
            </thead>
            <tbody>
                @foreach($fees as $index => $fee)

                    @php
                        $item = $invoice->items
                            ->firstWhere('fee_id', $fee->id);
                    @endphp

                    <tr>
                        <td class="text-center">
                            <input type="checkbox"
                                   name="fees[{{ $index }}][fee_id]"
                                   value="{{ $fee->id }}"
                                   class="form-check-input fee-checkbox"
                                   {{ $item ? 'checked' : '' }}>
                        </td>

                        <td>
                            {{ $fee->name }}
                        </td>

                        <td>
                            <input type="number"
                                   step="0.01"
                                   name="fees[{{ $index }}][amount]"
                                   class="form-control fee-amount"
                                   value="{{ $item?->amount }}"
                                   {{ $item ? '' : 'disabled' }}>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- Actions --}}
        <div class="mt-4">
            <button class="btn btn-success">
                حفظ التعديل
            </button>

            <a href="{{ route('dashboard.invoices.index') }}"
               class="btn btn-secondary">
                رجوع
            </a>
        </div>
    </form>
</div>

{{-- JS --}}
<script>
document.querySelectorAll('.fee-checkbox').forEach((checkbox) => {
    checkbox.addEventListener('change', function () {
        const amountInput =
            this.closest('tr').querySelector('.fee-amount');

        if (this.checked) {
            amountInput.disabled = false;
            amountInput.required = true;
        } else {
            amountInput.disabled = true;
            amountInput.required = false;
            amountInput.value = '';
        }
    });
});
</script>
@endsection