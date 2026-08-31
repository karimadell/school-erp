@php $categories=['registration'=>'Регистрационный взнос','tuition'=>'Обучение','tuition_regular'=>'Обычное обучение','tuition_family'=>'Семейное обучение','tuition_external'=>'Экстернат','transport'=>'Транспорт','food'=>'Питание','uniform'=>'Школьная форма','books'=>'Книги','extra_classes'=>'Дополнительные занятия','activity'=>'Мероприятия','other'=>'Дополнительные услуги']; $periods=['once'=>'Разово','daily'=>'Ежедневно','monthly'=>'Ежемесячно','quarterly'=>'Ежеквартально','term'=>'За семестр','yearly'=>'За год','package'=>'Пакет']; @endphp
<div class="row g-3">
 <div class="col-md-6"><label class="form-label">Название услуги на русском языке</label><input class="form-control" name="name_ru" value="{{ old('name_ru',$fee->name_ru ?? '') }}" required></div>
 <div class="col-md-3"><label class="form-label">{{ __('finance_uat.service_kind') }}</label><select class="form-select" name="category" required>@foreach($categories as $v=>$l)<option value="{{ $v }}" @selected(old('category',$fee->category ?? '')===$v)>{{ $l }}</option>@endforeach</select><div class="form-text">{{ __('finance_uat.service_kind_help') }}</div></div>
 <div class="col-md-3"><label class="form-label">Тип начисления</label><select class="form-select" name="type" required>@foreach(['service'=>'Услуга','monthly'=>'Ежемесячно','yearly'=>'Ежегодно'] as $v=>$l)<option value="{{ $v }}" @selected(old('type',$fee->type ?? 'service')===$v)>{{ $l }}</option>@endforeach</select></div>
 <div class="col-md-4"><label class="form-label">Период оплаты</label><select class="form-select" name="payment_period"><option value="">Не задан</option>@foreach($periods as $v=>$l)<option value="{{ $v }}" @selected(old('payment_period',$fee->payment_period ?? '')===$v)>{{ $l }}</option>@endforeach</select></div>
 <div class="col-md-8"><label class="form-label">Описание</label><textarea class="form-control" name="description">{{ old('description',$fee->description ?? '') }}</textarea></div>
 <div class="col-12 form-check ms-2"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="active" @checked(old('is_active',$fee->is_active ?? true))><label class="form-check-label" for="active">Активна</label></div>
 <div class="col-12 form-check ms-2"><input type="hidden" name="is_non_refundable" value="0"><input class="form-check-input" type="checkbox" name="is_non_refundable" value="1" id="non-refundable" @checked(old('is_non_refundable',$fee->is_non_refundable ?? false))><label class="form-check-label" for="non-refundable">Не подлежит возврату</label><div class="form-text">Эта отметка используется в счетах, квитанциях и будущих правилах возврата.</div></div>
 @php
   $selectedPeriods = old('billing_periods', isset($fee) ? $fee->billingPeriods->pluck('billing_period')->all() : []);
   $billingPeriodOptions = ['once'=>'Разово','monthly'=>'Ежемесячно','quarterly'=>'Ежеквартально','yearly'=>'Ежегодно','custom_plan'=>'Индивидуальный план'];
   $selectedPlanIds = old('payment_plan_ids', isset($fee) ? $fee->assignedPaymentPlans->pluck('id')->all() : []);
 @endphp
 <div class="col-12">
   <label class="form-label d-block">Допустимые варианты оплаты (Finance V2, Phase 2B)</label>
   <div class="form-text mb-2">Определяет, какие периоды оплаты и планы рассрочки будут доступны для этой услуги в Быстрой регистрации — никогда не предлагается глобально.</div>
   @foreach($billingPeriodOptions as $v=>$l)
     <div class="form-check form-check-inline">
       <input class="form-check-input" type="checkbox" name="billing_periods[]" value="{{ $v }}" id="billing-period-{{ $v }}" @checked(in_array($v,$selectedPeriods,true))>
       <label class="form-check-label" for="billing-period-{{ $v }}">{{ $l }}</label>
     </div>
   @endforeach
 </div>
 <div class="col-12">
   <label class="form-label">Назначенные планы рассрочки (только если отмечен «Индивидуальный план»)</label>
   <select class="form-select" name="payment_plan_ids[]" multiple size="4">
     @foreach($paymentPlans as $plan)
       <option value="{{ $plan->id }}" @selected(in_array($plan->id,$selectedPlanIds,true))>{{ $plan->name_ru }}</option>
     @endforeach
   </select>
 </div>
</div>
