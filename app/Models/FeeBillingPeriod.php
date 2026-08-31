<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Finance V2, Phase 2B — one allowed billing period for a Fee.
 * A Fee can have several of these (e.g. Tuition allowing monthly,
 * quarterly, and yearly at once).
 */
class FeeBillingPeriod extends Model
{
    public const PERIOD_ONCE = 'once';

    public const PERIOD_MONTHLY = 'monthly';

    public const PERIOD_QUARTERLY = 'quarterly';

    public const PERIOD_YEARLY = 'yearly';

    public const PERIOD_CUSTOM_PLAN = 'custom_plan';

    public const CALENDAR_PERIODS = [self::PERIOD_MONTHLY, self::PERIOD_QUARTERLY, self::PERIOD_YEARLY];

    /** Russian display labels — used in validation messages naming the specific rejected period. */
    public const PERIOD_LABELS = [
        self::PERIOD_ONCE => 'разово',
        self::PERIOD_MONTHLY => 'ежемесячно',
        self::PERIOD_QUARTERLY => 'ежеквартально',
        self::PERIOD_YEARLY => 'ежегодно',
        self::PERIOD_CUSTOM_PLAN => 'индивидуальный план',
    ];

    protected $fillable = ['fee_id', 'billing_period'];

    public function fee()
    {
        return $this->belongsTo(Fee::class);
    }
}
