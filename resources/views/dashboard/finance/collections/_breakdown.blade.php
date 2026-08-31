{{-- Finance V2, Phase 2A — expandable per-payment service/refund breakdown. --}}
<tr id="breakdown-{{ $row['payment']->id }}" class="collapse">
    <td colspan="13" class="bg-light">
        <div class="p-3">
            @if($row['payment']->allocations->isNotEmpty())
                <h6 class="mb-2">Распределение по услугам</h6>
                <table class="table table-sm table-bordered mb-3 bg-white">
                    <thead>
                        <tr>
                            <th>Услуга</th>
                            <th>Строка счёта</th>
                            <th class="text-end">Начислено</th>
                            <th class="text-end">Возвращено</th>
                            <th class="text-end">Чисто</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($row['payment']->allocations as $allocation)
                            @php
                                $allocRefunded = $allocation->refundAllocations->reduce(
                                    fn (string $carry, $refundAllocation): string => bcadd($carry, (string) $refundAllocation->amount, 2),
                                    '0.00'
                                );
                                $allocNet = bcsub((string) $allocation->amount, $allocRefunded, 2);
                            @endphp
                            <tr>
                                <td>{{ $allocation->item->fee?->name_ru ?? '—' }}</td>
                                <td class="text-muted">{{ $allocation->item->description }}</td>
                                <td class="text-end">{{ number_format((float) $allocation->amount, 2, '.', ' ') }}</td>
                                <td class="text-end">{{ number_format((float) $allocRefunded, 2, '.', ' ') }}</td>
                                <td class="text-end">{{ number_format((float) $allocNet, 2, '.', ' ') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if($row['refund_rows']->isNotEmpty())
                <h6 class="mb-2">Возвраты</h6>
                <table class="table table-sm table-bordered mb-0 bg-white">
                    <thead>
                        <tr>
                            <th>Квитанция возврата</th>
                            <th>Дата</th>
                            <th>Причина</th>
                            <th class="text-end">Сумма</th>
                            <th>Статус</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($row['refund_rows'] as $refundRow)
                            <tr>
                                <td>{{ $refundRow['refund']->display_number }}</td>
                                <td>{{ optional($refundRow['refund']->refunded_at)->format('d.m.Y H:i') }}</td>
                                <td>{{ $refundRow['refund']->reason }}</td>
                                <td class="text-end">{{ number_format((float) $refundRow['refund']->amount, 2, '.', ' ') }}</td>
                                <td>@include('dashboard.finance.collections._status_badge', ['status' => $refundRow['status']])</td>
                                <td><a href="{{ route('dashboard.refunds.receipt', $refundRow['refund']) }}" class="btn btn-sm btn-outline-secondary" target="_blank">Квитанция</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if($row['payment']->allocations->isEmpty() && $row['refund_rows']->isEmpty())
                <p class="text-muted mb-0">Нет данных для детализации.</p>
            @endif
        </div>
    </td>
</tr>
