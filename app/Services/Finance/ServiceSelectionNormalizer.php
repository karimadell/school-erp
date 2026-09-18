<?php

namespace App\Services\Finance;

use App\Models\Fee;
use App\Models\Grade;
use App\Models\EnrollmentMode;
use App\Models\MealPlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Unified Collection foundation (PR A) — extracted, unchanged from
 * QuickStudentRegistrationService::register()'s own $normalizedServices
 * closure. Given a raw "services[]" selection (one entry per Fee an
 * operator picked, whatever shape a request already validated) plus the
 * placement/payment context, produces the exact canonical, flat item array
 * InvoiceIssuanceService::issue()'s own 'items' input expects — per-
 * category option_type/option_value/grade_id resolution, mixed-billing
 * strategy re-validation, and Uniform's one-selection-fans-out-into-N-lines
 * behavior, all byte-identical to before.
 *
 * Pure normalization + read-only catalog validation (Fee/transport_routes/
 * meal_plans/uniform_products existence and is_active checks) — no writes,
 * no Student/Enrollment/Invoice knowledge, no registration-specific
 * bookkeeping. Reusable by any future caller that needs to turn an
 * operator's service picks into issuance-ready items, not just Quick
 * Registration.
 */
class ServiceSelectionNormalizer
{
    /**
     * @param  array<int, array<string, mixed>>  $services
     * @param  array<int, Fee>  $feesById  Already-loaded, keyed by id — the
     *         caller's own responsibility (unchanged from before this
     *         extraction: Fee is read-only here, never locked/written, so
     *         there is no correctness reason for this method to own that
     *         query).
     * @return Collection<int, array<string, mixed>>
     */
    public function normalize(array $services, Grade $grade, ?EnrollmentMode $mode, string $paymentType, array $feesById): Collection
    {
        return collect($services)->flatMap(function (array $service) use ($grade, $mode, $feesById, $paymentType) {
            $fee = $feesById[(int) $service['fee_id']]
                ?? throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(Fee::class, [$service['fee_id']]);

            $common = [
                '_fee_category' => $fee->category,
                'enrollment_mode_id' => $mode?->id,
                'grade_id' => in_array($fee->category, [
                    Fee::CATEGORY_TUITION,
                    Fee::CATEGORY_TUITION_REGULAR,
                    Fee::CATEGORY_TUITION_FAMILY,
                    Fee::CATEGORY_TUITION_EXTERNAL,
                ], true) && blank($service['grade_group'] ?? null) ? $grade->id : null,
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
                'payment_period' => $fee->category === Fee::CATEGORY_FOOD ? Fee::PERIOD_DAILY : ($service['payment_period'] ?? null),
            ];

            if ($paymentType === 'mixed' && $fee->category !== Fee::CATEGORY_FOOD) {
                $strategy = $service['billing_strategy'] ?? 'once';
                $calendarCapable = $fee->allowedBillingPeriods()->intersect(\App\Models\FeeBillingPeriod::CALENDAR_PERIODS)->isNotEmpty();

                if ($strategy === 'calendar') {
                    if (! $calendarCapable) {
                        throw ValidationException::withMessages(['services' => "Услуга «{$fee->name_ru}» не поддерживает периодическую оплату."]);
                    }
                    $period = $service['payment_period'] ?? null;
                    if (blank($period) || ! in_array($period, \App\Models\FeeBillingPeriod::CALENDAR_PERIODS, true) || ! $fee->allowsBillingPeriod($period)) {
                        throw ValidationException::withMessages(['services' => "Недопустимый период оплаты для услуги «{$fee->name_ru}»."]);
                    }
                    $common['_billing_strategy'] = 'calendar';
                    $common['_billing_period'] = $period;
                } else {
                    $common['_billing_strategy'] = 'once';
                    $common['_billing_period'] = null;
                }
            }

            if ($fee->category === Fee::CATEGORY_UNIFORM) {
                $uniformItems = $service['uniform_items'] ?? [];
                if (empty($uniformItems)) {
                    throw ValidationException::withMessages(['services' => 'Для школьной формы выберите хотя бы одно изделие и размер.']);
                }

                return collect($uniformItems)->map(function (array $row, int $position) use ($service, $common) {
                    $product = DB::table('uniform_products')->where('is_active', true)->find($row['uniform_product_id']);
                    if (! $product) {
                        throw ValidationException::withMessages(['services' => 'Выбранное изделие школьной формы больше не доступно.']);
                    }

                    return array_merge($service, $common, [
                        'quantity' => (int) $row['quantity'],
                        'item' => $product->name_ru,
                        'size' => $product->size,
                        'uniform_product_id' => $product->id,
                        'paid_now' => $position === 0 ? ($service['paid_now'] ?? '0.00') : '0.00',
                    ]);
                })->values();
            }

            $route = null;
            $mealPlan = null;
            if ($fee->category === Fee::CATEGORY_TRANSPORT) {
                $route = DB::table('transport_routes')->find($service['transport_route_id']);
                if (! $route) {
                    throw ValidationException::withMessages(['services' => 'Выбранный транспортный маршрут больше не доступен.']);
                }
            }
            if ($fee->category === Fee::CATEGORY_FOOD) {
                $mealPlan = MealPlan::query()->where('is_active', true)->find($service['meal_plan_id']);
                if (! $mealPlan) {
                    throw ValidationException::withMessages(['services' => 'Выбранный план питания больше не доступен.']);
                }
            }

            return collect([array_merge($service, $common, [
                'quantity' => (int) $service['quantity'],
                'item' => null,
                'size' => null,
                'transport_route_name' => $route?->name,
                'meal_plan_name' => $mealPlan?->name_ru,
            ])]);
        })->values();
    }
}
