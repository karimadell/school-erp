<section class="card shadow-sm mb-4"><div class="card-header fw-bold">4. Порядок оплаты</div><div class="card-body row g-3">
    <div class="col-md-4">
        <label class="form-label">Вариант</label>
        <select name="payment_type" id="payment-type" class="form-select">
            <option value="one_time">Единовременная оплата</option>
            <option value="plan" @selected(old('payment_type')==='plan') @disabled($paymentPlans->isEmpty())>Рассрочка</option>
        </select>
    </div>
    <div class="col-md-8">
        <label class="form-label">Предустановленный план</label>
        <select name="payment_plan_id" id="payment-plan-id" class="form-select" @disabled($paymentPlans->isEmpty())>
            <option value="">Выберите план</option>
            @foreach($paymentPlans as $plan)
                <option value="{{ $plan->id }}" @selected(old('payment_plan_id')==$plan->id)>{{ $plan->name_ru }} — этапов: {{ $plan->installments->count() }}</option>
            @endforeach
        </select>
        @if($paymentPlans->isEmpty())
            <div class="form-text text-danger">Нет активных планов рассрочки.</div>
        @endif
    </div>
</div></section>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const type = document.getElementById('payment-type');
    const plan = document.getElementById('payment-plan-id');
    const form = document.getElementById('quick-registration-form');
    if (!type || !plan || !form) return;
    form.addEventListener('submit', event => {
        if (type.value === 'plan' && !plan.value) {
            event.preventDefault();
            plan.classList.add('is-invalid');
        }
    });
    plan.addEventListener('change', () => plan.classList.remove('is-invalid'));
});
</script>
