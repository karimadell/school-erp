@extends('layouts.dashboard')

@section('content')
@php
    $tuitionCategories = ['tuition', 'tuition_regular', 'tuition_family', 'tuition_external'];
    $additionalServiceCategories = ['books', 'extra_classes', 'activity', 'other'];
    $groups = [
        'registration' => ['title' => 'Регистрационный взнос', 'fees' => $fees->where('category', 'registration')],
        'tuition' => ['title' => 'Обучение', 'fees' => $fees->whereIn('category', $tuitionCategories)],
        'transport' => ['title' => 'Транспорт', 'fees' => $fees->where('category', 'transport')],
        'food' => ['title' => 'Питание', 'fees' => $fees->where('category', 'food')],
        'uniform' => ['title' => 'Школьная форма', 'fees' => $fees->where('category', 'uniform')],
        'other' => ['title' => 'Дополнительные услуги', 'fees' => $fees->whereIn('category', $additionalServiceCategories)],
    ];
    $oldServices = collect(old('services', []))->keyBy(fn ($service) => (string) ($service['fee_id'] ?? ''));
    $configurationReady = $academicYears->isNotEmpty() && $modes->isNotEmpty() && $fees->isNotEmpty();
    $periodLabels = ['once' => 'Разово', 'daily' => 'Ежедневно', 'monthly' => 'Ежемесячно', 'quarterly' => 'Ежеквартально', 'term' => 'За семестр', 'yearly' => 'За год', 'package' => 'Пакет'];

    // Minimum safe availability gating, backed by FinanceConfigurationReadinessService
    // (Phase 3) — the controller computes readiness once, from the same
    // FeePrice::sellable() rows InvoiceCalculationService resolves from, and
    // hands it to this view as data. Only transport/food/uniform are
    // actively gated here; tuition/registration/other stay selectable
    // regardless of readiness (their pricing fallback and dimensional
    // matching are more forgiving by design — see the Phase 3 report).
    $gatedCategories = ['transport', 'food', 'uniform'];

    // Review corrective pass (P1) — a calendar period must never be
    // offered/auto-selected to the operator unless it is BOTH canonically
    // allowed (Fee::allowedBillingPeriods(), the Phase 2B source) AND
    // actually purchasable right now. "Purchasable" is read directly off
    // $fee->prices as the controller already scoped it via
    // InvoiceCalculationService::resolvableCandidates() — the exact same
    // canonical availability resolution the live /quick-registration/price
    // endpoint and every other category on this page already depend on —
    // never a second, ad-hoc pricing engine reimplemented here. The one
    // extra rule (quarterly derivable from an existing monthly price) is
    // not invented by this pass either: it mirrors, verbatim, the single
    // derivation InvoiceCalculationService::resolvePrice() itself performs
    // (quarterly = monthly × 3) — Tuition's own pre-existing period
    // dropdown already assumes this same rule in its own comment above.
    $purchasablePeriodsFor = function ($fee) {
        $allowed = $fee->allowedBillingPeriods()->intersect(\App\Models\FeeBillingPeriod::CALENDAR_PERIODS);
        $priced = $fee->prices->pluck('payment_period')->filter()->unique();

        return $allowed->filter(fn ($period) => $priced->contains($period)
            || ($period === \App\Models\FeeBillingPeriod::PERIOD_QUARTERLY && $priced->contains('monthly')))
            ->values();
    };
    // A Fee explicitly configured for calendar billing (allowedBillingPeriods
    // non-empty) but with none of those periods currently purchasable is
    // never silently offered as "Разовая оплата" — that would misrepresent
    // a periodic tariff that simply isn't priced yet as a deliberately
    // one-time one. It fails closed the same way Transport/Food/Uniform
    // already do here: the whole service becomes unselectable, with a
    // visible reason, never a quiet fallback to billing_strategy=once.
    // Scoped to Additional services only — Tuition/Transport keep their
    // existing (unrelated-to-this-fix) availability behavior untouched;
    // this pass does not redesign or re-gate those two categories.
    $calendarConfiguredButUnpriced = fn ($fee) => in_array($fee->category, $additionalServiceCategories, true)
        && $fee->allowedBillingPeriods()->intersect(\App\Models\FeeBillingPeriod::CALENDAR_PERIODS)->isNotEmpty()
        && $purchasablePeriodsFor($fee)->isEmpty();
    $serviceIsAvailable = fn ($fee) => (! in_array($fee->category, $gatedCategories, true)
        || ($serviceReadiness[$fee->id]['ready'] ?? false))
        && ! $calendarConfiguredButUnpriced($fee);
    // $calendarConfiguredButUnpriced checked FIRST: FinanceConfigurationReadinessService
    // computes a (generic, pricing-unrelated) reason for every fee it
    // evaluates, not only gated categories — that reason must never mask
    // this specifically diagnosed cause when it's the one actually
    // blocking the checkbox above.
    $unavailableReason = fn ($fee) => $calendarConfiguredButUnpriced($fee)
        ? 'Для этой услуги настроен периодический тариф, но действующая цена не определена — обратитесь к администратору.'
        : ($serviceReadiness[$fee->id]['reason'] ?? null);

    // Multi-item Uniform corrective pass — one compact row per Uniform
    // ITEM (Комплект/Майка/Поло/Толстовка), not one per item+size
    // combination (that would be 40 rows). $uniformProducts already
    // carries only sellable (active FeePrice-backed) rows — see the
    // controller's own filter — so this grouping can never expose a
    // legacy grouped size or an item+size with no active tariff. Sizes are
    // ordered by the catalog's own real-world sequence, never
    // alphabetically (a plain string sort would put "10" before "6").
    $uniformSizeOrder = ['6', '8', '10', '12', '14', '16', 'S', 'M', 'L', 'XL'];
    $uniformProductsByItem = $uniformProducts->groupBy('name_ru')
        ->map(fn ($products) => $products->sortBy(fn ($p) => array_search($p->size, $uniformSizeOrder, true))->values());
@endphp
<div class="container-fluid py-4">
@if($registrationSuccess)
    @include('dashboard.quick-registration._success-panel', ['registrationSuccess' => $registrationSuccess])
@else
    <ul class="nav nav-pills mb-4" role="tablist">
        <li class="nav-item"><button type="button" class="nav-link active" data-mode-tab="new" aria-selected="true">Новый ученик</button></li>
        <li class="nav-item"><button type="button" class="nav-link" data-mode-tab="existing" aria-selected="false">Существующий ученик</button></li>
    </ul>

    <section class="card shadow-sm mb-4 d-none" id="existing-student-panel">
        <div class="card-header fw-bold">Найти существующего ученика</div>
        <div class="card-body">
            <p class="text-muted">Откройте карточку ученика — учебный год, ступень, класс, форма обучения, активные подписки и неоплаченные счета загрузятся автоматически. Оттуда можно принять оплату по существующему счёту, продлить транспорт/питание или начислить новую услугу без повторного ввода данных.</p>
            <form method="GET" action="{{ route('dashboard.finance.income.students') }}" class="row g-2">
                <div class="col-md-8"><input type="text" name="q" class="form-control" placeholder="Имя, телефон или ID ученика"></div>
                <div class="col-md-4"><button class="btn btn-primary w-100">Найти ученика</button></div>
            </form>
        </div>
    </section>

    <div id="new-student-panel">
    <h2 class="mb-1">Быстрая регистрация нового ученика</h2>
    <p class="text-muted">Минимальное оформление и первоначальный счёт. Валюта расчётов: EGP.</p>

    @if($errors->any())
        <div class="alert alert-danger"><strong>Проверьте введённые данные:</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    @if($academicYears->isEmpty())<div class="alert alert-warning" data-configuration-warning="academic-year">Нет активного учебного года.</div>@endif
    @if($modes->isEmpty())<div class="alert alert-warning" data-configuration-warning="enrollment-mode">Формы обучения не настроены. @can('manage academic years')<a href="{{ route('dashboard.academic.enrollment-modes.index') }}" class="alert-link">Настроить формы обучения</a>@endcan</div>@endif
    @if($fees->isEmpty())<div class="alert alert-warning" data-configuration-warning="services">Финансовые услуги не настроены. Обратитесь к администратору.</div>@endif

    <form method="POST" action="{{ route('dashboard.quick-registration.store') }}" id="quick-registration-form">
        @csrf
        {{-- Finance V2, Phase 2B corrective pass (review finding M3): generated
             ONCE per page render, never by JS on interaction, so a double-click
             or an automatic retry of this same already-rendered form submits
             the SAME token both times. old() preserves it across a
             validation-error round-trip; only a genuine fresh page load gets
             a new one. Used server-side to derive stable, deterministic
             per-installment payment idempotency keys instead of a fresh
             random one per attempt. --}}
        <input type="hidden" name="idempotency_token" value="{{ old('idempotency_token', (string) \Illuminate\Support\Str::uuid()) }}">
        <section class="card shadow-sm mb-4">
            <div class="card-header fw-bold">1. Минимальные данные ученика</div>
            <div class="card-body row g-3">
                <div class="col-md-4"><label class="form-label">Фамилия *</label><input name="student_last_name_ru" value="{{ old('student_last_name_ru') }}" class="form-control" required></div>
                <div class="col-md-4"><label class="form-label">Имя *</label><input name="student_first_name_ru" value="{{ old('student_first_name_ru') }}" class="form-control" required></div>
                <div class="col-md-4"><label class="form-label">Отчество</label><input name="student_patronymic_ru" value="{{ old('student_patronymic_ru') }}" class="form-control"></div>
                <div class="col-md-4"><label class="form-label">Телефон *</label><input name="phone" value="{{ old('phone') }}" class="form-control" required></div>
                <div class="col-md-4"><label class="form-label">Дата регистрации *</label><input type="date" name="registration_date" value="{{ old('registration_date', now()->toDateString()) }}" class="form-control" required><div class="form-text">По этой дате система выбирает действующий тариф.</div></div>
                <div class="col-md-4"><label class="form-label">Учебный год *</label><select name="academic_year_id" class="form-select" required><option value="">Выберите учебный год</option>@foreach($academicYears as $year)<option value="{{ $year->id }}" @selected((string) old('academic_year_id', $defaultAcademicYearId) === (string) $year->id)>{{ $year->name }}</option>@endforeach</select></div>
                <div class="col-md-3"><label class="form-label">Ступень *</label><select name="stage_id" id="stage" class="form-select" required><option value="">Выберите ступень</option>@foreach($stages as $stage)<option value="{{ $stage->id }}" @selected((string) old('stage_id') === (string) $stage->id)>{{ $stage->name }}</option>@endforeach</select></div>
                <div class="col-md-3"><label class="form-label">Класс *</label><select name="grade_id" id="grade" class="form-select" required><option value="">Сначала выберите ступень.</option>@foreach($stages as $stage)@foreach($stage->grades as $grade)<option value="{{ $grade->id }}" data-stage="{{ $stage->id }}" @selected((string) old('grade_id') === (string) $grade->id)>{{ $grade->name }}</option>@endforeach @endforeach</select></div>
                <div class="col-md-3"><label class="form-label">Буква класса *</label><select name="class_id" id="school-class" class="form-select" required><option value="">Сначала выберите класс.</option>@foreach($stages as $stage)@foreach($stage->grades as $grade)@foreach($grade->classes as $class)<option value="{{ $class->id }}" data-grade="{{ $grade->id }}" @selected((string) old('class_id') === (string) $class->id)>{{ $class->name_ru ?: $class->code }}</option>@endforeach @endforeach @endforeach</select></div>
                <div class="col-md-3"><label class="form-label">Форма обучения *</label><select name="enrollment_mode_id" id="enrollment-mode" class="form-select" required><option value="">Выберите форму обучения</option>@foreach($modes as $mode)<option value="{{ $mode->id }}" @selected((string) old('enrollment_mode_id', $defaultEnrollmentModeId) === (string) $mode->id)>{{ $mode->name_ru }}</option>@endforeach</select></div>
                <div class="col-12"><label class="form-label">Примечание</label><textarea name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea></div>
            </div>
        </section>

        <section class="card shadow-sm mb-4">
            <div class="card-header fw-bold">2. Финансовые услуги</div>
            <div class="card-body">
                @foreach($groups as $groupKey => $group)
                    <h5 class="mt-3 mb-3">{{ $group['title'] }}</h5>
                    @forelse($group['fees'] as $fee)
                        @php
                            $index = $fees->search(fn ($candidate) => $candidate->id === $fee->id);
                            $oldService = $oldServices->get((string) $fee->id, []);
                            $available = $serviceIsAvailable($fee);
                        @endphp
                        <div class="service-row border rounded p-3 mb-3 {{ $available ? '' : 'opacity-75' }}" data-service-row data-fee-id="{{ $fee->id }}" data-category="{{ $fee->category }}" data-name="{{ $fee->name_ru }}">
                            <div class="form-check">
                                <input class="form-check-input service-toggle" type="checkbox" name="services[{{ $index }}][fee_id]" value="{{ $fee->id }}" id="fee-{{ $fee->id }}" @checked($oldService !== []) @disabled(!$available)>
                                <label class="form-check-label fw-bold" for="fee-{{ $fee->id }}">{{ $fee->name_ru }} — {{ $fee->prices->isNotEmpty() ? 'цена определяется по выбранным параметрам' : number_format($fee->current_amount, 2, '.', '').' EGP' }}</label>
                            </div>
                            @unless($available)
                                <div class="text-danger small mt-1">{{ $unavailableReason($fee) }}</div>
                            @endunless
                            <div class="service-fields row g-3 mt-1 d-none">
                                @if($groupKey !== 'uniform')<input type="hidden" name="services[{{ $index }}][quantity]" value="1" class="quantity">@endif
                                {{-- Finance V2 Phase 1 UI — resolved reactively by the page
                                     script from this row's own payment_period value (once
                                     that value is a genuine calendar period: monthly/
                                     quarterly/yearly) and only meaningful once the computed
                                     top-level payment_type becomes 'mixed'. Food resolves
                                     its own coverage independently and never carries this
                                     field at all — never trust it, never send it. --}}
                                @if($groupKey !== 'food')<input type="hidden" name="services[{{ $index }}][billing_strategy]" value="once" class="billing-strategy-field">@endif
                                @if($groupKey === 'tuition')
                                    @php
                                        // Corrective pass — a period this Fee allows (FeeBillingPeriod,
                                        // the Phase 2B canonical source) must be offerable even when no
                                        // explicit FeePrice row exists for it yet: InvoiceCalculationService
                                        // derives quarterly = monthly × 3 in exactly that case. Scoped to
                                        // calendar-billing periods only — 'custom_plan' is a distinct UI
                                        // concept handled by the separate payment-plan selection below, not
                                        // this dropdown.
                                        $tuitionPeriodOptions = $fee->prices->pluck('payment_period')->filter()->unique()
                                            ->merge($fee->allowedBillingPeriods()->intersect(\App\Models\FeeBillingPeriod::CALENDAR_PERIODS))
                                            ->unique()->values();
                                    @endphp
                                    <div class="col-md-3"><label class="form-label">Группа классов</label><select name="services[{{ $index }}][grade_group]" class="form-select price-option"><option value="">По выбранному классу</option>@foreach($fee->prices->pluck('grade_group')->filter()->unique() as $option)<option value="{{ $option }}" @selected(($oldService['grade_group'] ?? null) === $option)>{{ $option }}</option>@endforeach</select></div>
                                    <div class="col-md-3"><label class="form-label">Период оплаты</label><select name="services[{{ $index }}][payment_period]" class="form-select price-option"><option value="">Стандартный</option>@foreach($tuitionPeriodOptions as $option)<option value="{{ $option }}" @selected(($oldService['payment_period'] ?? null) === $option)>{{ $periodLabels[$option] ?? $option }}</option>@endforeach</select></div>
                                    <div class="col-md-3 form-check mt-5"><input type="checkbox" value="1" name="services[{{ $index }}][first_last_month]" class="form-check-input price-option" id="first-last-{{ $fee->id }}" @checked(!empty($oldService['first_last_month']))><label for="first-last-{{ $fee->id }}">Первый и последний месяц</label></div>
                                @elseif($groupKey === 'transport')
                                    @php
                                        // Derived live from this fee's actual sellable FeePrice rows — never
                                        // hardcoded — so the period dropdown can only ever offer combinations
                                        // that InvoiceCalculationService would actually resolve for that zone.
                                        $transportPeriodsByZone = $fee->prices->where('option_type', 'zone')
                                            ->groupBy('option_value')
                                            ->map(fn ($prices) => $prices->pluck('payment_period')->filter()->unique()->values());
                                        $transportPeriodRequired = $transportPeriodsByZone->flatten()->isNotEmpty();
                                    @endphp
                                    <div class="col-md-3"><label class="form-label">Зона тарифа *</label><select name="services[{{ $index }}][transport_area]" class="form-select price-option transport-zone" data-periods-by-zone="{{ $transportPeriodsByZone->toJson() }}"><option value="">Выберите зону</option>@foreach($fee->prices->where('option_type', 'zone')->pluck('option_value')->filter()->unique() as $zone)<option value="{{ $zone }}" @selected(($oldService['transport_area'] ?? null) === $zone)>{{ $zone }}</option>@endforeach</select></div>
                                    <div class="col-md-3"><label class="form-label">Маршрут *</label><select name="services[{{ $index }}][transport_route_id]" class="form-select"><option value="">Выберите маршрут</option>@foreach($transportRoutes as $route)<option value="{{ $route->id }}" @selected((string) ($oldService['transport_route_id'] ?? '') === (string) $route->id)>{{ $route->name }}</option>@endforeach</select></div>
                                    {{-- Transport Management Phase C — canonical bus selection. Never
                                         free text; only currently-active, non-full buses are offered.
                                         TransportAssignmentService::assign() re-checks capacity under a
                                         row lock at submission time regardless — this list is a UI
                                         convenience only, never the capacity authority. --}}
                                    <div class="col-md-3"><label class="form-label">Микроавтобус *</label><select name="services[{{ $index }}][bus_id]" class="form-select"><option value="">Выберите микроавтобус</option>@foreach($buses as $bus)<option value="{{ $bus->id }}" @selected((string) ($oldService['bus_id'] ?? '') === (string) $bus->id)>{{ $bus->label }} — {{ $bus->occupied }}/{{ $bus->capacity }}{{ $bus->occupied >= $bus->capacity ? ' (проверка мест на дату регистрации)' : '' }}</option>@endforeach</select></div>
                                    <div class="col-md-3"><label class="form-label">Период оплаты{{ $transportPeriodRequired ? ' *' : '' }}</label><select name="services[{{ $index }}][payment_period]" class="form-select price-option transport-period" data-old-value="{{ $oldService['payment_period'] ?? '' }}"><option value="">Выберите зону</option></select></div>
                                    <div class="col-md-3"><label class="form-label">Место посадки</label><input name="services[{{ $index }}][transport_stop]" value="{{ $oldService['transport_stop'] ?? '' }}" class="form-control"></div>
                                @elseif($groupKey === 'food')
                                    <div class="col-md-4"><label class="form-label">План питания *</label><select name="services[{{ $index }}][meal_plan_id]" class="form-select price-option"><option value="">Выберите план питания</option>@foreach($mealPlans as $plan)<option value="{{ $plan->id }}" @selected((string) ($oldService['meal_plan_id'] ?? '') === (string) $plan->id)>{{ $plan->name_ru }}</option>@endforeach</select></div>
                                    <div class="col-md-3">
                                        <label class="form-label">Режим периода *</label>
                                        <select name="services[{{ $index }}][food_duration_mode]" class="form-select price-option food-duration-mode">
                                            <option value="day" @selected(($oldService['food_duration_mode'] ?? 'day') === 'day')>Один день</option>
                                            <option value="school_week" @selected(($oldService['food_duration_mode'] ?? '') === 'school_week')>Учебная неделя</option>
                                            <option value="teaching_days" @selected(($oldService['food_duration_mode'] ?? '') === 'teaching_days')>N учебных дней</option>
                                            <option value="month" @selected(($oldService['food_duration_mode'] ?? '') === 'month')>Месяц(ы)</option>
                                            <option value="custom_range" @selected(($oldService['food_duration_mode'] ?? '') === 'custom_range')>Произвольный период</option>
                                        </select>
                                    </div>
                                    <div class="col-md-5 food-duration-fields" data-mode="day">
                                        <label class="form-label">Дата *</label>
                                        <input type="date" name="services[{{ $index }}][food_date]" value="{{ $oldService['food_date'] ?? now()->toDateString() }}" class="form-control price-option food-field" data-food-mode="day">
                                    </div>
                                    <div class="col-md-5 food-duration-fields d-none" data-mode="school_week">
                                        <label class="form-label">Начало учебной недели *</label>
                                        <input type="date" name="services[{{ $index }}][food_week_start]" value="{{ $oldService['food_week_start'] ?? '' }}" class="form-control price-option food-field" data-food-mode="school_week">
                                    </div>
                                    <div class="col-md-5 food-duration-fields d-none row g-2" data-mode="teaching_days">
                                        <div class="col-6"><label class="form-label">Начало *</label><input type="date" name="services[{{ $index }}][food_start_date]" value="{{ $oldService['food_start_date'] ?? '' }}" class="form-control price-option food-field" data-food-mode="teaching_days"></div>
                                        <div class="col-6"><label class="form-label">Кол-во учебных дней *</label><input type="number" min="1" name="services[{{ $index }}][food_day_count]" value="{{ $oldService['food_day_count'] ?? '' }}" class="form-control price-option food-field" data-food-mode="teaching_days"></div>
                                    </div>
                                    <div class="col-md-5 food-duration-fields d-none row g-2" data-mode="month">
                                        <div class="col-6"><label class="form-label">Первый месяц *</label><input type="month" name="services[{{ $index }}][food_month]" value="{{ $oldService['food_month'] ?? now()->format('Y-m') }}" class="form-control price-option food-field" data-food-mode="month"></div>
                                        <div class="col-6"><label class="form-label">Последний месяц</label><input type="month" name="services[{{ $index }}][food_end_month]" value="{{ $oldService['food_end_month'] ?? '' }}" class="form-control price-option food-field" data-food-mode="month"></div>
                                    </div>
                                    <div class="col-md-5 food-duration-fields d-none row g-2" data-mode="custom_range">
                                        <div class="col-6"><label class="form-label">Начало *</label><input type="date" name="services[{{ $index }}][food_range_start]" value="{{ $oldService['food_range_start'] ?? '' }}" class="form-control price-option food-field" data-food-mode="custom_range"></div>
                                        <div class="col-6"><label class="form-label">Окончание *</label><input type="date" name="services[{{ $index }}][food_range_end]" value="{{ $oldService['food_range_end'] ?? '' }}" class="form-control price-option food-field" data-food-mode="custom_range"></div>
                                    </div>
                                @elseif($groupKey === 'uniform')
                                    <div class="col-12">
                                        <label class="form-label mb-1">Изделия и размеры *</label>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-borderless align-middle mb-0 uniform-items-table">
                                                <tbody>
                                                @foreach($uniformProductsByItem as $itemName => $sizeOptions)
                                                    <tr class="uniform-item-row" data-uniform-item="{{ $itemName }}">
                                                        <td style="width:2rem"><input type="checkbox" class="form-check-input uniform-item-toggle"></td>
                                                        <td>{{ $itemName }}</td>
                                                        <td style="width:6rem">
                                                            <select name="services[{{ $index }}][uniform_items][{{ $loop->index }}][uniform_product_id]" class="form-select form-select-sm uniform-item-size" disabled>
                                                                @foreach($sizeOptions as $product)
                                                                    <option value="{{ $product->id }}" data-size="{{ $product->size }}">{{ $product->size }}</option>
                                                                @endforeach
                                                            </select>
                                                        </td>
                                                        <td style="width:5rem"><input type="number" min="1" max="100" name="services[{{ $index }}][uniform_items][{{ $loop->index }}][quantity]" value="1" class="form-control form-control-sm uniform-item-qty" disabled></td>
                                                        <td style="width:6rem" class="uniform-item-unit text-nowrap small text-muted">—</td>
                                                        <td style="width:6rem" class="uniform-item-total text-nowrap fw-semibold">—</td>
                                                    </tr>
                                                @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                @elseif($groupKey === 'other')
                                    @php
                                        // Review corrective pass (P1) — restricted to periods that
                                        // are BOTH canonically allowed AND actually purchasable
                                        // right now ($purchasablePeriodsFor(), defined above —
                                        // reads the same resolvableCandidates()-scoped $fee->prices
                                        // the rest of this page already relies on). A once-only
                                        // additional service (allowedBillingPeriods empty) still
                                        // gets no field at all, byte-identical to before this
                                        // pass; a Fee configured for calendar billing but with
                                        // nothing purchasable is caught earlier — its checkbox is
                                        // already disabled via $serviceIsAvailable, so this branch
                                        // is unreachable for it.
                                        $additionalCalendarPeriods = $purchasablePeriodsFor($fee);
                                    @endphp
                                    @if($additionalCalendarPeriods->count() > 1)
                                        <div class="col-md-3">
                                            <label class="form-label">Порядок оплаты *</label>
                                            <select name="services[{{ $index }}][payment_period]" class="form-select price-option requires-period">
                                                <option value="">Выберите порядок оплаты</option>
                                                @foreach($additionalCalendarPeriods as $option)
                                                    <option value="{{ $option }}" @selected(($oldService['payment_period'] ?? null) === $option)>{{ $periodLabels[$option] ?? $option }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @elseif($additionalCalendarPeriods->count() === 1)
                                        {{-- Exactly one allowed period — auto-selected, no
                                             operator interaction needed. --}}
                                        <input type="hidden" name="services[{{ $index }}][payment_period]" value="{{ $additionalCalendarPeriods->first() }}" class="price-option">
                                        <div class="col-md-3"><label class="form-label">Порядок оплаты</label><div class="form-control-plaintext text-muted small">{{ $periodLabels[$additionalCalendarPeriods->first()] ?? $additionalCalendarPeriods->first() }} (автоматически)</div></div>
                                    @elseif(! $calendarConfiguredButUnpriced($fee))
                                        <div class="col-md-3"><label class="form-label">Порядок оплаты</label><div class="form-control-plaintext text-muted small">Разовая оплата</div></div>
                                    @endif
                                    {{-- $calendarConfiguredButUnpriced($fee): renders neither a
                                         period control nor "Разовая оплата" — that label would
                                         misrepresent a periodic tariff that simply isn't priced
                                         yet as deliberately one-time. The checkbox above is
                                         already disabled with a visible reason; this row can
                                         never actually be checked/submitted. --}}
                                @endif
                                <div class="col-md-2"><label class="form-label">Цена</label><div class="resolved-unit fw-semibold">0.00 EGP</div></div>
                                <div class="col-md-2"><label class="form-label">Стоимость</label><div class="resolved-total fw-semibold">0.00 EGP</div></div>
                                <div class="col-md-2"><label class="form-label">Оплачено</label><input type="number" min="0" step="0.01" name="services[{{ $index }}][paid_now]" value="{{ $oldService['paid_now'] ?? '0.00' }}" class="form-control paid-now"><div class="invalid-feedback payment-overflow">Оплаченная сумма не может превышать стоимость услуги.</div></div>
                                <div class="col-md-2"><label class="form-label">Остаток</label><div class="remaining fw-semibold">0.00 EGP</div></div>
                                <div class="col-12 tariff-period small text-muted"></div>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted">Активные услуги в этой категории не настроены.</p>
                    @endforelse
                @endforeach
            </div>
        </section>

        <section class="card shadow-sm mb-4"><div class="card-header fw-bold">3. Финансовый итог</div><div class="card-body">
            <div class="table-responsive"><table class="table" id="live-summary"><thead><tr><th>Услуга</th><th>Стоимость</th><th>Оплачено</th><th>Остаток</th></tr></thead><tbody><tr class="empty-summary"><td colspan="4" class="text-muted">Услуги не выбраны.</td></tr></tbody><tfoot><tr><th>Итого</th><th id="summary-total">0.00 EGP</th><th id="summary-paid">0.00 EGP</th><th id="summary-remaining">0.00 EGP</th></tr></tfoot></table></div>
            <div><strong>Общая стоимость:</strong> <span id="grand-total">0.00 EGP</span> · <strong>Всего оплачено:</strong> <span id="grand-paid">0.00 EGP</span> · <strong>Общий остаток:</strong> <span id="grand-remaining">0.00 EGP</span></div>
        </div></section>

        @include('dashboard.quick-registration.payment-plan-fields')
        <section class="card shadow-sm mb-4"><div class="card-header fw-bold">5. Оплата</div><div class="card-body row g-3">
            <div class="col-md-4"><label class="form-label">Способ оплаты</label><select name="payment_method" id="payment-method" class="form-select"><option value="">Без оплаты</option><option value="cash" @selected(old('payment_method') === 'cash')>Наличные</option><option value="card" @selected(old('payment_method') === 'card')>Банковская карта</option><option value="bank" @selected(old('payment_method') === 'bank')>Банковский перевод</option><option value="instapay" @selected(old('payment_method') === 'instapay')>InstaPay</option></select></div>
            <div class="col-md-4"><label class="form-label">Касса</label><select name="cash_account_id" id="cash-account" class="form-select"><option value="">Без оплаты</option>@foreach($cashAccounts as $account)<option value="{{ $account->id }}" data-role="{{ $account->role }}" @selected((string) old('cash_account_id') === (string) $account->id)>{{ $account->name }}</option>@endforeach</select><div class="form-text d-none" id="cash-account-auto-hint">Касса определяется автоматически по способу оплаты.</div></div>
            <div class="col-md-4"><label class="form-label">Примечание к оплате</label><input name="payment_note" value="{{ old('payment_note') }}" class="form-control"></div>
        </div></section>

        <div id="service-selection-error" class="text-danger mb-2 d-none">Выберите хотя бы одну финансовую услугу.</div>
        <div id="submit-blocked-error" class="text-danger mb-2 d-none"></div>
        <button class="btn btn-primary btn-lg" @disabled(!$configurationReady)>Подтвердить оплату и завершить регистрацию</button>
    </form>
    </div>
@endif
</div>

@unless($registrationSuccess)
<script>
const cents = value => Math.round((Number(value || 0) + Number.EPSILON) * 100);
const money = value => `${(cents(value) / 100).toFixed(2)} EGP`;
const periodLabels = @json($periodLabels);
// Finance V2 Phase 1 UI — the only genuine calendar-schedule periods;
// every other payment_period value (once/daily/term/package, or blank) is
// a pricing-dimension choice only and always resolves to billing_strategy
// 'once'. Kept in exact sync with FeeBillingPeriod::CALENDAR_PERIODS.
const CALENDAR_PERIOD_VALUES = ['monthly', 'quarterly', 'yearly'];
const stage = document.getElementById('stage');
const grade = document.getElementById('grade');
const schoolClass = document.getElementById('school-class');
const academicYear = document.querySelector('[name="academic_year_id"]');
const enrollmentMode = document.getElementById('enrollment-mode');
const registrationDate = document.querySelector('[name="registration_date"]');
const filterAcademics = () => {
    grade.querySelectorAll('option[data-stage]').forEach(option => option.hidden = option.dataset.stage !== stage.value);
    if (grade.selectedOptions[0]?.hidden) grade.value = '';
    schoolClass.querySelectorAll('option[data-grade]').forEach(option => option.hidden = option.dataset.grade !== grade.value);
    if (schoolClass.selectedOptions[0]?.hidden) schoolClass.value = '';
    grade.options[0].textContent = stage.value ? 'Выберите класс' : 'Сначала выберите ступень.';
    const availableGrades = [...grade.querySelectorAll('option[data-stage]')].some(option => !option.hidden);
    if (stage.value && !availableGrades) grade.options[0].textContent = 'Классы не настроены.';
    schoolClass.options[0].textContent = grade.value ? 'Выберите букву класса' : 'Сначала выберите класс.';
    const availableClasses = [...schoolClass.querySelectorAll('option[data-grade]')].some(option => !option.hidden);
    if (grade.value && !availableClasses) schoolClass.options[0].textContent = 'Классы не настроены.';
};
stage.addEventListener('change', filterAcademics); grade.addEventListener('change', filterAcademics); filterAcademics();

const rows = [...document.querySelectorAll('.service-row')];

// Finance V2 Phase 1 UI — reads whatever the row's own payment_period
// field currently holds (Tuition's/Transport's pre-existing pricing-
// dimension dropdown, or the new Additional-services control) and derives
// this service's billing strategy from it. Never a separate operator
// choice: choosing a genuine calendar period IS choosing 'calendar'.
// Food never resolves through here (see the blade: no billing-strategy
// field is even rendered for a Food row).
function resolvedStrategy(row) {
    const periodField = row.querySelector('[name$="[payment_period]"]');
    return CALENDAR_PERIOD_VALUES.includes(periodField?.value || '') ? 'calendar' : 'once';
}
// Russian label for the live summary (Section 3) — null for Food, whose
// existing coverage/duration summary is left completely untouched.
function billingLabel(row) {
    if (row.dataset.category === 'food') return null;
    if (resolvedStrategy(row) !== 'calendar') return 'Разово';
    const periodField = row.querySelector('[name$="[payment_period]"]');
    return periodLabels[periodField.value] || periodField.value;
}

const paymentMode = document.getElementById('payment-mode');
const paymentPlanWrapper = document.getElementById('payment-plan-wrapper');
const paymentPlanSelect = document.getElementById('payment-plan-id');
const paymentTypeInput = document.getElementById('payment-type-input');
// Custom PaymentPlan (payment_type=plan) never coexists with Food (Food
// has no assignable PaymentPlan and always needs its own duration-mode
// path) — mirrors the pre-existing food-forces-calendar behavior, now
// pointed at the 'auto' (mixed-capable) mode instead of a literal
// payment_type value the operator no longer picks directly.
const planOption = paymentMode?.querySelector('option[value="plan"]');
// Captured once, before this function ever runs: whether the server
// already disabled "план" because no installment plans are configured at
// all (FinanceConfigurationReadinessService) — that reason must persist
// regardless of which services get checked/unchecked afterwards.
const planUnavailableByReadiness = planOption?.disabled ?? false;
function syncPaymentModeAvailability() {
    const foodSelected = rows.some(row => row.dataset.category === 'food' && row.querySelector('.service-toggle').checked);
    if (planOption) planOption.disabled = planUnavailableByReadiness || foodSelected;
    if (foodSelected && paymentMode.value === 'plan') paymentMode.value = 'auto';
    if (paymentPlanWrapper) paymentPlanWrapper.style.display = paymentMode.value === 'plan' ? '' : 'none';
    // Review corrective pass (P0) — a plan chosen, then abandoned by
    // switching back to "auto", must never linger as a live, submittable
    // field: disabled inputs are never sent, so this is what actually
    // stops a stale payment_plan_id from reaching a payment_type=mixed
    // request (rejected server-side, but on a field the hidden wrapper no
    // longer shows — never let that state be reachable at all). The
    // select's value is deliberately left alone: re-enabling it (switching
    // back to "план") should restore what the operator last chose, not
    // force them to re-pick it.
    if (paymentPlanSelect) paymentPlanSelect.disabled = paymentMode.value !== 'plan';
}

// Bug 2: Transport's payment-period options are derived live from this
// fee's own sellable FeePrice rows (data-periods-by-zone, rendered
// server-side) — never hardcoded — and re-filtered to just the selected
// zone every time. Called unconditionally at the top of updateRow() so the
// dropdown is always correct before pricing is requested, regardless of
// which field just changed.
function syncTransportPeriods(row) {
    const zoneSelect = row.querySelector('.transport-zone');
    const periodSelect = row.querySelector('.transport-period');
    if (!zoneSelect || !periodSelect) return;
    const periodsByZone = JSON.parse(zoneSelect.dataset.periodsByZone || '{}');
    const periods = periodsByZone[zoneSelect.value] || [];
    // Preserve an old()-repopulated value (after a validation error) on the
    // very first sync only; afterwards the select's own live value wins.
    const desired = periodSelect.value || periodSelect.dataset.oldValue || '';
    periodSelect.dataset.oldValue = '';
    periodSelect.innerHTML = (zoneSelect.value ? '<option value="">Выберите период оплаты</option>' : '<option value="">Выберите зону</option>')
        + periods.map(p => `<option value="${p}">${periodLabels[p] || p}</option>`).join('');
    if (periods.includes(desired)) periodSelect.value = desired;
}

// Food flexible-duration corrective pass: shows only the input group for
// the currently selected duration mode (day/school_week/teaching_days/
// month/custom_range) and disables the hidden groups' own inputs so they
// never get submitted alongside the visible mode's fields.
function syncFoodDurationMode(row) {
    const modeSelect = row.querySelector('.food-duration-mode');
    if (!modeSelect) return;
    const mode = modeSelect.value;
    row.querySelectorAll('.food-duration-fields').forEach(group => {
        const active = group.dataset.mode === mode;
        group.classList.toggle('d-none', !active);
        group.querySelectorAll('input').forEach(input => input.disabled = !active);
    });
}

// Multi-item Uniform corrective pass — each compact item row's size/qty
// inputs are governed by that row's OWN checkbox, never by the blanket
// Fee-level enable/disable below (which would otherwise re-enable every
// item's fields the instant the Uniform Fee checkbox is checked,
// regardless of which items are actually selected).
function syncUniformItemRow(itemRow) {
    const checked = itemRow.querySelector('.uniform-item-toggle').checked;
    itemRow.querySelector('.uniform-item-size').disabled = !checked;
    itemRow.querySelector('.uniform-item-qty').disabled = !checked;
    if (!checked) {
        itemRow.querySelector('.uniform-item-unit').textContent = '—';
        itemRow.querySelector('.uniform-item-total').textContent = '—';
    }
}

// Resolves each checked item row's own canonical price independently (one
// /price call per selected item — never a client-computed total) and sums
// them into the SAME row.dataset.total/paid/remaining + resolved-total/
// remaining fields every other category already populates, so
// updateSummary() and the submit guard need no changes at all.
async function updateUniformRow(row) {
    const itemRows = [...row.querySelectorAll('.uniform-item-row')];
    itemRows.forEach(syncUniformItemRow);
    const checkedRows = itemRows.filter(itemRow => itemRow.querySelector('.uniform-item-toggle').checked);

    if (!checkedRows.length) {
        row.dataset.pricingAvailable = 'false';
        row.dataset.total = row.dataset.paid = row.dataset.remaining = '0';
        row.querySelector('.resolved-unit').textContent = '—';
        row.querySelector('.resolved-total').textContent = 'Выберите изделие';
        row.querySelector('.remaining').textContent = '—';
        row.querySelector('.tariff-period').textContent = '';
        updateSummary();
        return;
    }

    let totalCents = 0, allOk = true, tariffPeriod = '';
    for (const itemRow of checkedRows) {
        const option = itemRow.querySelector('.uniform-item-size').selectedOptions[0];
        const qty = itemRow.querySelector('.uniform-item-qty').value || 1;
        const body = new FormData();
        body.append('_token', document.querySelector('input[name="_token"]').value);
        body.append('fee_id', row.dataset.feeId);
        body.append('quantity', qty);
        body.append('item', itemRow.dataset.uniformItem);
        body.append('size', option?.dataset.size || '');
        body.append('academic_year_id', document.querySelector('[name="academic_year_id"]').value);
        body.append('enrollment_mode_id', enrollmentMode.value);
        if (registrationDate.value) body.append('registration_date', registrationDate.value);

        let unit = null, lineTotal = null;
        try {
            const response = await fetch('{{ route('dashboard.quick-registration.price') }}', {method: 'POST', body, headers: {'Accept': 'application/json'}});
            if (response.ok) {
                const result = await response.json();
                unit = Number(result.unit_price);
                lineTotal = Number(result.amount);
                if (result.valid_from) {
                    const displayDate = value => value ? new Date(`${value}T00:00:00`).toLocaleDateString('ru-RU') : null;
                    tariffPeriod = `Действует с ${displayDate(result.valid_from)}${result.valid_to ? ` по ${displayDate(result.valid_to)}` : ''}`;
                }
            }
        } catch (e) { /* unit/lineTotal stay null — reported as unresolved below */ }

        if (unit === null || lineTotal === null) {
            allOk = false;
            itemRow.querySelector('.uniform-item-unit').textContent = 'Ошибка';
            itemRow.querySelector('.uniform-item-total').textContent = '—';
            continue;
        }
        itemRow.querySelector('.uniform-item-unit').textContent = money(unit);
        itemRow.querySelector('.uniform-item-total').textContent = money(lineTotal);
        totalCents += cents(lineTotal);
    }

    row.querySelector('.tariff-period').textContent = tariffPeriod;
    if (!allOk) {
        row.dataset.pricingAvailable = 'false';
        row.dataset.total = row.dataset.paid = row.dataset.remaining = '0';
        row.querySelector('.resolved-unit').textContent = '—';
        row.querySelector('.resolved-total').textContent = 'Не удалось рассчитать стоимость.';
        row.querySelector('.remaining').textContent = '—';
        updateSummary();
        return;
    }

    row.dataset.pricingAvailable = 'true';
    const paidInput = row.querySelector('.paid-now');
    const paid = Number(paidInput?.value || 0);
    const overpaid = cents(paid) > totalCents;
    paidInput?.classList.toggle('is-invalid', overpaid);
    const remaining = Math.max((totalCents - cents(paid)) / 100, 0);
    row.dataset.total = (totalCents / 100).toFixed(2);
    row.dataset.paid = (cents(paid) / 100).toFixed(2);
    row.dataset.remaining = remaining.toFixed(2);
    row.querySelector('.resolved-unit').textContent = '—';
    row.querySelector('.resolved-total').textContent = money(totalCents / 100);
    row.querySelector('.remaining').textContent = money(remaining);
    updateSummary();
}

async function updateRow(row) {
    syncTransportPeriods(row);
    syncFoodDurationMode(row);
    const selected = row.querySelector('.service-toggle').checked;
    const fields = row.querySelector('.service-fields');
    fields.classList.toggle('d-none', !selected);
    fields.querySelectorAll('input, select').forEach(field => {
        if (field.matches('.uniform-item-size, .uniform-item-qty')) return; // governed by syncUniformItemRow instead
        field.disabled = !selected;
    });
    if (!selected) {
        fields.querySelectorAll('.uniform-item-size, .uniform-item-qty').forEach(field => field.disabled = true);
        row.dataset.total = row.dataset.paid = row.dataset.remaining = '0';
        updateSummary();
        return;
    }
    if (row.dataset.category === 'uniform') { await updateUniformRow(row); return; }

    const body = new FormData();
    body.append('_token', document.querySelector('input[name="_token"]').value);
    body.append('fee_id', row.dataset.feeId);
    body.append('quantity', row.querySelector('.quantity')?.value || 1);
    body.append('academic_year_id', document.querySelector('[name="academic_year_id"]').value);
    body.append('enrollment_mode_id', enrollmentMode.value);
    if (registrationDate.value) body.append('registration_date', registrationDate.value);
    if (grade.value) body.append('grade_id', grade.value);
    ['grade_group', 'payment_period', 'transport_area'].forEach(name => { const input = row.querySelector(`[name$="[${name}]"]`); if (input?.value) body.append(name, input.value); });
    const firstLast = row.querySelector('[name$="[first_last_month]"]'); if (firstLast?.checked) body.append('first_last_month', '1');
    const mealPlan = row.querySelector('[name$="[meal_plan_id]"]'); if (mealPlan?.value) body.append('meal_plan_id', mealPlan.value);
    const foodMode = row.querySelector('.food-duration-mode');
    if (foodMode?.value) {
        body.append('food_duration_mode', foodMode.value);
        row.querySelectorAll(`.food-field[data-food-mode="${foodMode.value}"]`).forEach(input => {
            if (input.value) body.append(input.name.match(/\[([a-z_]+)\]$/)[1], input.value);
        });
    }

    let unit = null, total = null, errorMessage = 'Тариф не настроен.';
    const response = await fetch('{{ route('dashboard.quick-registration.price') }}', {method: 'POST', body, headers: {'Accept': 'application/json'}});
    let tariffPeriod = '';
    if (response.ok) {
        const result = await response.json(); unit = Number(result.unit_price); total = Number(result.amount);
        const displayDate = value => value ? new Date(`${value}T00:00:00`).toLocaleDateString('ru-RU') : null;
        if (result.valid_from) tariffPeriod = `Действует с ${displayDate(result.valid_from)}${result.valid_to ? ` по ${displayDate(result.valid_to)}` : ''}`;
    } else if (response.status === 422) {
        // Surface InvoiceCalculationService's own validation message
        // (e.g. "Для услуги «Транспорт» выберите все параметры тарифа.")
        // instead of a generic string, so the reason is actionable. Never
        // display anything from a non-422 response — that could be a
        // framework/server error message, not a validation reason.
        try {
            const problem = await response.json();
            const firstError = Object.values(problem.errors || {})[0]?.[0];
            if (firstError) errorMessage = firstError;
        } catch (e) { /* keep the generic fallback */ }
    } else {
        errorMessage = 'Не удалось рассчитать тариф. Попробуйте ещё раз.';
    }
    if (unit === null || total === null) {
        row.querySelector('.resolved-unit').textContent = errorMessage;
        row.querySelector('.resolved-total').textContent = '—';
        row.querySelector('.remaining').textContent = '—';
        row.querySelector('.tariff-period').textContent = errorMessage;
        row.dataset.pricingAvailable = 'false';
        row.dataset.total = row.dataset.paid = row.dataset.remaining = '0';
        updateSummary();
        return;
    }
    row.dataset.pricingAvailable = 'true';
    row.querySelector('.tariff-period').textContent = tariffPeriod;
    const paidInput = row.querySelector('.paid-now');
    const paid = Number(paidInput?.value || 0);
    const overpaid = cents(paid) > cents(total);
    paidInput?.classList.toggle('is-invalid', overpaid);
    const remaining = Math.max((cents(total) - cents(paid)) / 100, 0);
    row.dataset.total = (cents(total) / 100).toFixed(2); row.dataset.paid = (cents(paid) / 100).toFixed(2); row.dataset.remaining = remaining.toFixed(2);
    row.querySelector('.resolved-unit').textContent = money(unit); row.querySelector('.resolved-total').textContent = money(total); row.querySelector('.remaining').textContent = money(remaining);
    updateSummary();
}
function updateSummary() {
    const tbody = document.querySelector('#live-summary tbody'); tbody.innerHTML = '';
    let total = 0, paid = 0, remaining = 0;
    rows.filter(row => row.querySelector('.service-toggle').checked).forEach(row => {
        total += cents(row.dataset.total); paid += cents(row.dataset.paid); remaining += cents(row.dataset.remaining);
        const summaryRow = document.createElement('tr');
        const label = billingLabel(row);
        const name = label ? `${row.dataset.name} — ${label}` : row.dataset.name;
        [name, money(row.dataset.total), money(row.dataset.paid), money(row.dataset.remaining)].forEach(value => {
            const cell = document.createElement('td'); cell.textContent = value; summaryRow.appendChild(cell);
        });
        tbody.appendChild(summaryRow);
    });
    if (!tbody.children.length) tbody.innerHTML = '<tr><td colspan="4" class="text-muted">Услуги не выбраны.</td></tr>';
    [['summary-total', total], ['summary-paid', paid], ['summary-remaining', remaining], ['grand-total', total], ['grand-paid', paid], ['grand-remaining', remaining]].forEach(([id, value]) => document.getElementById(id).textContent = money(value / 100));
}
rows.forEach(row => { row.querySelectorAll('input, select').forEach(input => { input.addEventListener('change', () => { updateRow(row); syncPaymentModeAvailability(); }); input.addEventListener('input', () => updateRow(row)); }); });
if (paymentMode) { paymentMode.addEventListener('change', syncPaymentModeAvailability); syncPaymentModeAvailability(); }
// Bug 1: some browsers (confirmed: Safari) restore a checkbox's checked
// state — e.g. from bfcache navigation, or native form-autofill — AFTER
// this script's top-level code has already run, and never dispatch a
// 'change' event when doing so. Reading `.checked` synchronously at parse
// time can therefore see a not-yet-restored, unchecked snapshot even for a
// row the browser is about to show as checked, leaving its price stuck on
// the static "0.00 EGP" placeholder until the user manually toggles it.
// 'pageshow' fires after that restoration completes on every navigation —
// including a plain first load — so re-reading `.checked` there is the
// robust point to resolve pricing for whatever is actually checked.
window.addEventListener('pageshow', () => { rows.forEach(updateRow); syncPaymentModeAvailability(); });
grade.addEventListener('change', () => rows.filter(row => row.querySelector('.service-toggle').checked).forEach(updateRow));
[academicYear, schoolClass, enrollmentMode, registrationDate].forEach(input => input.addEventListener('change', () => rows.filter(row => row.querySelector('.service-toggle').checked).forEach(updateRow)));
const paymentMethod = document.getElementById('payment-method');
const cashAccount = document.getElementById('cash-account');
const cashAccountHint = document.getElementById('cash-account-auto-hint');
const methodToRole = {cash: 'operating', bank: 'bank', instapay: 'instapay'};
// The server never trusts cash_account_id for cash/bank/instapay — it always
// resolves the canonical account by role (CashAccount::resolvePaymentAccountId).
// This select must show that same resolved account instead of leaving its
// stale "Без оплаты" placeholder visible while disabled, which previously
// made a real cash payment look like no cash account would be charged.
function syncCashAccountField() {
    const role = methodToRole[paymentMethod.value];
    cashAccount.disabled = !!role;
    if (!role) {
        cashAccountHint.classList.add('d-none');
        return;
    }
    const matchingOption = [...cashAccount.options].find(option => option.dataset.role === role);
    cashAccountHint.classList.remove('d-none');
    if (matchingOption) {
        cashAccount.value = matchingOption.value;
        cashAccountHint.textContent = `Касса определяется автоматически: ${matchingOption.textContent}`;
        cashAccountHint.classList.remove('text-danger');
    } else {
        cashAccount.value = '';
        cashAccountHint.textContent = 'Для этого способа оплаты касса не настроена — обратитесь к администратору.';
        cashAccountHint.classList.add('text-danger');
    }
}
paymentMethod.addEventListener('change', syncCashAccountField);
syncCashAccountField();

const modeTabs = [...document.querySelectorAll('[data-mode-tab]')];
const newPanel = document.getElementById('new-student-panel');
const existingPanel = document.getElementById('existing-student-panel');
modeTabs.forEach(tab => tab.addEventListener('click', () => {
    const existing = tab.dataset.modeTab === 'existing';
    modeTabs.forEach(other => { other.classList.toggle('active', other === tab); other.setAttribute('aria-selected', other === tab ? 'true' : 'false'); });
    newPanel.classList.toggle('d-none', existing);
    existingPanel.classList.toggle('d-none', !existing);
}));

// Bug 3: blocking submission with only event.preventDefault() — as this
// used to do for the "unresolved price" and "overpaid" cases — produces
// exactly the silent "nothing happens" click the employee saw: no request,
// no navigation, no visible message. Every blocked-submit path below must
// now show a specific Russian message and bring the offending row into view.
const submitBlockedError = document.getElementById('submit-blocked-error');
if (paymentPlanSelect) paymentPlanSelect.addEventListener('change', () => paymentPlanSelect.classList.remove('is-invalid'));
document.getElementById('quick-registration-form').addEventListener('submit', event => {
    const noneSelected = !rows.some(row => row.querySelector('.service-toggle').checked);
    document.getElementById('service-selection-error').classList.toggle('d-none', !noneSelected);

    rows.forEach(row => row.classList.remove('border-danger'));

    // Finance V2 Phase 1 UI — resolve every checked, non-Food row's own
    // billing_strategy from its current payment_period value, and derive
    // the single top-level payment_type from those resolutions. Recomputed
    // fresh on every submit attempt, so a field the operator just fixed is
    // always reflected — never a stale value from an earlier attempt.
    const checkedRows = rows.filter(row => row.querySelector('.service-toggle').checked);
    const planSelected = paymentMode && paymentMode.value === 'plan';
    const foodSelectedNow = checkedRows.some(row => row.dataset.category === 'food');
    let anyCalendarSelected = false;
    checkedRows.forEach(row => {
        const strategyField = row.querySelector('.billing-strategy-field');
        if (!strategyField) return; // Food never carries this field.
        // Custom PaymentPlan mode never coexists with a per-service
        // calendar strategy — the backend rejects billing_strategy=
        // 'calendar' outside payment_type=mixed, so force 'once' here
        // defensively rather than ever letting a stale 'calendar' value
        // reach the server under 'plan'.
        const strategy = planSelected ? 'once' : resolvedStrategy(row);
        strategyField.value = strategy;
        if (strategy === 'calendar') anyCalendarSelected = true;
    });
    if (paymentTypeInput) {
        paymentTypeInput.value = planSelected ? 'plan' : ((foodSelectedNow || anyCalendarSelected) ? 'mixed' : 'one_time');
    }
    // A multi-option "Порядок оплаты" control (Additional services) left
    // on its blank placeholder — mandatory once shown, never silently
    // defaulted to 'once'.
    const emptyRequiredPeriodRow = !planSelected && checkedRows.find(row => {
        const field = row.querySelector('.requires-period');
        return field && !field.value;
    });

    const overpaidRow = rows.find(row => row.querySelector('.service-toggle').checked && row.querySelector('.paid-now')?.classList.contains('is-invalid'));
    const unavailableRow = rows.find(row => row.querySelector('.service-toggle').checked && row.dataset.pricingAvailable !== 'true');
    const blockedRow = overpaidRow || unavailableRow || emptyRequiredPeriodRow;

    // amount > 0 must never post with the payment account still unresolved —
    // mirrors the "Касса = Без оплаты" audit finding: block here instead of
    // letting InvoicePaymentService reject it after the invoice already exists.
    const totalPaidNow = checkedRows.reduce((sum, row) => sum + cents(row.dataset.paid), 0);
    const unresolvedCashAccount = totalPaidNow > 0 && cashAccount.disabled && cashAccountHint.classList.contains('text-danger');
    const planRequiredButMissing = planSelected && paymentPlanSelect && !paymentPlanSelect.value;
    // Custom PaymentPlan is explicitly out of scope for Food (Food never
    // has an assignable plan and always needs its own duration-mode
    // path) — syncPaymentModeAvailability() already disables "план" the
    // moment Food is checked, this is a submit-time defense-in-depth
    // guard against a state that should never be reachable through the UI.
    const planConflictsWithFood = planSelected && foodSelectedNow;

    if (!noneSelected && !blockedRow && !unresolvedCashAccount && !planRequiredButMissing && !planConflictsWithFood) {
        submitBlockedError.classList.add('d-none');
        return;
    }

    event.preventDefault();
    if (blockedRow) {
        blockedRow.classList.add('border-danger');
        submitBlockedError.textContent = overpaidRow
            ? 'Оплаченная сумма превышает стоимость услуги — исправьте выделенную строку ниже.'
            : (emptyRequiredPeriodRow
                ? 'Для одной из выбранных услуг нужно выбрать порядок оплаты — заполните обязательное поле в выделенной строке ниже.'
                : 'Для одной из выбранных услуг не удалось рассчитать стоимость — заполните все обязательные поля в выделенной строке ниже.');
        submitBlockedError.classList.remove('d-none');
        blockedRow.scrollIntoView({behavior: 'smooth', block: 'center'});
    } else if (planConflictsWithFood) {
        submitBlockedError.textContent = 'Питание нельзя совместить с индивидуальным планом рассрочки — выберите автоматический порядок оплаты в разделе 4.';
        submitBlockedError.classList.remove('d-none');
        paymentMode.scrollIntoView({behavior: 'smooth', block: 'center'});
    } else if (planRequiredButMissing) {
        paymentPlanSelect.classList.add('is-invalid');
        submitBlockedError.textContent = 'Выберите план оплаты рассрочки.';
        submitBlockedError.classList.remove('d-none');
        paymentPlanSelect.scrollIntoView({behavior: 'smooth', block: 'center'});
    } else if (unresolvedCashAccount) {
        submitBlockedError.textContent = 'Для выбранного способа оплаты не настроена касса — оплату принять нельзя. Обратитесь к администратору.';
        submitBlockedError.classList.remove('d-none');
        cashAccountHint.scrollIntoView({behavior: 'smooth', block: 'center'});
    } else if (!noneSelected) {
        submitBlockedError.classList.add('d-none');
    }
});
</script>
@endunless
@endsection
