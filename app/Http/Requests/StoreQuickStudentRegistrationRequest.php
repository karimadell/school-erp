<?php

namespace App\Http\Requests;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Grade;
use App\Models\SchoolClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreQuickStudentRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage invoices') === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'student_last_name_ru' => \App\Models\Student::normalizeRussianNamePart($this->input('student_last_name_ru')),
            'student_first_name_ru' => \App\Models\Student::normalizeRussianNamePart($this->input('student_first_name_ru')),
            'student_patronymic_ru' => \App\Models\Student::normalizeRussianNamePart($this->input('student_patronymic_ru')),
        ]);
        $services = collect($this->input('services', []))->map(function ($service) {
            $service = is_array($service) ? $service : [];
            $service['quantity'] ??= 1;
            $service['paid_now'] ??= '0.00';

            return $service;
        })->values()->all();

        $this->merge(['services' => $services, 'payment_type' => $this->input('payment_type', 'one_time')]);
    }

    public function rules(): array
    {
        return [
            'student_last_name_ru' => ['required', 'string', 'max:100'],
            'student_first_name_ru' => ['required', 'string', 'max:100'],
            'student_patronymic_ru' => ['nullable', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9\s\-()]{7,20}$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'stage_id' => ['required', 'integer', 'exists:stages,id'],
            'grade_id' => ['required', 'integer', 'exists:grades,id'],
            'class_id' => ['required', 'integer', 'exists:classes,id'],
            'enrollment_mode_id' => ['required', 'integer', 'exists:enrollment_modes,id'],
            'registration_date' => ['required', 'date'],
            'services' => ['required', 'array', 'min:1'],
            'services.*.fee_id' => ['required', 'integer', 'distinct', 'exists:fees,id'],
            'services.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'services.*.paid_now' => ['required', 'decimal:0,2', 'min:0'],
            'services.*.item' => ['nullable', 'string', 'max:100'],
            'services.*.size' => ['nullable', 'string', 'max:50'],
            'services.*.uniform_product_id' => ['nullable', 'integer', 'exists:uniform_products,id'],
            'services.*.grade_group' => ['nullable', Rule::in(FeePrice::GRADE_GROUPS)],
            'services.*.payment_period' => ['nullable', Rule::in(['once', 'daily', 'monthly', 'quarterly', 'term', 'yearly', 'package'])],
            'services.*.first_last_month' => ['nullable', 'boolean'],
            'services.*.transport_area' => ['nullable', 'string', 'max:150'],
            'services.*.transport_route_id' => ['nullable', 'integer', 'exists:transport_routes,id'],
            'services.*.transport_stop' => ['nullable', 'string', 'max:150'],
            'services.*.meal_plan_id' => ['nullable', 'integer', 'exists:meal_plans,id'],
            // Food flexible-duration corrective pass: replaces the old
            // month-only coverage_start_month/coverage_end_month pair.
            // Exactly one mode's fields are required per Food line —
            // enforced in after() below, since which fields are required
            // depends on food_duration_mode.
            'services.*.food_duration_mode' => ['nullable', 'string', Rule::in(['day', 'school_week', 'teaching_days', 'month', 'custom_range'])],
            'services.*.food_date' => ['nullable', 'date_format:Y-m-d'],
            'services.*.food_week_start' => ['nullable', 'date_format:Y-m-d'],
            'services.*.food_start_date' => ['nullable', 'date_format:Y-m-d'],
            'services.*.food_day_count' => ['nullable', 'integer', 'min:1'],
            'services.*.food_month' => ['nullable', 'date_format:Y-m'],
            'services.*.food_end_month' => ['nullable', 'date_format:Y-m'],
            'services.*.food_range_start' => ['nullable', 'date_format:Y-m-d'],
            'services.*.food_range_end' => ['nullable', 'date_format:Y-m-d'],
            'cash_account_id' => ['nullable', 'integer', 'exists:cash_accounts,id'],
            'payment_method' => ['nullable', Rule::in(['cash', 'card', 'bank', 'transfer', 'instapay'])],
            'payment_note' => ['nullable', 'string', 'max:1000'],
            'payment_type' => ['required', Rule::in(['one_time', 'plan', 'calendar'])],
            'payment_plan_id' => ['nullable', 'required_if:payment_type,plan', 'integer', 'exists:payment_plans,id'],
            // Finance V2, Phase 2B — service-aware billing schedules.
            // Food flexible-duration corrective pass: no longer
            // unconditionally required_if calendar — a Food-only
            // submission never needs a billing_period at all (Food is
            // resolved from its own duration-mode selection, never from
            // CalendarPeriodCalculator's month/quarter grouping); the
            // conditional "required only when a non-Food service is also
            // present" rule lives in after() below, where the service list
            // is actually known.
            'billing_period' => ['nullable', Rule::in(\App\Models\FeeBillingPeriod::CALENDAR_PERIODS)],
            // Finance V2, Phase 2B corrective pass (review finding M3): a
            // stable per-page-render token used to derive deterministic
            // installment-payment idempotency keys, so a retried submission
            // of the same rendered form cannot create duplicate payments.
            // Optional — a caller that never supplies one (e.g. a
            // non-browser API consumer) falls back to today's behavior
            // (QuickStudentRegistrationService generates one itself).
            'idempotency_token' => ['nullable', 'string', 'max:100'],
            'invoice_number' => ['prohibited'],
            'currency' => ['prohibited'],
            'subtotal_amount' => ['prohibited'],
            'total_amount' => ['prohibited'],
            'paid_amount' => ['prohibited'],
            'remaining_amount' => ['prohibited'],
            'status' => ['prohibited'],
            'price' => ['prohibited'],
            'unit_price' => ['prohibited'],
            'line_total' => ['prohibited'],
            'paid_total' => ['prohibited'],
            'services.*.price' => ['prohibited'],
            'services.*.unit_price' => ['prohibited'],
            'services.*.line_total' => ['prohibited'],
            'services.*.remaining_amount' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! AcademicYear::where('is_active', true)->exists()) {
                $validator->errors()->add('academic_year_id', 'Нет активного учебного года.');
            }
            $year = AcademicYear::find($this->integer('academic_year_id'));
            if ($year && ! $year->is_active) {
                $validator->errors()->add('academic_year_id', 'Для быстрого оформления можно выбрать только активный учебный год.');
            }
            if ($year && $this->filled('registration_date')) {
                $date = Carbon::parse($this->input('registration_date'));
                if ($date->gt($year->end_date)) {
                    $validator->errors()->add('registration_date', 'Дата регистрации не может быть позже окончания выбранного учебного года.');
                }
            }

            if (! EnrollmentMode::where('is_active', true)->exists()) {
                $validator->errors()->add('enrollment_mode_id', 'Формы обучения не настроены.');
            }
            $mode = EnrollmentMode::find($this->integer('enrollment_mode_id'));
            if ($mode && ! $mode->is_active) {
                $validator->errors()->add('enrollment_mode_id', 'Выбранная форма обучения не активна.');
            }

            $grade = Grade::find($this->integer('grade_id'));
            if ($grade && $grade->stage_id !== $this->integer('stage_id')) {
                $validator->errors()->add('grade_id', 'Выбранный класс не относится к указанной ступени.');
            }
            $class = SchoolClass::find($this->integer('class_id'));
            if ($class && $class->grade_id !== $this->integer('grade_id')) {
                $validator->errors()->add('class_id', 'Выбранный класс не относится к указанной параллели.');
            }
            if ($class && ! $class->is_active) {
                $validator->errors()->add('class_id', 'Выбранный класс не активен.');
            }

            $services = collect($this->input('services', []));
            $fees = Fee::whereIn('id', $services->pluck('fee_id'))->get()->keyBy('id');
            if ($services->filter(fn ($item) => $fees->get((int) ($item['fee_id'] ?? 0))?->category === Fee::CATEGORY_REGISTRATION)->count() > 1) {
                $validator->errors()->add('services', 'Регистрационный взнос можно добавить только один раз за учебный год.');
            }

            foreach ($services as $index => $item) {
                $category = $fees->get((int) ($item['fee_id'] ?? 0))?->category;
                if ($category === Fee::CATEGORY_UNIFORM && blank($item['uniform_product_id'] ?? null)) {
                    $validator->errors()->add("services.{$index}.uniform_product_id", 'Для школьной формы выберите изделие и размер.');
                }
                if ($category === Fee::CATEGORY_TRANSPORT) {
                    if (blank($item['transport_area'] ?? null) || blank($item['transport_route_id'] ?? null)) {
                        $validator->errors()->add("services.{$index}.transport_area", 'Для транспорта укажите район и маршрут.');
                    }
                    // Mirrors InvoiceCalculationService::resolvePrice()'s own
                    // conditional requirement exactly (Bug 2): payment_period
                    // is only mandatory when this fee actually has at least
                    // one dimensioned-by-period tariff — never a blanket
                    // requirement, and never re-deriving a different rule.
                    $fee = $fees->get((int) ($item['fee_id'] ?? 0));
                    if ($fee && blank($item['payment_period'] ?? null) && FeePrice::where('fee_id', $fee->id)->whereNotNull('payment_period')->exists()) {
                        $validator->errors()->add("services.{$index}.payment_period", 'Для транспорта выберите период оплаты.');
                    }
                }
                if ($category === Fee::CATEGORY_FOOD && blank($item['meal_plan_id'] ?? null)) {
                    $validator->errors()->add("services.{$index}.meal_plan_id", 'Для питания выберите план питания.');
                }
                // Food flexible-duration corrective pass: replaces the old
                // month-only coverage_start_month/coverage_end_month check.
                // Питание still requires an explicit service period
                // ('calendar' payment_type), but the period itself is now
                // one of five duration modes, each with its own required
                // fields — never forced through billing_period='monthly'.
                if ($category === Fee::CATEGORY_FOOD) {
                    if ($this->input('payment_type') !== 'calendar') {
                        $validator->errors()->add('payment_type', 'Питание оформляется только с явным периодом обслуживания.');
                    }
                    $mode = $item['food_duration_mode'] ?? null;
                    if (! in_array($mode, ['day', 'school_week', 'teaching_days', 'month', 'custom_range'], true)) {
                        $validator->errors()->add("services.{$index}.food_duration_mode", 'Выберите режим периода питания.');
                    } else {
                        $requiredFields = match ($mode) {
                            'day' => ['food_date'],
                            'school_week' => ['food_week_start'],
                            'teaching_days' => ['food_start_date', 'food_day_count'],
                            'month' => ['food_month'],
                            'custom_range' => ['food_range_start', 'food_range_end'],
                        };
                        foreach ($requiredFields as $field) {
                            if (blank($item[$field] ?? null)) {
                                $validator->errors()->add("services.{$index}.{$field}", 'Заполните обязательное поле периода питания.');
                            }
                        }
                        if ($mode === 'custom_range' && filled($item['food_range_start'] ?? null) && filled($item['food_range_end'] ?? null)
                            && Carbon::parse($item['food_range_end'])->lt(Carbon::parse($item['food_range_start']))) {
                            $validator->errors()->add("services.{$index}.food_range_end", 'Дата окончания периода питания не может быть раньше даты начала.');
                        }
                        if ($mode === 'month' && $year && filled($item['food_month'] ?? null) && preg_match('/^\d{4}-\d{2}$/', (string) $item['food_month'])) {
                            $endMonth = $item['food_end_month'] ?? $item['food_month'];
                            if (preg_match('/^\d{4}-\d{2}$/', (string) $endMonth)) {
                                $start = Carbon::createFromFormat('Y-m', $item['food_month'])->startOfMonth();
                                $end = Carbon::createFromFormat('Y-m', $endMonth)->endOfMonth();
                                if ($end->lt($start) || $start->lt($year->start_date) || $end->gt($year->end_date)) {
                                    $validator->errors()->add("services.{$index}.food_end_month", 'Период питания должен находиться внутри выбранного учебного года.');
                                }
                            }
                        }
                    }
                }
            }

            // Finance V2, Phase 2B — service-aware billing schedules: the
            // chosen payment_type/billing_period/payment_plan_id must be
            // valid for EVERY selected service's Fee, never assumed valid
            // globally. This is what blocks e.g. Registration (which only
            // ever allows 'once') from being swept into a monthly/quarterly
            // schedule when bundled with Tuition in the same submission.
            $paymentType = $this->input('payment_type');
            if ($paymentType === 'calendar') {
                $billingPeriod = $this->input('billing_period');
                // Food flexible-duration corrective pass: billing_period is
                // required, and validated against allowsBillingPeriod(),
                // only for a non-Food service — Food never uses it at all.
                $hasNonFoodService = $services->contains(fn ($item) => $fees->get((int) ($item['fee_id'] ?? 0))?->category !== Fee::CATEGORY_FOOD);
                if ($hasNonFoodService && blank($billingPeriod)) {
                    $validator->errors()->add('billing_period', 'Укажите период оплаты.');
                }
                foreach ($services as $index => $item) {
                    $fee = $fees->get((int) ($item['fee_id'] ?? 0));
                    if ($fee && $fee->category !== Fee::CATEGORY_FOOD && $billingPeriod && ! $fee->allowsBillingPeriod($billingPeriod)) {
                        $periodLabel = \App\Models\FeeBillingPeriod::PERIOD_LABELS[$billingPeriod] ?? $billingPeriod;
                        $validator->errors()->add('billing_period', "Услуга «{$fee->name_ru}» не поддерживает период оплаты «{$periodLabel}».");
                    }
                }
            } elseif ($paymentType === 'plan') {
                $planId = $this->input('payment_plan_id');
                foreach ($services as $index => $item) {
                    $fee = $fees->get((int) ($item['fee_id'] ?? 0));
                    if ($fee && $planId && (
                        ! $fee->allowsBillingPeriod(\App\Models\FeeBillingPeriod::PERIOD_CUSTOM_PLAN)
                        || ! $fee->assignedPaymentPlans()->where('payment_plans.id', $planId)->exists()
                    )) {
                        $validator->errors()->add('payment_plan_id', "Выбранный план оплаты не назначен для услуги «{$fee->name_ru}».");
                    }
                }
            }

            $paid = $services->reduce(function (string $sum, array $item): string {
                $value = (string) ($item['paid_now'] ?? '0');

                return preg_match('/^\d+(?:\.\d{1,2})?$/', $value) ? bcadd($sum, $value, 2) : $sum;
            }, '0.00');
            if (bccomp($paid, '0.00', 2) > 0) {
                if (! $this->filled('payment_method')) {
                    $validator->errors()->add('payment_method', 'Для оплаты выберите способ оплаты.');
                }
                // cash/bank/instapay resolve to their canonical account
                // server-side (CashAccount::resolvePaymentAccountId) and
                // never consult cash_account_id — only methods without a
                // canonical mapping (card/transfer today) still need it.
                if (CashAccount::canonicalRoleForMethod((string) $this->input('payment_method')) === null) {
                    if (! $this->filled('cash_account_id')) {
                        $validator->errors()->add('cash_account_id', 'Для оплаты выберите кассу.');
                    }
                    $cashAccount = CashAccount::find($this->integer('cash_account_id'));
                    if ($cashAccount && ! $cashAccount->is_active) {
                        $validator->errors()->add('cash_account_id', 'Выбранная касса неактивна.');
                    }
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'student_last_name_ru.required' => 'Укажите фамилию ученика.',
            'student_first_name_ru.required' => 'Укажите имя ученика.',
            'phone.required' => 'Укажите номер телефона.',
            'phone.regex' => 'Укажите корректный номер телефона.',
            'academic_year_id.required' => 'Выберите учебный год.',
            'stage_id.required' => 'Выберите ступень.',
            'grade_id.required' => 'Выберите класс.',
            'class_id.required' => 'Выберите учебную группу.',
            'enrollment_mode_id.required' => 'Выберите форму обучения.',
            'registration_date.required' => 'Укажите дату регистрации.',
            'services.required' => 'Выберите хотя бы одну финансовую услугу.',
            'services.min' => 'Выберите хотя бы одну финансовую услугу.',
            'services.*.fee_id.distinct' => 'Одну услугу нельзя добавить в счёт дважды.',
            'services.*.quantity.min' => 'Количество должно быть не меньше 1.',
            'services.*.paid_now.min' => 'Оплата по услуге не может быть отрицательной.',
        ];
    }
}
