<?php

namespace App\Services\Admissions;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\MealSubscription;
use App\Models\Student;
use App\Models\StudentServiceSubscription;
use App\Models\User;
use App\Services\Finance\InvoiceCalculationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuickStudentRegistrationService
{
    public function __construct(private InvoiceCalculationService $calculator)
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

            $student = Student::create([
                'name' => $data['student_name_ru'],
                'phone' => $data['phone'],
                'status' => Student::STATUS_PRE_REGISTERED,
            ]);

            $enrollment = Enrollment::create([
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'enrollment_mode_id' => $data['enrollment_mode_id'],
                'stage_id' => $data['stage_id'],
                'grade_id' => $data['grade_id'],
                'class_id' => null,
                'academic_year' => $year->name,
                'enrollment_date' => $data['registration_date'],
                'enrolled_at' => $data['registration_date'],
                'status' => 'active',
                'is_active' => true,
                'notes' => 'Быстрая предварительная регистрация. Личное дело не завершено.',
            ]);

            $items = collect($data['services'])->map(function (array $service) {
                $fee = Fee::query()->findOrFail($service['fee_id']);

                return array_merge($service, [
                    'quantity' => (int) $service['quantity'],
                    'item' => $fee->category === Fee::CATEGORY_UNIFORM ? $service['item'] : null,
                    'size' => $fee->category === Fee::CATEGORY_UNIFORM ? $service['size'] : null,
                    'option_type' => $fee->category === Fee::CATEGORY_TRANSPORT ? 'area' : null,
                    'option_value' => $fee->category === Fee::CATEGORY_TRANSPORT ? $service['transport_area'] : null,
                ]);
            })->all();

            $paidNow = collect($data['services'])->reduce(
                fn (string $sum, array $service) => bcadd($sum, (string) $service['paid_now'], 2),
                '0.00'
            );
            $calculation = $this->calculator->calculate(
                items: $items,
                initialPaymentAmount: $paidNow,
                pricingDate: $data['registration_date'],
            );

            foreach ($calculation['line_items'] as $index => $line) {
                if (bccomp((string) $data['services'][$index]['paid_now'], $line['amount'], 2) > 0) {
                    throw ValidationException::withMessages([
                        "services.{$index}.paid_now" => 'Оплата по услуге не может превышать её рассчитанную стоимость.',
                    ]);
                }
            }

            $invoice = Invoice::create([
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'customer_name' => $student->full_name,
                'currency' => InvoiceCalculationService::CURRENCY,
                'subtotal_amount' => $calculation['subtotal'],
                'total_amount' => $calculation['total_amount'],
                'discount_amount' => '0.00',
                'paid_amount' => $calculation['paid_amount'],
                'remaining_amount' => $calculation['remaining_amount'],
                'status' => $calculation['status'],
                'cash_account_id' => bccomp($paidNow, '0.00', 2) > 0 ? $data['cash_account_id'] : null,
                'payment_method' => bccomp($paidNow, '0.00', 2) > 0 ? $data['payment_method'] : null,
                'paid_at' => $calculation['status'] === Invoice::STATUS_PAID ? now() : null,
                'due_date' => $year->end_date,
                'created_by' => $actor->id,
            ]);
            $invoice->invoice_number = Invoice::numberFor($invoice->id, $invoice->created_at->format('Y'));
            $invoice->save();

            foreach ($calculation['line_items'] as $index => $line) {
                $selection = $data['services'][$index];
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

                $linePaid = number_format((float) $selection['paid_now'], 2, '.', '');
                $lineRemaining = bcsub($line['amount'], $linePaid, 2);
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

            if (bccomp($paidNow, '0.00', 2) > 0) {
                $account = CashAccount::query()->lockForUpdate()->findOrFail($data['cash_account_id']);
                CashTransaction::create([
                    'cash_account_id' => $account->id,
                    'amount' => $paidNow,
                    'type' => CashTransaction::TYPE_IN,
                    'category' => CashTransaction::CATEGORY_INCOME,
                    'description' => "Первоначальная оплата по счёту {$invoice->invoice_number}",
                ]);
                InvoicePayment::create([
                    'invoice_id' => $invoice->id,
                    'cash_account_id' => $account->id,
                    'amount' => $paidNow,
                    'payment_method' => $data['payment_method'],
                    'paid_at' => now(),
                    'reference' => "Быстрая регистрация {$invoice->invoice_number}",
                    'created_by' => $actor->id,
                ]);
            }

            return compact('student', 'enrollment', 'invoice');
        });
    }

    private function metadata(Fee $fee, array $selection): array
    {
        return array_filter(match ($fee->category) {
            Fee::CATEGORY_UNIFORM => ['item' => $selection['item'], 'size' => $selection['size']],
            Fee::CATEGORY_TRANSPORT => [
                'area' => $selection['transport_area'], 'route' => $selection['transport_route'],
                'stop' => $selection['transport_stop'],
            ],
            Fee::CATEGORY_FOOD => ['meal_plan_id' => $selection['meal_plan_id']],
            default => [],
        }, fn ($value) => filled($value));
    }

    private function description(string $name, array $metadata): string
    {
        return $metadata === [] ? $name : $name.' — '.collect($metadata)->map(fn ($value, $key) => "{$key}: {$value}")->implode(', ');
    }
}
