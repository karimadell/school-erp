<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Stolovaya Phase 2 (Employee cash purchases). Deliberately its own class,
 * not a subclass of any Student/Charge-and-Collect request hierarchy —
 * Employee's fields (employee_user_id/meal_plan_id/food_date/quantity)
 * don't map onto that Student/Invoice-shaped contract at all.
 *
 * Authorization lives in EmployeeFoodPurchaseService::purchase() ('manage
 * employee stolovaya'), not here — this class only shapes/validates the
 * input, matching this project's existing convention (see
 * StoreStolovayaChargeRequest, StoreChargeAndCollectRequest).
 */
class StoreEmployeeFoodPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Defense in depth, matching StoreChargeAndCollectRequest's own
        // convention: the controller's constructor middleware already
        // gates this route on the same permission, but the check is
        // repeated here too.
        return $this->user()?->can('manage employee stolovaya') ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_user_id' => ['required', 'integer', 'exists:users,id'],
            'food_date' => ['required', 'date_format:Y-m-d'],
            'meal_plan_id' => ['required', 'integer', 'exists:meal_plans,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'cash_account_id' => ['required', 'integer', 'exists:cash_accounts,id'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
