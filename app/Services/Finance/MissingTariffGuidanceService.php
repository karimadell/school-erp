<?php

namespace App\Services\Finance;

use App\Models\EnrollmentMode;
use App\Models\Fee;
use App\Models\Grade;
use Illuminate\Validation\ValidationException;

/**
 * Pure operator-UX helper — never consulted by pricing, never touches
 * InvoiceCalculationService's own fail-loud behavior. It only recognizes
 * the EXACT, already-established generic "no tariff configured at all"
 * message (see InvoiceCalculationService::resolvePriceInternal()'s own
 * "На выбранную дату тариф не настроен." fallback — unmodified) and, only
 * when the submission contained EXACTLY ONE priced item (so there is zero
 * ambiguity about which item's dimensions are relevant), builds a
 * friendlier message plus the query-string context an authorized caller
 * can use to link into the existing FeePrice admin screen.
 *
 * Deliberately does NOT fire for the mode-scoped (PR #80) or
 * payment-period (PR #83) ambiguity guards — those already name the exact
 * missing dimension themselves and must never be reworded here.
 *
 * Never resolves EnrollmentMode from client-submitted input — the caller
 * is responsible for passing only a server-resolved, authoritative
 * enrollment_mode_id (e.g. from the student's own active Enrollment, or
 * Quick Registration's own already-validated, required field).
 */
class MissingTariffGuidanceService
{
    private const GENERIC_MESSAGE = 'На выбранную дату тариф не настроен.';

    private const PERIOD_LABELS = [
        'once' => 'разово', 'daily' => 'ежедневно', 'monthly' => 'ежемесячно',
        'quarterly' => 'ежеквартально', 'term' => 'за семестр', 'yearly' => 'за год', 'package' => 'пакет',
    ];

    /**
     * @param  array<string, mixed>  $item  The single submitted item's own
     *                                      selection fields (fee_id, grade_id/grade_group,
     *                                      payment_period) — already validated by the caller's own
     *                                      FormRequest, never raw unvalidated input.
     * @return array{message: string, link_context: array<string, mixed>}|null
     *                                                                         null when this is not the recognized generic case.
     */
    public function describe(
        ValidationException $exception,
        array $item,
        int $academicYearId,
        ?int $enrollmentModeId,
    ): ?array {
        $messages = $exception->errors()['fees'] ?? [];
        if (! in_array(self::GENERIC_MESSAGE, $messages, true)) {
            return null;
        }

        $fee = Fee::find($item['fee_id'] ?? null);
        if (! $fee) {
            return null;
        }

        $gradeLabel = $this->gradeLabel($item);
        $mode = $enrollmentModeId ? EnrollmentMode::find($enrollmentModeId) : null;
        $periodLabel = self::PERIOD_LABELS[$item['payment_period'] ?? null] ?? null;

        $details = collect([
            "услуга «{$fee->name_ru}»",
            $gradeLabel,
            $mode ? "форма обучения «{$mode->name_ru}»" : null,
            $periodLabel ? "период «{$periodLabel}»" : null,
        ])->filter()->implode(', ');

        $message = 'Цена не настроена для выбранного учебного года, класса, формы обучения и периода оплаты.'
            .($details !== '' ? " ({$details})" : '');

        return [
            'message' => $message,
            'link_context' => array_filter([
                'fee_id' => $fee->id,
                'academic_year_id' => $academicYearId,
                'grade_id' => $item['grade_id'] ?? null,
                'grade_group' => $item['grade_group'] ?? null,
                'payment_period' => $item['payment_period'] ?? null,
                'enrollment_mode' => $mode?->code,
            ], fn ($value) => $value !== null && $value !== ''),
        ];
    }

    /**
     * The one place that turns a describe()-produced link_context into the
     * actual Add Price URL — shared by every caller (HasMissingTariffGuidance
     * and QuickStudentRegistrationController::price()'s own JSON response)
     * so the destination and query-string shape can never drift between
     * them. Points at the Dashboard-native tariff create screen, not the
     * separate Filament admin layout — that screen's own controller
     * (FinanceTariffController::create()) re-validates every one of these
     * values against real master data before using them as defaults.
     *
     * @param  array<string, mixed>  $linkContext
     */
    public function addPriceUrl(array $linkContext): string
    {
        return route('dashboard.finance.tariffs.create').'?'.http_build_query($linkContext);
    }

    private function gradeLabel(array $item): ?string
    {
        if (filled($item['grade_group'] ?? null)) {
            return "группа «{$item['grade_group']}»";
        }
        if (filled($item['grade_id'] ?? null)) {
            $grade = Grade::find($item['grade_id']);

            return $grade ? "класс «{$grade->name}»" : null;
        }

        return null;
    }
}
