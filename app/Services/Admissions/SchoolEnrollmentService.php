<?php

namespace App\Services\Admissions;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\FeePrice;
use App\Models\Invoice;
use App\Models\MealPlan;
use App\Models\MealSubscription;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentServiceSubscription;
use App\Models\User;
use App\Services\Finance\InvoiceCalculationService;
use App\Services\Finance\InvoiceIssuanceService;
use App\Services\AcademicStructureService;
use App\Services\StudentServiceSubscriptionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class SchoolEnrollmentService
{
    public function __construct(
        private InvoiceIssuanceService $issuer,
        private StudentServiceSubscriptionService $subscriptions,
        private AcademicStructureService $structure,
    )
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
                if (! $year->is_active) {
                    throw ValidationException::withMessages(['class_id' => 'Учебная структура изменилась. Обновите страницу и повторите попытку.']);
                }
                $this->structure->validatePlacement(
                    (int) $data['stage_id'],
                    (int) $data['grade_id'],
                    (int) $data['class_id'],
                    requireActive: true,
                );

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

                if ($photo) {
                    $photoPath = $photo->store('students/photos', config('filesystems.uploads.public'));
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

                $mode = EnrollmentMode::query()->lockForUpdate()->findOrFail($data['enrollment_mode_id']);
                if (! $mode->is_active) {
                    throw ValidationException::withMessages(['enrollment_mode_id' => 'Выбранная форма обучения больше не активна.']);
                }
                $enrollment = Enrollment::create([
                    'student_id' => $student->id,
                    'academic_year_id' => $year->id,
                    'enrollment_mode_id' => $mode->id,
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

                // Phase 2: issuance — invoice, items, the registration-fee
                // duplicate guard, subscription linkage, an installment row
                // at issuance (this path never had one before), and audit
                // logging — is delegated to the canonical
                // InvoiceIssuanceService instead of being hand-rolled here.
                // Subscription/MealSubscription creation stays an
                // Admissions-domain concern owned entirely by this resolver;
                // InvoiceIssuanceService never imports either.
                $subscriptionResolver = function (Fee $fee, array $selection, Enrollment $enrollment) use ($pricingDate, $actor) {
                    $subscription = $this->subscriptions->subscribe($enrollment, $fee, [
                        'start_date' => $pricingDate,
                        'quantity' => 1,
                        'status' => StudentServiceSubscription::STATUS_ACTIVE,
                        'metadata' => $this->lineMetadata($selection),
                    ], $actor);

                    if ($fee->category === Fee::CATEGORY_FOOD && filled($selection['option_value'] ?? null)) {
                        $mealPlan = MealPlan::active()->find((int) $selection['option_value']);
                        if ($mealPlan) {
                            MealSubscription::create([
                                'enrollment_id' => $enrollment->id,
                                'meal_plan_id' => $mealPlan->id,
                                'start_date' => $pricingDate,
                            ]);
                        }
                    }

                    return $subscription->id;
                };

                $invoice = $this->issuer->issue($student, [
                    'student_id' => $student->id,
                    'academic_year_id' => $year->id,
                    'due_date' => $year->end_date->toDateString(),
                    'pricing_date' => $pricingDate,
                    'items' => $items,
                    'payment_type' => 'one_time',
                ], $actor, subscriptionResolver: $subscriptionResolver);

                // The curated (non-raw) metadata snapshot and the legacy
                // registration_fee_charged_at bookkeeping are layered on top
                // of the just-issued, canonical InvoiceItem rows — still
                // inside the same transaction as issue(), so a failure here
                // rolls back the invoice too.
                foreach ($invoice->items as $item) {
                    $selection = collect($items)->firstWhere('fee_id', $item->fee_id);
                    $item->update(['metadata' => $this->lineMetadata($selection)]);

                    if (Fee::find($item->fee_id)?->category === Fee::CATEGORY_REGISTRATION) {
                        $enrollment->update(['registration_fee_charged_at' => now()]);
                    }
                }

                return compact('student', 'enrollment', 'invoice');
            });
        } catch (Throwable $exception) {
            if ($photoPath) {
                Storage::disk(config('filesystems.uploads.public'))->delete($photoPath);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $selection */
    private function lineMetadata(array $selection): array
    {
        return array_filter([
            'grade_group' => $selection['grade_group'],
            'payment_period' => $selection['payment_period'],
            'size' => $selection['size'],
            'item' => $selection['item'],
            'option_type' => $selection['option_type'],
            'option_value' => $selection['option_value'],
        ], fn ($value) => filled($value));
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
