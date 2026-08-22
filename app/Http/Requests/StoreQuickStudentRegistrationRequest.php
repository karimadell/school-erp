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
            'cash_account_id' => ['nullable', 'integer', 'exists:cash_accounts,id'],
            'payment_method' => ['nullable', Rule::in(['cash', 'card', 'bank', 'transfer'])],
            'payment_note' => ['nullable', 'string', 'max:1000'],
            'payment_type' => ['required', Rule::in(['one_time','plan'])],
            'payment_plan_id' => ['nullable', 'required_if:payment_type,plan', 'integer', 'exists:payment_plans,id'],
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
                if ($category === Fee::CATEGORY_TRANSPORT && (blank($item['transport_area'] ?? null) || blank($item['transport_route_id'] ?? null))) {
                    $validator->errors()->add("services.{$index}.transport_area", 'Для транспорта укажите район и маршрут.');
                }
                if ($category === Fee::CATEGORY_FOOD && blank($item['meal_plan_id'] ?? null)) {
                    $validator->errors()->add("services.{$index}.meal_plan_id", 'Для питания выберите план питания.');
                }
            }

            $paid = $services->reduce(function (string $sum, array $item): string {
                $value = (string) ($item['paid_now'] ?? '0');

                return preg_match('/^\d+(?:\.\d{1,2})?$/', $value) ? bcadd($sum, $value, 2) : $sum;
            }, '0.00');
            if (bccomp($paid, '0.00', 2) > 0) {
                if (! $this->filled('cash_account_id')) {
                    $validator->errors()->add('cash_account_id', 'Для оплаты выберите кассу.');
                }
                if (! $this->filled('payment_method')) {
                    $validator->errors()->add('payment_method', 'Для оплаты выберите способ оплаты.');
                }
                $cashAccount = CashAccount::find($this->integer('cash_account_id'));
                if ($cashAccount && ! $cashAccount->is_active) {
                    $validator->errors()->add('cash_account_id', 'Выбранная касса неактивна.');
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
