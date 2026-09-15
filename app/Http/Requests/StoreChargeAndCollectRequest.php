<?php

namespace App\Http\Requests;

use App\Models\CashAccount;
use App\Models\Fee;
use App\Models\FeePrice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Phase 2 — cashier charge & collect. Validates a single-service charge plus
 * an optional collection, and shapes the payload into the canonical issuance
 * format consumed by InvoiceIssuanceService::issue().
 *
 * Existing-student Food purchase corrective pass: a Food Fee is shaped and
 * priced through the exact same duration-mode selection (day/school_week/
 * teaching_days/custom_range) Quick Registration already uses — never
 * payment_type=one_time (Food requires an explicit service period, see
 * InvoiceIssuanceService::issue()'s own guard) and never a second pricing
 * engine. Every other category's shape is completely unchanged.
 */
class StoreChargeAndCollectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage invoices') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $student = $this->route('student');
        $feeId = (int) $this->input('fee_id');
        // Single lookup, reused for both the payment_type decision below and
        // the item shape — never re-derived by ChargeAndCollectService,
        // which instead reads the '_fee_category' this method stamps onto
        // the item (see that service's own docblock).
        $fee = $feeId > 0 ? Fee::find($feeId) : null;
        $isFood = $fee?->category === Fee::CATEGORY_FOOD;

        $item = $isFood ? [
            'fee_id' => $feeId,
            'quantity' => 1,
            'grade_group' => null,
            // Food is always priced daily — mirrors QuickStudentRegistrationController::price()
            // and QuickStudentRegistrationService's own Food branch exactly.
            'payment_period' => Fee::PERIOD_DAILY,
            'first_last_month' => false,
            'size' => null,
            'item' => null,
            'option_type' => 'meal_plan',
            'option_value' => $this->filled('meal_plan_id') ? (string) $this->input('meal_plan_id') : null,
            'meal_plan_id' => $this->input('meal_plan_id'),
            'food_duration_mode' => $this->input('food_duration_mode'),
            'food_date' => $this->input('food_date'),
            'food_week_start' => $this->input('food_week_start'),
            'food_start_date' => $this->input('food_start_date'),
            'food_day_count' => $this->input('food_day_count'),
            'food_range_start' => $this->input('food_range_start'),
            'food_range_end' => $this->input('food_range_end'),
            '_fee_category' => Fee::CATEGORY_FOOD,
        ] : [
            'fee_id' => $feeId,
            'quantity' => (int) $this->input('quantity', 1),
            'grade_group' => $this->input('grade_group'),
            'payment_period' => $this->input('payment_period'),
            'first_last_month' => $this->input('first_last_month') === '1',
            'size' => $this->input('size'),
            'item' => $this->input('item'),
            'option_type' => $this->input('option_type'),
            'option_value' => $this->input('option_value'),
            '_fee_category' => $fee?->category,
        ];

        $this->merge([
            'student_id' => $student?->id,
            'pricing_date' => $this->input('pricing_date', now()->toDateString()),
            // Food requires an explicit service period — 'one_time' cannot
            // express it (InvoiceIssuanceService::issue() rejects it
            // outright). Every other category keeps the unchanged 'one_time'
            // single-installment path.
            'payment_type' => $isFood ? 'calendar' : 'one_time',
            'collect_amount' => $this->input('collect_amount', '0'),
            'notes' => $this->input('notes'),
            // Food's billable day count is always system-computed from the
            // academic calendar — a submitted quantity other than 1 would be
            // rejected by InvoiceCalculationService anyway (see
            // priceFoodDailyLine()'s own guard), so it is never taken from
            // client input for Food.
            'quantity' => $isFood ? 1 : (int) $this->input('quantity', 1),
            'items' => [$item],
        ]);
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'fee_id' => ['required', 'integer', 'exists:fees,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'due_date' => ['required', 'date'],
            'pricing_date' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.fee_id' => ['required', 'integer'],
            'items.*.grade_group' => ['nullable', Rule::in(FeePrice::GRADE_GROUPS)],
            'items.*.payment_period' => ['nullable', 'string', 'max:50'],
            'items.*.first_last_month' => ['boolean'],
            'items.*.size' => ['nullable', 'string', 'max:100'],
            'items.*.item' => ['nullable', 'string', 'max:100'],
            'items.*.option_type' => ['nullable', 'string', 'max:100'],
            'items.*.option_value' => ['nullable', 'string', 'max:255'],
            // Internal marker StoreChargeAndCollectRequest itself stamps onto
            // the item (never client input) so ChargeAndCollectService can
            // branch on category without re-querying Fee — must be listed
            // here or FormRequest::validated() strips it from the array.
            'items.*._fee_category' => ['nullable', 'string'],

            // Existing-student Food purchase corrective pass — the same
            // duration-mode fields StoreQuickStudentRegistrationRequest
            // accepts, minus 'month' (never offered by this NEW screen,
            // matching PR #49's Food date-range UX corrective pass, which
            // already removed it from Quick Registration's own UI). Listed
            // BOTH flat (what the form actually submits) and under items.*
            // (what prepareForValidation() copies them into, and what
            // FormRequest::validated() actually returns to the service —
            // an unlisted items.* key is silently stripped).
            'meal_plan_id' => ['nullable', 'integer', 'exists:meal_plans,id'],
            'food_duration_mode' => ['nullable', 'string', Rule::in(['day', 'school_week', 'teaching_days', 'custom_range'])],
            'food_date' => ['nullable', 'date_format:Y-m-d'],
            'food_week_start' => ['nullable', 'date_format:Y-m-d'],
            'food_start_date' => ['nullable', 'date_format:Y-m-d'],
            'food_day_count' => ['nullable', 'integer', 'min:1'],
            'food_range_start' => ['nullable', 'date_format:Y-m-d'],
            'food_range_end' => ['nullable', 'date_format:Y-m-d'],
            'items.*.meal_plan_id' => ['nullable', 'integer'],
            'items.*.food_duration_mode' => ['nullable', 'string', Rule::in(['day', 'school_week', 'teaching_days', 'custom_range'])],
            'items.*.food_date' => ['nullable', 'date_format:Y-m-d'],
            'items.*.food_week_start' => ['nullable', 'date_format:Y-m-d'],
            'items.*.food_start_date' => ['nullable', 'date_format:Y-m-d'],
            'items.*.food_day_count' => ['nullable', 'integer', 'min:1'],
            'items.*.food_range_start' => ['nullable', 'date_format:Y-m-d'],
            'items.*.food_range_end' => ['nullable', 'date_format:Y-m-d'],

            'collect_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'payment_method' => ['nullable', 'in:cash,bank,card,transfer,instapay'],
            'cash_account_id' => ['nullable', 'integer', 'exists:cash_accounts,id'],
            'idempotency_key' => ['required', 'uuid'],

            'payment_type' => ['required', Rule::in(['one_time', 'calendar'])],
            'notes' => ['nullable', 'string', 'max:1000'],

            // Tariff-only: never accept a client-supplied price/amount.
            'unit_price' => ['prohibited'],
            'amount' => ['prohibited'],
            'total_amount' => ['prohibited'],
            'paid_amount' => ['prohibited'],
            'remaining_amount' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $fee = Fee::find($this->integer('fee_id'));
            if ($fee?->category === Fee::CATEGORY_FOOD) {
                $this->validateFoodDuration($validator);
            }

            // Collecting money requires a method and, for methods with no
            // canonical account mapping (card/transfer), a cash account —
            // cash/bank/instapay resolve their own canonical account
            // server-side and never consult this field.
            if (bccomp((string) ($this->input('collect_amount') ?? '0'), '0', 2) > 0) {
                if (! $this->filled('payment_method')) {
                    $validator->errors()->add('payment_method', 'Выберите способ оплаты.');
                }
                $resolvesCanonically = $this->filled('payment_method')
                    && CashAccount::canonicalRoleForMethod((string) $this->input('payment_method')) !== null;
                if (! $resolvesCanonically && ! $this->filled('cash_account_id')) {
                    $validator->errors()->add('cash_account_id', 'Выберите кассу.');
                }
            }
        });
    }

    /**
     * Mirrors StoreQuickStudentRegistrationRequest's own Food duration-mode
     * check exactly (same required-field-per-mode map, same messages) —
     * never a separate, potentially-diverging validation shape for the same
     * business rule.
     */
    private function validateFoodDuration(Validator $validator): void
    {
        if (blank($this->input('meal_plan_id'))) {
            $validator->errors()->add('meal_plan_id', 'Выберите план питания.');
        }

        $mode = $this->input('food_duration_mode');
        if (! in_array($mode, ['day', 'school_week', 'teaching_days', 'custom_range'], true)) {
            $validator->errors()->add('food_duration_mode', 'Выберите режим периода питания.');

            return;
        }

        $requiredFields = match ($mode) {
            'day' => ['food_date'],
            'school_week' => ['food_week_start'],
            'teaching_days' => ['food_start_date', 'food_day_count'],
            'custom_range' => ['food_range_start', 'food_range_end'],
        };
        foreach ($requiredFields as $field) {
            if (blank($this->input($field))) {
                $validator->errors()->add($field, 'Заполните обязательное поле периода питания.');
            }
        }

        if ($mode === 'custom_range' && filled($this->input('food_range_start')) && filled($this->input('food_range_end'))
            && Carbon::parse($this->input('food_range_end'))->lt(Carbon::parse($this->input('food_range_start')))) {
            $validator->errors()->add('food_range_end', 'Дата окончания периода питания не может быть раньше даты начала.');
        }
    }
}
