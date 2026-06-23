<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fee extends Model
{
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
        'billing_period',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'base_price' => 'decimal:2',
        'effective_from' => 'date',
        'is_active' => 'boolean',
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