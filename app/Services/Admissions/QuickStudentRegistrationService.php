<?php

namespace App\Services\Admissions;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MealPlan;
use App\Models\MealSubscription;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentServiceSubscription;
use App\Models\User;
use App\Services\Finance\InvoiceCalculationService;
use App\Services\Finance\InvoicePaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuickStudentRegistrationService
{
    public function __construct(
        private InvoiceCalculationService $calculator,
        private InvoicePaymentService $payments,
    )
    {
    }

    /** @return array{student: Student, enrollment: Enrollment, invoice: Invoice} */
    public function register(array $data, User $actor): array
    {
        return DB::transaction(function () use ($data, $actor) {
            $year = AcademicYear::query()->lockForUpdate()->findOrFail($data['academic_year_id']);
            if (! $year->is_active) {
                throw ValidationException::withMessages(['academic_year_id' => 'Выбранный учебный год больше не активен.']);
            }
            $registrationDate = Carbon::parse($data['registration_date']);
            if ($registrationDate->gt($year->end_date)) {
                throw ValidationException::withMessages(['registration_date' => 'Дата регистрации не может быть позже окончания учебного года.']);
            }

            $stage = Stage::query()->lockForUpdate()->findOrFail($data['stage_id']);
            $grade = Grade::query()->lockForUpdate()->findOrFail($data['grade_id']);
            $class = SchoolClass::query()->lockForUpdate()->findOrFail($data['class_id']);
            $mode = EnrollmentMode::query()->lockForUpdate()->findOrFail($data['enrollment_mode_id']);
            if (! $stage->is_active || $grade->stage_id !== $stage->id || $class->grade_id !== $grade->id || ! $class->is_active) {
                throw ValidationException::withMessages(['class_id' => 'Ступень, параллель или класс изменились. Обновите страницу и повторите попытку.']);
            }
            if (! $mode->is_active) {
                throw ValidationException::withMessages(['enrollment_mode_id' => 'Выбранная форма обучения больше не активна.']);
            }

            $student = Student::create([
                'last_name_ru' => $data['student_last_name_ru'],
                'first_name_ru' => $data['student_first_name_ru'],
                'patronymic_ru' => $data['student_patronymic_ru'] ?? null,
                'phone' => $data['phone'],
                'class_id' => $class->id,
                'status' => Student::STATUS_PRE_REGISTERED,
            ]);

            $enrollment = Enrollment::create([
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'enrollment_mode_id' => $data['enrollment_mode_id'],
                'stage_id' => $data['stage_id'],
                'grade_id' => $data['grade_id'],
                'class_id' => $class->id,
                'academic_year' => $year->name,
                'enrollment_date' => $data['registration_date'],
                'enrolled_at' => $data['registration_date'],
                'status' => 'active',
                'is_active' => true,
                'notes' => collect([
                    'Быстрая предварительная регистрация. Личное дело не завершено.',
                    $data['notes'] ?? null,
                ])->filter()->implode("\n"),
            ]);

            $normalizedServices = collect($data['services'])->map(function (array $service) use ($grade, $mode) {
                $fee = Fee::query()->lockForUpdate()->findOrFail($service['fee_id']);
                $product = null;
                $route = null;
                $mealPlan = null;

                if ($fee->category === Fee::CATEGORY_UNIFORM) {
                    $product = DB::table('uniform_products')->where('is_active', true)->lockForUpdate()->find($service['uniform_product_id']);
                    if (! $product) {
                        throw ValidationException::withMessages(['services' => 'Выбранное изделие школьной формы больше не доступно.']);
                    }
                }
                if ($fee->category === Fee::CATEGORY_TRANSPORT) {
                    $route = DB::table('transport_routes')->lockForUpdate()->find($service['transport_route_id']);
                    if (! $route) {
                        throw ValidationException::withMessages(['services' => 'Выбранный транспортный маршрут больше не доступен.']);
                    }
                }
                if ($fee->category === Fee::CATEGORY_FOOD) {
                    $mealPlan = MealPlan::query()->where('is_active', true)->lockForUpdate()->find($service['meal_plan_id']);
                    if (! $mealPlan) {
                        throw ValidationException::withMessages(['services' => 'Выбранный план питания больше не доступен.']);
                    }
                }

                return array_merge($service, [
                    '_fee_category' => $fee->category,
                    'enrollment_mode_id' => $mode->id,
                    'quantity' => (int) $service['quantity'],
                    'grade_id' => in_array($fee->category, [
                        Fee::CATEGORY_TUITION,
                        Fee::CATEGORY_TUITION_REGULAR,
                        Fee::CATEGORY_TUITION_FAMILY,
                        Fee::CATEGORY_TUITION_EXTERNAL,
                    ], true) && blank($service['grade_group'] ?? null) ? $grade->id : null,
                    'item' => $product?->name_ru,
                    'size' => $product?->size,
                    'transport_route_name' => $route?->name,
                    'meal_plan_name' => $mealPlan?->name_ru,
                    'option_type' => match ($fee->category) {
                        Fee::CATEGORY_TRANSPORT => 'zone',
                        Fee::CATEGORY_FOOD => 'meal_plan',
                        default => null,
                    },
                    'option_value' => match ($fee->category) {
                        Fee::CATEGORY_TRANSPORT => $service['transport_area'] ?? null,
                        Fee::CATEGORY_FOOD => isset($service['meal_plan_id']) ? (string) $service['meal_plan_id'] : null,
                        default => null,
                    },
                ]);
            });
            $items = $normalizedServices->all();

            $paidNow = $normalizedServices->reduce(
                fn (string $sum, array $service) => bcadd($sum, (string) $service['paid_now'], 2),
                '0.00'
            );
            $calculation = $this->calculator->calculate(
                items: $items,
                initialPaymentAmount: '0.00',
                pricingDate: $data['registration_date'],
                academicYearId: $year->id,
            );

            $allocatedPaid = '0.00';
            $allocatedRemaining = '0.00';
            foreach ($calculation['line_items'] as $index => $line) {
                if (bccomp((string) $normalizedServices[$index]['paid_now'], $line['amount'], 2) > 0) {
                    throw ValidationException::withMessages([
                        "services.{$index}.paid_now" => 'Оплата по услуге не может превышать её рассчитанную стоимость.',
                    ]);
                }
            }
            $calculation = $this->calculator->calculate(
                items: $items,
                initialPaymentAmount: $paidNow,
                pricingDate: $data['registration_date'],
                academicYearId: $year->id,
            );

            $invoice = Invoice::create([
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'customer_name' => $student->full_name,
                'currency' => InvoiceCalculationService::CURRENCY,
                'subtotal_amount' => $calculation['subtotal'],
                'total_amount' => $calculation['total_amount'],
                'discount_amount' => '0.00',
                'paid_amount' => '0.00',
                'remaining_amount' => $calculation['total_amount'],
                'status' => Invoice::STATUS_UNPAID,
                'cash_account_id' => null,
                'payment_method' => null,
                'paid_at' => null,
                'due_date' => $year->end_date,
                'created_by' => $actor->id,
            ]);
            $invoice->invoice_number = Invoice::numberFor($invoice->id, $invoice->created_at->format('Y'));
            $invoice->save();

            foreach ($calculation['line_items'] as $index => $line) {
                $selection = $normalizedServices[$index];
                $fee = Fee::query()->lockForUpdate()->findOrFail($line['fee_id']);

                if ($fee->category === Fee::CATEGORY_REGISTRATION) {
                    $lockedEnrollment = Enrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
                    if ($lockedEnrollment->registration_fee_charged_at !== null) {
                        throw ValidationException::withMessages(['services' => 'Регистрационный взнос уже начислен за этот учебный год.']);
                    }
                    $lockedEnrollment->update(['registration_fee_charged_at' => now()]);
                }

                $metadata = $this->metadata($fee, $selection);
                $subscription = StudentServiceSubscription::create([
                    'enrollment_id' => $enrollment->id,
                    'fee_id' => $fee->id,
                    'start_date' => $data['registration_date'],
                    'quantity' => $line['quantity'],
                    'status' => StudentServiceSubscription::STATUS_ACTIVE,
                    'metadata' => $metadata,
                ]);

                if ($fee->category === Fee::CATEGORY_FOOD) {
                    MealSubscription::create([
                        'enrollment_id' => $enrollment->id,
                        'meal_plan_id' => $selection['meal_plan_id'],
                        'start_date' => $data['registration_date'],
                    ]);
                }

                $linePaid = bcadd((string) $selection['paid_now'], '0', 2);
                $lineRemaining = bcsub($line['amount'], $linePaid, 2);
                $allocatedPaid = bcadd($allocatedPaid, $linePaid, 2);
                $allocatedRemaining = bcadd($allocatedRemaining, $lineRemaining, 2);
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'fee_id' => $fee->id,
                    'subscription_id' => $subscription->id,
                    'description' => $this->description($line['description'], $metadata),
                    'unit_price' => $line['unit_price'],
                    'quantity' => $line['quantity'],
                    'amount' => $line['amount'],
                    'paid_amount' => $linePaid,
                    'remaining_amount' => $lineRemaining,
                    'is_non_refundable' => $fee->is_non_refundable,
                    'metadata' => $metadata,
                ]);

                $invoice->fees()->attach($fee->id, [
                    'amount' => $line['amount'],
                    'item' => $line['item'],
                    'size' => $line['size'],
                    'option_type' => $line['option_type'],
                    'option_value' => $line['option_value'],
                ]);
            }

            if (bccomp($allocatedPaid, $calculation['paid_amount'], 2) !== 0
                || bccomp($allocatedRemaining, $calculation['remaining_amount'], 2) !== 0) {
                throw ValidationException::withMessages(['services' => 'Распределение оплаты по услугам не совпадает с итогом счёта.']);
            }

            if (bccomp($paidNow, '0.00', 2) > 0) {
                $this->payments->record(
                    invoiceId: $invoice->id,
                    cashAccountId: (int) $data['cash_account_id'],
                    amount: $paidNow,
                    paymentMethod: $data['payment_method'],
                    idempotencyKey: (string) Str::uuid(),
                    actor: $actor,
                    reference: "Быстрая регистрация {$invoice->invoice_number}",
                    notes: $data['payment_note'] ?? null,
                );
            }

            return compact('student', 'enrollment', 'invoice');
        });
    }

    private function metadata(Fee $fee, array $selection): array
    {
        return array_filter(match ($fee->category) {
            Fee::CATEGORY_UNIFORM => [
                'uniform_product_id' => $selection['uniform_product_id'],
                'item' => $selection['item'], 'size' => $selection['size'],
            ],
            Fee::CATEGORY_TRANSPORT => [
                'area' => $selection['transport_area'],
                'route_id' => $selection['transport_route_id'],
                'route' => $selection['transport_route_name'],
                'stop' => $selection['transport_stop'] ?? null,
            ],
            Fee::CATEGORY_FOOD => [
                'meal_plan_id' => $selection['meal_plan_id'],
                'meal_plan' => $selection['meal_plan_name'],
            ],
            Fee::CATEGORY_TUITION,
            Fee::CATEGORY_TUITION_REGULAR,
            Fee::CATEGORY_TUITION_FAMILY,
            Fee::CATEGORY_TUITION_EXTERNAL => [
                'grade_group' => $selection['grade_group'] ?? null,
                'payment_period' => $selection['payment_period'] ?? null,
                'first_last_month' => (bool) ($selection['first_last_month'] ?? false),
            ],
            default => [],
        }, fn ($value) => filled($value));
    }

    private function description(string $name, array $metadata): string
    {
        $labels = [
            'item' => 'изделие', 'size' => 'размер', 'area' => 'зона', 'route' => 'маршрут',
            'stop' => 'остановка', 'meal_plan' => 'план питания', 'grade_group' => 'группа классов',
            'payment_period' => 'период оплаты', 'first_last_month' => 'первый и последний месяц',
        ];
        $visible = collect($metadata)->only(array_keys($labels));

        return $visible->isEmpty() ? $name : $name.' — '.$visible
            ->map(fn ($value, $key) => $labels[$key].': '.($value === true ? 'да' : $value))
            ->implode(', ');
    }
}
