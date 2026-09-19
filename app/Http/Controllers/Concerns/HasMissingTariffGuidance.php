<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Enrollment;
use App\Services\Finance\MissingTariffGuidanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Shared operator-UX enrichment for the already-established
 * "На выбранную дату тариф не настроен." fail-loud case (see
 * MissingTariffGuidanceService's own docblock — pricing itself is never
 * touched here). Used identically by every Classic Invoice / Unified
 * Collection / Quick Registration store() action so the recognition rule
 * and authorization gate can never drift between them.
 */
trait HasMissingTariffGuidance
{
    /**
     * @param  array<int, array<string, mixed>>  $items  The exact submitted
     *                                                   items array (already validated by the caller's own
     *                                                   FormRequest). Enrichment is only attempted when it contains
     *                                                   exactly one item — with more than one, which item's
     *                                                   dimensions actually failed cannot be attributed without
     *                                                   guessing, so the plain, unenriched redirect is returned
     *                                                   unchanged.
     * @param  ?int  $enrollmentModeId  The caller's own already-resolved,
     *                                  authoritative mode — the student's active Enrollment for
     *                                  Classic Invoice/Unified Collection, or the request's own
     *                                  required, validated field for Quick Registration. Never
     *                                  derived here from client input.
     */
    protected function withMissingTariffGuidance(
        RedirectResponse $redirect,
        ValidationException $exception,
        array $items,
        ?int $studentId,
        int $academicYearId,
        Request $request,
        ?int $enrollmentModeId = null,
    ): RedirectResponse {
        if (count($items) !== 1) {
            return $redirect;
        }

        if ($enrollmentModeId === null && $studentId !== null) {
            $enrollmentModeId = Enrollment::query()
                ->where('student_id', $studentId)
                ->where('academic_year_id', $academicYearId)
                ->where('is_active', true)
                ->value('enrollment_mode_id');
        }

        $service = app(MissingTariffGuidanceService::class);
        $guidance = $service->describe(
            $exception, $items[0], $academicYearId, $enrollmentModeId ? (int) $enrollmentModeId : null,
        );

        if (! $guidance) {
            return $redirect;
        }

        $redirect->with('missing_tariff_message', $guidance['message']);

        if ($request->user()?->can('manage fee prices')) {
            $redirect->with('missing_tariff_link', $service->addPriceUrl($guidance['link_context']));
        }

        return $redirect;
    }
}
