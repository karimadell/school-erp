<section class="card shadow-sm mb-4"><div class="card-header fw-bold">4. Порядок оплаты</div><div class="card-body row g-3">
    <div class="col-md-4">
        <label class="form-label">Вариант</label>
        <select name="payment_type" id="payment-type" class="form-select">
            <option value="one_time">Единовременная оплата</option>
            <option value="calendar" @selected(old('payment_type')==='calendar')>Периодическая оплата (по календарю)</option>
            <option value="plan" @selected(old('payment_type')==='plan') @disabled(! $installmentsReadiness['ready'])>Рассрочка (индивидуальный план)</option>
        </select>
    </div>
    {{-- Finance V2, Phase 2B: shown only for payment_type=calendar. Which
         options are actually valid for the selected service(s) is enforced
         server-side (StoreQuickStudentRegistrationRequest) — a choice
         invalid for a selected service (e.g. Registration, which only
         allows "once") is rejected with a clear error, not silently
         filtered out of this list in the browser. --}}
    <div class="col-md-8" id="billing-period-wrapper" style="display:none">
        <label class="form-label">Период оплаты</label>
        <select name="billing_period" id="billing-period" class="form-select">
            <option value="">Выберите период</option>
            <option value="monthly" @selected(old('billing_period')==='monthly')>Ежемесячно</option>
            <option value="quarterly" @selected(old('billing_period')==='quarterly')>Ежеквартально</option>
            <option value="yearly" @selected(old('billing_period')==='yearly')>Ежегодно</option>
        </select>
    </div>
    <div class="col-md-8" id="payment-plan-wrapper">
        <label class="form-label">Предустановленный план</label>
        <select name="payment_plan_id" id="payment-plan-id" class="form-select" @disabled(! $installmentsReadiness['ready'])>
            <option value="">Выберите план</option>
            @foreach($paymentPlans as $plan)
                <option value="{{ $plan->id }}" @selected(old('payment_plan_id')==$plan->id)>{{ $plan->name_ru }} — этапов: {{ $plan->installments->count() }}</option>
            @endforeach
        </select>
        @if(! $installmentsReadiness['ready'])
            <div class="form-text text-danger">{{ $installmentsReadiness['reason'] }}</div>
        @endif
    </div>
</div></section>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const type = document.getElementById('payment-type');
    const plan = document.getElementById('payment-plan-id');
    const planWrapper = document.getElementById('payment-plan-wrapper');
    const period = document.getElementById('billing-period');
    const periodWrapper = document.getElementById('billing-period-wrapper');
    const form = document.getElementById('quick-registration-form');
    if (!type || !plan || !form) return;

    const syncVisibility = () => {
        const isCalendar = type.value === 'calendar';
        const isPlan = type.value === 'plan';
        if (periodWrapper) periodWrapper.style.display = isCalendar ? '' : 'none';
        if (planWrapper) planWrapper.style.display = isPlan ? '' : 'none';
    };
    type.addEventListener('change', syncVisibility);
    syncVisibility();

    form.addEventListener('submit', event => {
        if (type.value === 'plan' && !plan.value) {
            event.preventDefault();
            plan.classList.add('is-invalid');
        }
        if (type.value === 'calendar' && period && !period.value) {
            event.preventDefault();
            period.classList.add('is-invalid');
        }
    });
    plan.addEventListener('change', () => plan.classList.remove('is-invalid'));
    if (period) period.addEventListener('change', () => period.classList.remove('is-invalid'));
});
</script>
