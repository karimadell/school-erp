<?php

namespace App\Http\Requests;

use App\Models\Fee;

/**
 * Столовая (Student, Phase 1) — a thin, narrower specialization of
 * StoreChargeAndCollectRequest: the exact same Food charge/collect
 * validation and item-shaping, with the operator-chosen inputs reduced to
 * only what the dedicated Stolovaya screen exposes (meal + date). fee_id,
 * food_duration_mode and quantity are never taken from client input here —
 * they are always server-resolved/fixed, matching this screen's single
 * purpose (one canonical Food fee, always a single day, never a
 * client-supplied multiplier). Authorization is inherited unchanged
 * ('manage invoices' — the same gate chargeCreate()/chargeStore() require).
 */
class StoreStolovayaChargeRequest extends StoreChargeAndCollectRequest
{
    protected function prepareForValidation(): void
    {
        $foodFee = Fee::active()->where('category', Fee::CATEGORY_FOOD)->first();

        $this->merge([
            'fee_id' => $foodFee?->id,
            'food_duration_mode' => 'day',
            'quantity' => 1,
        ]);

        parent::prepareForValidation();
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'meal_plan_id' => ['required', 'integer', 'exists:meal_plans,id'],
            'food_date' => ['required', 'date_format:Y-m-d'],
        ]);
    }
}
