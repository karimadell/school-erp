<?php

namespace App\Services\Admissions;

use App\Models\AcademicYear;
use App\Models\CashAccount;
use App\Models\Enrollment;
use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\MealPlan;
use App\Models\MealSubscription;
use App\Models\SchoolClass;
use App\Models\Stage;
use App\Models\Student;
use App\Models\StudentServiceSubscription;
use App\Models\User;
use App\Services\Finance\InvoiceCalculationService;
use App\Services\Finance\InvoiceIssuanceService;
use App\Services\Finance\InvoicePaymentService;
use App\Services\AcademicStructureService;
use App\Services\StudentServiceSubscriptionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class QuickStudentRegistrationService
{
    public function __construct(
        private InvoiceCalculationService $calculator,
        private InvoiceIssuanceService $issuer,
        private InvoicePaymentService $payments,
        private StudentServiceSubscriptionService $subscriptions,
        private AcademicStructureService $structure,
    )
    {
    }

    /** @return array{student: Student, enrollment: Enrollment, invoice: Invoice} */
    public function register(array $data, User $actor): array
    {
        return DB::transaction(function () use ($data, $actor) {
            // Finance V2, Phase 2D corrective pass (P0/HIGH — invoice
            // issuance idempotency): the SAME per-page-render outer token
            // already used to derive deterministic per-installment payment
            // keys below is reused here for the INVOICE itself, so a
            // retried submission (same token) returns the original
            // invoice directly rather than creating a second one.
            $outerToken = $data['idempotency_token'] ?? null;
            $invoiceIdempotencyKey = $outerToken
                ? (string) Uuid::uuid5(Uuid::NAMESPACE_URL, "quick-registration-invoice:{$outerToken}")
                : null;

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
            $this->structure->validatePlacement(
                $stage->id,
                $grade->id,
                $class->id,
                requireActive: true,
            );
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

            $feesById = [];
            $normalizedServices = collect($data['services'])->map(function (array $service) use ($grade, $mode, &$feesById) {
                $fee = Fee::query()->lockForUpdate()->findOrFail($service['fee_id']);
                $feesById[$fee->id] = $fee;
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

            // Phase 2: issuance itself — invoice, items, registration-fee
            // duplicate guard, subscription linkage, installments, and audit
            // logging — is delegated to the canonical InvoiceIssuanceService
            // instead of being hand-rolled here. Subscription creation stays
            // an Admissions-domain concern: the resolver below is the only
            // place that knows about StudentServiceSubscriptionService or
            // MealSubscription — InvoiceIssuanceService never does.
            $subscriptionResolver = function (Fee $fee, array $selection, Enrollment $enrollment) use ($data, $actor) {
                $subscription = $this->subscriptions->subscribe($enrollment, $fee, [
                    'start_date' => $data['registration_date'],
                    'quantity' => (int) $selection['quantity'],
                    'status' => StudentServiceSubscription::STATUS_ACTIVE,
                    'metadata' => $this->metadata($fee, $selection),
                ], $actor);

                if ($fee->category === Fee::CATEGORY_FOOD) {
                    MealSubscription::create([
                        'enrollment_id' => $enrollment->id,
                        'meal_plan_id' => $selection['meal_plan_id'],
                        'start_date' => $data['registration_date'],
                    ]);
                }

                return $subscription->id;
            };

            $invoice = $this->issuer->issue($student, [
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'due_date' => $year->end_date->toDateString(),
                'pricing_date' => $data['registration_date'],
                'items' => $items,
                'payment_type' => $data['payment_type'] ?? 'one_time',
                'payment_plan_id' => $data['payment_plan_id'] ?? null,
                'billing_period' => $data['billing_period'] ?? null,
            ], $actor, subscriptionResolver: $subscriptionResolver, origin: Invoice::ORIGIN_QUICK_REGISTRATION, idempotencyKey: $invoiceIdempotencyKey);

            // Quick Registration's own per-line concerns — the initial
            // paid/remaining split per service, the enriched description,
            // the curated (non-raw) metadata snapshot, and the legacy
            // registration_fee_charged_at bookkeeping — are layered on top
            // of the just-issued, canonical InvoiceItem rows. This still
            // runs inside the same outer transaction as issue(), so a
            // failure here rolls back the invoice too.
            // Finance V2, Phase 1A: this loop already matches each submitted
            // service line to its real InvoiceItem (by fee_id, see the fix
            // below) and already computes that line's own $linePaid — the
            // exact, already-deterministic mapping PaymentAllocation needs.
            // Collected here and passed to InvoicePaymentService::record()
            // below; zero-paid lines are skipped (nothing to allocate).
            $allocations = [];
            foreach ($invoice->items as $item) {
                // Bug fix (2026-08-29): fee_id arrives here as a string
                // (every HTML form field is a string over HTTP) while
                // $item->fee_id is an int (InvoiceItem has no cast on this
                // column, so Eloquent returns whatever the driver gives —
                // an int on every driver we run against). A strict === used
                // to never match, so search() always returned false, and
                // $normalizedServices[false] silently coerced to index 0 —
                // every line after the first quietly reused the *first*
                // submitted service's paid_now instead of its own. Compare
                // both sides as int, and fail loudly instead of guessing if
                // an invoice item still can't be matched to any submitted
                // line — that indicates a real inconsistency, not something
                // safe to paper over with index 0.
                $index = $normalizedServices->search(fn (array $service) => (int) $service['fee_id'] === (int) $item->fee_id);
                if ($index === false) {
                    throw ValidationException::withMessages([
                        'services' => "Не удалось сопоставить строку счёта с выбранной услугой (fee_id: {$item->fee_id}).",
                    ]);
                }
                $selection = $normalizedServices[$index];
                $fee = $feesById[$item->fee_id];
                $metadata = $this->metadata($fee, $selection);
                $linePaid = bcadd((string) $selection['paid_now'], '0', 2);

                // Same check the old, separate preview calculate() pass used
                // to run before anything was persisted — folded in here
                // against the invoice's own just-issued line amount instead
                // of pricing every line twice. Still runs (and can still
                // throw) before payment is attempted, so a violation rolls
                // back the whole outer transaction exactly as before.
                if (bccomp($linePaid, $item->amount, 2) > 0) {
                    throw ValidationException::withMessages([
                        "services.{$index}.paid_now" => 'Оплата по услуге не может превышать её рассчитанную стоимость.',
                    ]);
                }
                $lineRemaining = bcsub($item->amount, $linePaid, 2);

                if (bccomp($linePaid, '0.00', 2) > 0) {
                    $allocations[] = ['invoice_item_id' => $item->id, 'amount' => $linePaid];
                }

                $item->update([
                    'description' => $this->description($item->description, $metadata),
                    'paid_amount' => $linePaid,
                    'remaining_amount' => $lineRemaining,
                    'metadata' => $metadata,
                ]);

                if ($fee->category === Fee::CATEGORY_REGISTRATION) {
                    $enrollment->update(['registration_fee_charged_at' => now()]);
                }
            }

            if (bccomp($paidNow, '0.00', 2) > 0) {
                // Finance V2, Phase 2B (§4 of the approved design): a
                // calendar/custom-plan schedule can now have more than one
                // installment, and "full payment" must settle every one of
                // them, not just the first. Walk installments in sequence,
                // fully settling as many as $paidNow covers; a remainder
                // that doesn't exactly cover the next whole installment is
                // rejected (same validation-error pattern as the old
                // single-installment check, generalized to "the next
                // uncovered installment" rather than hardcoded to #1).
                $installments = $invoice->installments()->orderBy('sequence')->get();
                if ($installments->isEmpty()) {
                    throw ValidationException::withMessages(['services' => 'У счёта отсутствуют этапы оплаты.']);
                }

                // Each entry: [installment, amount to record against it].
                // For the single-installment case, amount is $paidNow
                // itself (may be a genuine partial payment, unchanged from
                // the original behavior). For a multi-installment schedule,
                // each settled installment's amount is its own full
                // remaining_amount, since only whole installments may be
                // settled (validated below).
                $toRecord = [];

                if ($installments->count() === 1) {
                    // Unchanged original behavior: a single-installment
                    // invoice (one_time payment_type — the overwhelmingly
                    // common case) accepts any partial-or-full amount up
                    // to that one installment's remaining balance. The
                    // "must exactly cover a whole number of installments"
                    // rule below only makes sense once there's more than
                    // one installment to walk.
                    $installment = $installments->first();
                    if (bccomp($paidNow, (string) $installment->remaining_amount, 2) > 0) {
                        throw ValidationException::withMessages(['services' => 'Первоначальная оплата превышает сумму первого этапа рассрочки.']);
                    }
                    $toRecord[] = [$installment, $paidNow];
                } else {
                    $remainingToApply = $paidNow;
                    foreach ($installments as $installment) {
                        if (bccomp($remainingToApply, '0.00', 2) <= 0) {
                            break;
                        }
                        $due = (string) $installment->remaining_amount;
                        if (bccomp($remainingToApply, $due, 2) < 0) {
                            throw ValidationException::withMessages(['services' => 'Первоначальная оплата не покрывает целое число этапов оплаты.']);
                        }
                        $toRecord[] = [$installment, $due];
                        $remainingToApply = bcsub($remainingToApply, $due, 2);
                    }
                    if (bccomp($remainingToApply, '0.00', 2) > 0) {
                        // Cannot happen given upstream per-line paid_now
                        // caps (never exceeds a line's own amount, and
                        // lines sum to the invoice total) — guarded
                        // defensively rather than silently over-applying.
                        throw ValidationException::withMessages(['services' => 'Первоначальная оплата превышает сумму счёта.']);
                    }
                }

                $cashAccountId = CashAccount::resolvePaymentAccountId($data['payment_method'], $data['cash_account_id'] ?? null);
                // Finance V2, Phase 2B corrective pass (review finding M3):
                // deterministic, per-installment-INDEX idempotency keys
                // derived from one outer, page-render-stable token — not a
                // fresh random UUID per call. Deliberately keyed on the
                // POSITION within this submission's own schedule, not on
                // invoice_id/installment_id: issue() always creates a brand
                // new Invoice (no invoice-level dedup exists, and adding
                // one is out of scope here), so a true retry produces a
                // DIFFERENT invoice/installment id every time regardless —
                // keying on those would never actually collide. Keying on
                // the stable (token, index) pair instead means a retry's
                // record() calls reuse attempt one's exact keys.
                //
                // Finance V2, Phase 2D corrective pass: $issue() above is
                // now ALSO keyed on $invoiceIdempotencyKey (derived from
                // this same $outerToken), so a genuine retry with the same
                // token returns the ORIGINAL invoice/installments directly
                // — $installment/$invoice here are already the first
                // attempt's own rows, and each record() call below simply
                // replays its own already-recorded payment (same
                // idempotency-hash check InvoicePaymentService::record()
                // already performs). Net effect: a retried submission
                // creates nothing new at any level — invoice, installments,
                // coverage, or payments — it observes and returns exactly
                // what the first successful attempt already produced. A
                // caller with no idempotency_token (e.g. a non-browser API
                // consumer) falls back to today's fresh-UUID-per-attempt
                // behavior, unchanged (and to always-fresh invoice
                // issuance, also unchanged).
                foreach ($toRecord as $index => [$installment, $amount]) {
                    $idempotencyKey = $outerToken
                        ? (string) Uuid::uuid5(Uuid::NAMESPACE_URL, "quick-registration:{$outerToken}:{$index}")
                        : (string) Str::uuid();
                    $this->payments->record(
                        invoiceId: $invoice->id,
                        cashAccountId: $cashAccountId,
                        amount: $amount,
                        paymentMethod: $data['payment_method'],
                        idempotencyKey: $idempotencyKey,
                        actor: $actor,
                        reference: "Быстрая регистрация {$invoice->invoice_number}",
                        notes: $data['payment_note'] ?? null,
                        installmentId: $installment->id,
                        // Service-level attribution ($allocations) was built
                        // from each service's own individually-entered
                        // paid_now amount, summing to the FULL $paidNow —
                        // it can only be attached to a record() call whose
                        // own amount is that same full total (Phase 1A/1C's
                        // SUM(allocations) === payment.amount invariant), so
                        // it is only ever passed when this whole payment
                        // settles in exactly one installment. Whenever it
                        // spans more than one (count($toRecord) > 1),
                        // splitting per-item attribution across installment
                        // slices would require guessing which item's money
                        // landed in which period — never done here; the
                        // whole payment is honestly recorded as Unallocated
                        // (Phase 2A's existing "Не распределено" bucket)
                        // instead. NOTE: null (not []) is what actually
                        // means "no explicit allocation" to record() — an
                        // empty array is itself a validation error there
                        // (Phase 1A/1C: "specify the allocation").
                        allocations: (count($toRecord) === 1 && $index === 0) ? $allocations : null,
                    );
                }
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
                'payment_period' => $selection['payment_period'] ?? null,
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
