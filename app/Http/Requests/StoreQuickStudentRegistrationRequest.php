<?php

namespace App\Http\Requests;

use App\Models\AcademicYear;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\Grade;
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
        $services = collect($this->input('services', []))->map(function ($service) {
            $service = is_array($service) ? $service : [];
            $service['quantity'] ??= 1;
            $service['paid_now'] ??= '0.00';

            return $service;
        })->values()->all();

        $this->merge(['services' => $services]);
    }

    public function rules(): array
    {
        return [
            'student_name_ru' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'stage_id' => ['required', 'integer', 'exists:stages,id'],
            'grade_id' => ['required', 'integer', 'exists:grades,id'],
            'enrollment_mode_id' => ['required', 'integer', 'exists:enrollment_modes,id'],
            'registration_date' => ['required', 'date'],
            'services' => ['required', 'array', 'min:1'],
            'services.*.fee_id' => ['required', 'integer', 'distinct', 'exists:fees,id'],
            'services.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'services.*.paid_now' => ['required', 'decimal:0,2', 'min:0'],
            'services.*.item' => ['nullable', 'string', 'max:100'],
            'services.*.size' => ['nullable', 'string', 'max:50'],
            'services.*.transport_area' => ['nullable', 'string', 'max:150'],
            'services.*.transport_route' => ['nullable', 'string', 'max:150'],
            'services.*.transport_stop' => ['nullable', 'string', 'max:150'],
            'services.*.meal_plan_id' => ['nullable', 'integer', 'exists:meal_plans,id'],
            'cash_account_id' => ['nullable', 'integer', 'exists:cash_accounts,id'],
            'payment_method' => ['nullable', Rule::in(['cash', 'card', 'bank', 'transfer'])],
            'invoice_number' => ['prohibited'],
            'currency' => ['prohibited'],
            'subtotal_amount' => ['prohibited'],
            'total_amount' => ['prohibited'],
            'paid_amount' => ['prohibited'],
            'remaining_amount' => ['prohibited'],
            'status' => ['prohibited'],
            'price' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $year = AcademicYear::find($this->integer('academic_year_id'));
            if ($year && ! $year->is_active) {
                $validator->errors()->add('academic_year_id', 'Для быстрого оформления можно выбрать только активный учебный год.');
            }
            if ($year && $this->filled('registration_date')) {
                $date = Carbon::parse($this->input('registration_date'));
                if ($date->lt($year->start_date) || $date->gt($year->end_date)) {
                    $validator->errors()->add('registration_date', 'Дата регистрации должна находиться в пределах выбранного учебного года.');
                }
            }

            $mode = EnrollmentMode::find($this->integer('enrollment_mode_id'));
            if ($mode && ! $mode->is_active) {
                $validator->errors()->add('enrollment_mode_id', 'Выбранная форма обучения не активна.');
            }

            $grade = Grade::find($this->integer('grade_id'));
            if ($grade && $grade->stage_id !== $this->integer('stage_id')) {
                $validator->errors()->add('grade_id', 'Выбранный класс не относится к указанной ступени.');
            }

            $services = collect($this->input('services', []));
            $fees = Fee::whereIn('id', $services->pluck('fee_id'))->get()->keyBy('id');
            if ($services->filter(fn ($item) => $fees->get((int) ($item['fee_id'] ?? 0))?->category === Fee::CATEGORY_REGISTRATION)->count() > 1) {
                $validator->errors()->add('services', 'Регистрационный взнос можно добавить только один раз за учебный год.');
            }

            foreach ($services as $index => $item) {
                $category = $fees->get((int) ($item['fee_id'] ?? 0))?->category;
                if ($category === Fee::CATEGORY_UNIFORM && (blank($item['item'] ?? null) || blank($item['size'] ?? null))) {
                    $validator->errors()->add("services.{$index}.item", 'Для школьной формы укажите изделие и размер.');
                }
                if ($category === Fee::CATEGORY_TRANSPORT && collect(['transport_area', 'transport_route', 'transport_stop'])->contains(fn ($field) => blank($item[$field] ?? null))) {
                    $validator->errors()->add("services.{$index}.transport_area", 'Для транспорта укажите район, маршрут и остановку.');
                }
                if ($category === Fee::CATEGORY_FOOD && blank($item['meal_plan_id'] ?? null)) {
                    $validator->errors()->add("services.{$index}.meal_plan_id", 'Для питания выберите план питания.');
                }
            }

            $paid = $services->reduce(fn (string $sum, array $item) => bcadd($sum, (string) ($item['paid_now'] ?? 0), 2), '0.00');
            if (bccomp($paid, '0.00', 2) > 0) {
                if (! $this->filled('cash_account_id')) {
                    $validator->errors()->add('cash_account_id', 'Для оплаты выберите кассу.');
                }
                if (! $this->filled('payment_method')) {
                    $validator->errors()->add('payment_method', 'Для оплаты выберите способ оплаты.');
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'student_name_ru.required' => 'Укажите имя ученика на русском языке.',
            'phone.required' => 'Укажите номер телефона.',
            'academic_year_id.required' => 'Выберите учебный год.',
            'stage_id.required' => 'Выберите ступень.',
            'grade_id.required' => 'Выберите класс.',
            'enrollment_mode_id.required' => 'Выберите форму обучения.',
            'registration_date.required' => 'Укажите дату регистрации.',
            'services.required' => 'Выберите хотя бы одну финансовую услугу.',
            'services.min' => 'Выберите хотя бы одну финансовую услугу.',
            'services.*.fee_id.distinct' => 'Одну услугу нельзя добавить в счёт дважды.',
            'services.*.quantity.min' => 'Количество должно быть не меньше 1.',
        ];
    }
}
