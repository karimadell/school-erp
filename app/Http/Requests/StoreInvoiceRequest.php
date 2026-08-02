<?php

namespace App\Http\Requests;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use Illuminate\Foundation\Http\FormRequest;
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
            'fees' => ['required', 'array', 'min:1'],
            'fees.*' => ['required', 'integer', 'distinct', 'exists:fees,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.fee_id' => ['required', 'integer'],
            'items.*.grade_group' => ['nullable', 'string', 'max:100'],
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
            'notes' => ['nullable', 'string', 'max:1000'],
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

            $hasEnrollment = Enrollment::query()
                ->where('student_id', $this->integer('student_id'))
                ->where('academic_year_id', $year->id)
                ->where('is_active', true)
                ->exists();

            if (! $hasEnrollment) {
                $validator->errors()->add('student_id', 'Ученик не имеет активного зачисления на выбранный учебный год.');
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
            'fees.required' => 'Выберите хотя бы одну услугу.',
            'fees.min' => 'Выберите хотя бы одну услугу.',
            'fees.*.distinct' => 'Одну услугу нельзя добавлять в счёт несколько раз.',
            'fees.*.exists' => 'Одна из выбранных услуг не найдена.',
            'discount_type.in' => 'Выбран недопустимый тип скидки.',
            'discount_value.decimal' => 'Скидка должна содержать не более двух знаков после запятой.',
            'discount_value.min' => 'Скидка не может быть отрицательной.',
            'initial_payment_amount.decimal' => 'Сумма оплаты должна содержать не более двух знаков после запятой.',
            'initial_payment_amount.min' => 'Первоначальный платёж не может быть отрицательным.',
            'payment_method.in' => 'Выбран недопустимый способ оплаты.',
            'cash_account_id.exists' => 'Выбранная касса не найдена.',
            '*.prohibited' => 'Вычисляемые финансовые поля нельзя передавать вручную.',
        ];
    }
}
