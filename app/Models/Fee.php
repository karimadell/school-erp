<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fee extends Model
{
    protected $attributes = ['is_non_refundable' => false];

    public const CATEGORY_TUITION = 'tuition';
    public const CATEGORY_TUITION_REGULAR = 'tuition_regular';
    public const CATEGORY_TUITION_FAMILY = 'tuition_family';
    public const CATEGORY_TUITION_EXTERNAL = 'tuition_external';

    public const CATEGORY_REGISTRATION = 'registration';
    public const CATEGORY_TRANSPORT = 'transport';
    public const CATEGORY_FOOD = 'food';
    public const CATEGORY_UNIFORM = 'uniform';
    public const CATEGORY_EXTRA_CLASSES = 'extra_classes';

    public const CATEGORY_BOOKS = 'books';
    public const CATEGORY_ACTIVITY = 'activity';
    public const CATEGORY_OTHER = 'other';

    public const PERIOD_ONCE = 'once';
    public const PERIOD_DAILY = 'daily';
    public const PERIOD_MONTHLY = 'monthly';
    public const PERIOD_QUARTERLY = 'quarterly';
    public const PERIOD_TERM = 'term';
    public const PERIOD_YEARLY = 'yearly';
    public const PERIOD_PACKAGE = 'package';

    protected $fillable = [
        'name_ar',
        'name_en',
        'name_ru',
        'type',
        'category',
        'grade_id',
        'payment_period',
        'amount',
        'base_price',
        'effective_from',
        'description',
        'is_active',
        'is_non_refundable',
        'billing_period',
        'exempt_from_balance_block',
        'is_test_data',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'base_price' => 'decimal:2',
        'effective_from' => 'date',
        'is_active' => 'boolean',
        'is_non_refundable' => 'boolean',
        'exempt_from_balance_block' => 'boolean',
        'is_test_data' => 'boolean',
    ];

    public function invoices()
    {
        return $this->belongsToMany(Invoice::class, 'invoice_fee')
            ->withPivot('amount')
            ->withTimestamps();
    }

    public function prices()
    {
        return $this->hasMany(FeePrice::class);
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(StudentServiceSubscription::class);
    }

    /**
     * Finance V2, Phase 2B — which billing periods this Fee allows
     * (once/monthly/quarterly/yearly/custom_plan). A Fee may allow several
     * at once (e.g. Tuition: monthly, quarterly, yearly).
     */
    public function billingPeriods()
    {
        return $this->hasMany(FeeBillingPeriod::class);
    }

    /**
     * Finance V2, Phase 2B — PaymentPlans explicitly assigned to this Fee.
     * Only meaningful when billingPeriods() includes 'custom_plan'. A
     * PaymentPlan is NEVER offered to a Fee it isn't explicitly assigned to
     * here — this is the fix for the reported bug where every active
     * PaymentPlan was offered to every Fee unconditionally.
     */
    public function assignedPaymentPlans()
    {
        return $this->belongsToMany(PaymentPlan::class, 'fee_payment_plan');
    }

    /**
     * Whether this Fee allows the given billing period. Uses the loaded
     * billingPeriods relation if already eager-loaded (no extra query);
     * falls back to a query otherwise.
     */
    public function allowsBillingPeriod(string $period): bool
    {
        if ($this->relationLoaded('billingPeriods')) {
            return $this->billingPeriods->contains('billing_period', $period);
        }

        return $this->billingPeriods()->where('billing_period', $period)->exists();
    }

    /**
     * Finance V2, Phase 2B corrective pass (Quick Registration parity) —
     * the canonical set of period strings this Fee is allowed to bill
     * under, per FeeBillingPeriod, regardless of whether a FeePrice row
     * happens to exist yet for every one of them. A period this Fee
     * allows but has no explicit tariff for is still offerable — the
     * pricing resolver (InvoiceCalculationService) derives a quarterly
     * amount from monthly × 3 in that exact case. Uses the loaded
     * billingPeriods relation if already eager-loaded (no extra query).
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public function allowedBillingPeriods(): \Illuminate\Support\Collection
    {
        if ($this->relationLoaded('billingPeriods')) {
            return $this->billingPeriods->pluck('billing_period')->unique()->values();
        }

        return $this->billingPeriods()->pluck('billing_period')->unique()->values();
    }

    /**
     * If this Fee is a uniform bundle, the items that make it up.
     */
    public function bundleComponents()
    {
        return $this->hasMany(UniformBundleComponent::class, 'bundle_fee_id');
    }

    /**
     * If this Fee is an individual uniform item, the bundles it belongs to.
     */
    public function partOfBundles()
    {
        return $this->hasMany(UniformBundleComponent::class, 'item_fee_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getNameAttribute()
    {
        return $this->name_ru;
    }

    public function getCurrentAmountAttribute()
    {
        return $this->currentPrice();
    }

    public function currentPrice($date = null): float
    {
        $date = $date ?? now()->toDateString();

        $price = $this->prices()
            ->active()
            ->current($date)
            ->orderByDesc('start_date')
            ->first();

        return (float) ($price?->amount ?? $this->amount ?? $this->base_price ?? 0);
    }

    public function priceForDate($date): float
    {
        return $this->currentPrice($date);
    }

    public function priceForSelection(
        ?string $gradeGroup = null,
        ?string $paymentPeriod = null,
        ?string $size = null,
        ?string $item = null,
        ?string $optionType = null,
        ?string $optionValue = null,
        $date = null
    ): float {
        $date = $date ?? now()->toDateString();

        $query = $this->prices()
            ->active()
            ->current($date);

        if ($gradeGroup) {
            $query->where('grade_group', $gradeGroup);
        }

        if ($paymentPeriod) {
            $query->where('payment_period', $paymentPeriod);
        }

        if ($size) {
            $query->where('size', $size);
        }

        if ($item) {
            $query->where('item', $item);
        }

        if ($optionType) {
            $query->where('option_type', $optionType);
        }

        if ($optionValue) {
            $query->where('option_value', $optionValue);
        }

        $price = $query
            ->orderByDesc('start_date')
            ->first();

        return (float) ($price?->amount ?? $this->amount ?? $this->base_price ?? 0);
    }

    public function latestPriceRecord(
        ?string $gradeGroup = null,
        ?string $paymentPeriod = null,
        ?string $size = null,
        ?string $item = null,
        ?string $optionType = null,
        ?string $optionValue = null,
        $date = null
    ): ?FeePrice {
        $date = $date ?? now()->toDateString();

        $query = $this->prices()
            ->active()
            ->current($date);

        if ($gradeGroup) {
            $query->where('grade_group', $gradeGroup);
        }

        if ($paymentPeriod) {
            $query->where('payment_period', $paymentPeriod);
        }

        if ($size) {
            $query->where('size', $size);
        }

        if ($item) {
            $query->where('item', $item);
        }

        if ($optionType) {
            $query->where('option_type', $optionType);
        }

        if ($optionValue) {
            $query->where('option_value', $optionValue);
        }

        return $query
            ->orderByDesc('start_date')
            ->first();
    }
}
