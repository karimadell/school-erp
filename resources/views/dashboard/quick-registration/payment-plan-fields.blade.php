<section class="card shadow-sm mb-4"><div class="card-header fw-bold">4. Порядок оплаты</div><div class="card-body row g-3">
    {{-- Finance V2 Phase 1 UI — per-service billing_strategy/payment_period
         (Section 2) already let each service resolve its own schedule, so
         this section no longer asks for one global payment_type/
         billing_period pair. Only two variants remain here:
           - "auto": the normal path — payment_type is computed client-side
             as 'mixed' whenever any selected service actually needs a
             calendar schedule (or Food is selected), or 'one_time' when
             every selected service is once-only. Both are existing,
             unchanged backend payment_type values — this UI simply stops
             asking the operator to name one explicitly.
           - "plan": the pre-existing custom installment-plan path,
             completely unchanged (global payment_plan_id, one plan for the
             whole invoice) — mixed and plan remain mutually exclusive, so
             choosing this hides/ignores every per-service billing control. --}}
    <div class="col-md-4">
        <label class="form-label">Вариант</label>
        <select id="payment-mode" class="form-select">
            <option value="auto">Автоматически по каждой услуге</option>
            <option value="plan" @selected(old('payment_type') === 'plan') @disabled(! $installmentsReadiness['ready'])>Рассрочка (индивидуальный план)</option>
        </select>
        <div class="form-text">Период оплаты по обучению, транспорту и дополнительным услугам указывается прямо в карточке услуги — раздел 2.</div>
    </div>
    <div class="col-md-8" id="payment-plan-wrapper" style="display:none">
        <label class="form-label">Предустановленный план</label>
        <select name="payment_plan_id" id="payment-plan-id" class="form-select" @disabled(! $installmentsReadiness['ready'])>
            <option value="">Выберите план</option>
            @foreach($paymentPlans as $plan)
                <option value="{{ $plan->id }}" @selected(old('payment_plan_id') == $plan->id)>{{ $plan->name_ru }} — этапов: {{ $plan->installments->count() }}</option>
            @endforeach
        </select>
        @if(! $installmentsReadiness['ready'])
            <div class="form-text text-danger">{{ $installmentsReadiness['reason'] }}</div>
        @endif
    </div>
    {{-- Computed client-side at submit time (create.blade.php's own
         script owns every row's data, so it is the single place that
         actually knows whether any service needs a calendar schedule). --}}
    <input type="hidden" name="payment_type" id="payment-type-input" value="{{ old('payment_type', 'mixed') }}">
</div></section>
