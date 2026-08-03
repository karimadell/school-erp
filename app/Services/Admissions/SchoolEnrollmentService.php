<?php

namespace App\Services\Admissions;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MealPlan;
use App\Models\MealSubscription;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentServiceSubscription;
use App\Models\User;
use App\Services\Finance\InvoiceCalculationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class SchoolEnrollmentService
{
    public function __construct(private InvoiceCalculationService $calculator)
    {
    }

    /** @return array{student: Student, enrollment: Enrollment, invoice: Invoice} */
    public function enroll(array $data, User $actor, ?UploadedFile $photo = null): array
    {
        $photoPath = null;

        try {
            return DB::transaction(function () use ($data, $actor, $photo, &$photoPath) {
                $year = AcademicYear::query()->lockForUpdate()->findOrFail($data['academic_year_id']);
                $class = SchoolClass::query()->with('grade')->lockForUpdate()->findOrFail($data['class_id']);
                if (! $year->is_active || ! $class->is_active
                    || $class->grade_id !== (int) $data['grade_id']
                    || $class->grade->stage_id !== (int) $data['stage_id']) {
                    throw ValidationException::withMessages(['class_id' => 'Учебная структура изменилась. Обновите страницу и повторите попытку.']);
                }

                $pricingDate = now()->betweenIncluded($year->start_date, $year->end_date)
                    ? now()->toDateString()
                    : $year->start_date->toDateString();
                $prices = FeePrice::query()->with('fee')->whereIn('id', $data['fee_price_ids'])
                    ->lockForUpdate()->get()->keyBy('id');
                $items = collect($data['fee_price_ids'])->map(function ($id) use ($prices, $year, $pricingDate) {
                    $price = $prices->get((int) $id);
                    if (! $price || ! $price->is_active || $price->academic_year_id !== $year->id
                        || $price->currency !== InvoiceCalculationService::CURRENCY
                        || $price->start_date->gt($pricingDate)
                        || ($price->end_date && $price->end_date->lt($pricingDate))) {
                        throw ValidationException::withMessages(['fee_price_ids' => 'Выбранный тариф больше не действует.']);
                    }

                    return [
                        'fee_id' => $price->fee_id,
                        'quantity' => 1,
                        'grade_id' => $price->grade_id,
                        'grade_group' => $price->grade_group,
                        'payment_period' => $price->payment_period,
                        'size' => $price->size,
                        'item' => $price->item,
                        'option_type' => $price->option_type,
                        'option_value' => $price->option_value,
                    ];
                })->all();
                $calculation = $this->calculator->calculate(
                    items: $items,
                    initialPaymentAmount: '0.00',
                    pricingDate: $pricingDate,
                    academicYearId: $year->id,
                );

                if ($photo) {
                    $photoPath = $photo->store('students/photos', 'public');
                    if (! $photoPath) {
                        throw ValidationException::withMessages(['photo' => 'Не удалось сохранить фотографию ученика.']);
                    }
                }

                $documents = [
                    'name_en' => $data['student_name_en'] ?? null,
                    'name_ar' => $data['student_name_ar'] ?? null,
                    'birth_date' => $data['birth_date'] ?? null,
                    'gender' => $data['gender'] ?? null,
                    'identity_document' => $data['identity_document'] ?? null,
                    'father' => $this->contact($data, 'father'),
                    'mother' => $this->contact($data, 'mother'),
                    'emergency_contact' => $data['emergency_contact'] ?? null,
                ];
                $student = Student::create([
                    'name' => $data['student_name_ru'],
                    'class_id' => $class->id,
                    'nationality' => $data['nationality'] ?? null,
                    'phone' => $data['father_phone'] ?? $data['mother_phone'] ?? null,
                    'photo' => $photoPath,
                    'documents' => $documents,
                    'status' => Student::STATUS_ACTIVE,
                ]);

                $modeId = EnrollmentMode::query()->where('is_active', true)->orderBy('id')->value('id');
                $enrollment = Enrollment::create([
                    'student_id' => $student->id,
                    'academic_year_id' => $year->id,
                    'enrollment_mode_id' => $modeId,
                    'stage_id' => $data['stage_id'],
                    'grade_id' => $data['grade_id'],
                    'class_id' => $class->id,
                    'academic_year' => $year->name,
                    'enrollment_date' => $pricingDate,
                    'enrolled_at' => $pricingDate,
                    'status' => 'active',
                    'is_active' => true,
                    'notes' => 'Оформлено через современный мастер зачисления.',
                ]);

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
                    'due_date' => $year->end_date,
                    'created_by' => $actor->id,
                ]);
                $invoice->invoice_number = Invoice::numberFor($invoice->id, $invoice->created_at->format('Y'));
                $invoice->save();

                foreach ($calculation['line_items'] as $index => $line) {
                    $selection = $items[$index];
                    $fee = Fee::query()->lockForUpdate()->findOrFail($line['fee_id']);
                    if ($fee->category === Fee::CATEGORY_REGISTRATION) {
                        $enrollment->update(['registration_fee_charged_at' => now()]);
                    }
                    $metadata = array_filter([
                        'grade_group' => $selection['grade_group'],
                        'payment_period' => $selection['payment_period'],
                        'size' => $selection['size'],
                        'item' => $selection['item'],
                        'option_type' => $selection['option_type'],
                        'option_value' => $selection['option_value'],
                    ], fn ($value) => filled($value));
                    $subscription = StudentServiceSubscription::create([
                        'enrollment_id' => $enrollment->id,
                        'fee_id' => $fee->id,
                        'start_date' => $pricingDate,
                        'quantity' => 1,
                        'status' => StudentServiceSubscription::STATUS_ACTIVE,
                        'metadata' => $metadata,
                    ]);
                    if ($fee->category === Fee::CATEGORY_FOOD && is_numeric($selection['option_value'])) {
                        $mealPlan = MealPlan::active()->find((int) $selection['option_value']);
                        if ($mealPlan) {
                            MealSubscription::create(['enrollment_id' => $enrollment->id, 'meal_plan_id' => $mealPlan->id, 'start_date' => $pricingDate]);
                        }
                    }
                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'fee_id' => $fee->id,
                        'subscription_id' => $subscription->id,
                        'description' => $line['description'],
                        'unit_price' => $line['unit_price'],
                        'quantity' => 1,
                        'amount' => $line['amount'],
                        'paid_amount' => '0.00',
                        'remaining_amount' => $line['amount'],
                        'metadata' => $metadata,
                    ]);
                    $invoice->fees()->attach($fee->id, [
                        'amount' => $line['amount'], 'item' => $line['item'], 'size' => $line['size'],
                        'option_type' => $line['option_type'], 'option_value' => $line['option_value'],
                    ]);
                }

                return compact('student', 'enrollment', 'invoice');
            });
        } catch (Throwable $exception) {
            if ($photoPath) {
                Storage::disk('public')->delete($photoPath);
            }
            throw $exception;
        }
    }

    private function contact(array $data, string $prefix): array
    {
        return array_filter([
            'name' => $data[$prefix.'_name'] ?? null,
            'phone' => $data[$prefix.'_phone'] ?? null,
            'email' => $data[$prefix.'_email'] ?? null,
            'passport' => $data[$prefix.'_passport'] ?? null,
        ], fn ($value) => filled($value));
    }
}
