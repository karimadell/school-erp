<?php

namespace App\Http\Requests;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\FeePrice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage invoices') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $items = collect($this->input('fees', []))->map(function ($feeId) {
            return [
                'fee_id' => (int) $feeId,
                'fee_price_id' => $this->input("fee_price_id.{$feeId}"),
                'grade_group' => $this->input("grade_group.{$feeId}"),
                'payment_period' => $this->input("payment_period.{$feeId}"),
                'first_last_month' => $this->input("first_last_month.{$feeId}") === '1',
                'size' => $this->input("uniform_size.{$feeId}"),
                'item' => $this->input("uniform_item.{$feeId}"),
                'option_type' => $this->input("option_type.{$feeId}"),
                'option_value' => $this->input("option_value.{$feeId}"),
            ];
        })->values()->all();

        $this->merge([
            'items' => $items,
            'pricing_date' => $this->input('pricing_date', now()->toDateString()),
            'payment_type' => $this->input('payment_type', 'one_time'),
            'initial_payment_amount' => $this->input('initial_payment_amount', '0'),
            'notes' => $this->input('notes', $this->input('invoice_note')),
        ]);
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'due_date' => ['required', 'date'],
            'pricing_date' => ['required', 'date'],
            'fees' => ['required', 'array', 'min:1'],
            'fees.*' => ['required', 'integer', 'distinct', 'exists:fees,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.fee_id' => ['required', 'integer'],
            'items.*.fee_price_id' => ['nullable', 'integer', 'exists:fee_prices,id'],
            'items.*.grade_group' => ['nullable', Rule::in(FeePrice::GRADE_GROUPS)],
            'items.*.payment_period' => ['nullable', 'string', 'max:50'],
            'items.*.first_last_month' => ['boolean'],
            'items.*.size' => ['nullable', 'string', 'max:100'],
            'items.*.item' => ['nullable', 'string', 'max:100'],
            'items.*.option_type' => ['nullable', 'string', 'max:100'],
            'items.*.option_value' => ['nullable', 'string', 'max:255'],
            'discount_type' => ['nullable', 'in:fixed,percent'],
            'discount_value' => ['nullable', 'decimal:0,2', 'min:0'],
            'initial_payment_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'payment_method' => ['nullable', 'in:cash,bank,card,transfer'],
            'cash_account_id' => ['nullable', 'integer', 'exists:cash_accounts,id'],
            // Finance V2, Phase 1B — optional per-fee amount for the initial
            // payment, keyed by fee_id (fees are guaranteed distinct above,
            // so fee_id is a safe key before the corresponding InvoiceItem
            // exists). Only meaningful when the invoice has more than one
            // item; single-item invoices keep Phase 1A's auto-allocation.
            'allocations' => ['nullable', 'array'],
            'allocations.*' => ['nullable', 'decimal:0,2', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Finance V2, Phase 2B (review finding M2): deliberately NOT
            // extended to accept 'calendar' — calendar (monthly/quarterly/
            // yearly) billing is Quick-Registration-only in this phase.
            // Classic Invoice (this request) supports one-time and
            // explicit-custom-plan billing only; see StoreQuickStudentRegistrationRequest
            // for where 'calendar' is accepted.
            'payment_type' => ['required', 'in:one_time,plan'],
            'payment_plan_id' => ['nullable', 'required_if:payment_type,plan', 'integer', 'exists:payment_plans,id'],
            'subtotal' => ['prohibited'],
            'total_amount' => ['prohibited'],
            'paid_amount' => ['prohibited'],
            'remaining_amount' => ['prohibited'],
            'status' => ['prohibited'],
            'discount_amount' => ['prohibited'],
            'unit_price' => ['prohibited'],
            'invoice_number' => ['prohibited'],
            'currency' => ['prohibited'],
            'subtotal_amount' => ['prohibited'],
            'created_by' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $year = AcademicYear::find($this->integer('academic_year_id'));

            if (! $year?->is_active) {
                $validator->errors()->add('academic_year_id', 'Счёт можно создать только для активного учебного года.');

                return;
            }

            if ($this->date('due_date')->lt($year->start_date) || $this->date('due_date')->gt($year->end_date)) {
                $validator->errors()->add('due_date', 'Срок оплаты должен находиться в пределах выбранного учебного года.');
            }
            if ($this->date('pricing_date')->gt($year->end_date)) {
                $validator->errors()->add('pricing_date', 'Дата выставления счёта не может быть позже окончания выбранного учебного года.');
            }

            $hasEnrollment = Enrollment::query()
                ->where('student_id', $this->integer('student_id'))
                ->where('academic_year_id', $year->id)
                ->where('is_active', true)
                ->exists();

            if (! $hasEnrollment) {
                $validator->errors()->add('student_id', 'Ученик не имеет активного зачисления на выбранный учебный год.');
            }

            $fees = Fee::query()->whereIn('id', collect($this->input('items'))->pluck('fee_id'))->get()->keyBy('id');
            foreach ($this->input('items') as $index => $item) {
                $fee = $fees->get((int) $item['fee_id']);
                if (! $fee?->is_active) {
                    $validator->errors()->add("fees.{$index}", 'Выбранная услуга недоступна.');

                    continue;
                }

                $allowed = match ($fee->category) {
                    Fee::CATEGORY_TUITION, Fee::CATEGORY_TUITION_REGULAR,
                    Fee::CATEGORY_TUITION_FAMILY, Fee::CATEGORY_TUITION_EXTERNAL => ['grade_group', 'payment_period', 'first_last_month'],
                    Fee::CATEGORY_TRANSPORT => ['payment_period', 'option_type', 'option_value'],
                    Fee::CATEGORY_FOOD => ['option_type', 'option_value'],
                    Fee::CATEGORY_UNIFORM => ['size', 'item', 'option_type', 'option_value'],
                    default => [],
                };

                foreach (['grade_group', 'payment_period', 'size', 'item', 'option_type', 'option_value'] as $field) {
                    if (filled($item[$field] ?? null) && ! in_array($field, $allowed, true)) {
                        $validator->errors()->add("items.{$index}.{$field}", "Параметр не применяется к услуге «{$fee->name_ru}».");
                    }
                }
                if (($item['first_last_month'] ?? false) && ! in_array('first_last_month', $allowed, true)) {
                    $validator->errors()->add("items.{$index}.first_last_month", "Параметр не применяется к услуге «{$fee->name_ru}».");
                }
            }

            if (bccomp((string) ($this->input('initial_payment_amount') ?? '0'), '0', 2) > 0) {
                if (! $this->filled('payment_method')) {
                    $validator->errors()->add('payment_method', 'Выберите способ первоначальной оплаты.');
                }

                if (! $this->filled('cash_account_id')) {
                    $validator->errors()->add('cash_account_id', 'Выберите кассу для первоначальной оплаты.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'student_id.required' => 'Выберите ученика.',
            'student_id.exists' => 'Выбранный ученик не найден.',
            'academic_year_id.required' => 'Выберите учебный год.',
            'academic_year_id.exists' => 'Выбранный учебный год не найден.',
            'due_date.required' => 'Укажите срок оплаты.',
            'due_date.date' => 'Срок оплаты указан в неверном формате.',
            'pricing_date.required' => 'Укажите дату выставления счёта.',
            'pricing_date.date' => 'Дата выставления счёта указана в неверном формате.',
            'fees.required' => 'Выберите хотя бы одну услугу.',
            'fees.min' => 'Выберите хотя бы одну услугу.',
            'fees.*.distinct' => 'Одну услугу нельзя добавлять в счёт несколько раз.',
            'fees.*.exists' => 'Одна из выбранных услуг не найдена.',
            'items.*.fee_price_id.exists' => 'Выбранный тариф не найден.',
            'discount_type.in' => 'Выбран недопустимый тип скидки.',
            'discount_value.decimal' => 'Скидка должна содержать не более двух знаков после запятой.',
            'discount_value.min' => 'Скидка не может быть отрицательной.',
            'initial_payment_amount.decimal' => 'Сумма оплаты должна содержать не более двух знаков после запятой.',
            'initial_payment_amount.min' => 'Первоначальный платёж не может быть отрицательным.',
            'payment_method.in' => 'Выбран недопустимый способ оплаты.',
            'cash_account_id.exists' => 'Выбранная касса не найдена.',
            'payment_plan_id.required_if' => 'Выберите план оплаты.',
            '*.prohibited' => 'Вычисляемые финансовые поля нельзя передавать вручную.',
        ];
    }
}
