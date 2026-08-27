<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class FeePrice extends Model
{
    public const GRADE_GROUPS = [
        'Подготовительный класс',
        '1–4 классы',
        '5–6 классы',
        '7–8 классы',
        '9–11 классы',
    ];

    protected $attributes = [
        'currency' => 'EGP',
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $price): void {
            if ($price->currency !== 'EGP') {
                throw ValidationException::withMessages(['currency' => 'Для цен используется валюта EGP.']);
            }
            if (bccomp((string) $price->amount, '0.00', 2) <= 0) {
                throw ValidationException::withMessages(['amount' => 'Цена должна быть больше нуля.']);
            }
            if ($price->end_date && $price->end_date->lt($price->start_date)) {
                throw ValidationException::withMessages(['end_date' => 'Дата окончания не может быть раньше даты начала.']);
            }
            if ($price->academic_year_id) {
                $year = AcademicYear::find($price->academic_year_id);
                if (! $year || $price->start_date->gt($year->end_date)
                    || ($price->end_date && $price->end_date->gt($year->end_date))) {
                    throw ValidationException::withMessages(['academic_year_id' => 'Тариф может начинаться до учебного года, но не может начинаться или заканчиваться после его окончания.']);
                }
            }
        });
    }

    protected $fillable = [
        'fee_id',
        'academic_year_id',
        'grade_id',

        // للتعليم: Подготовительный / 1-4 / 5-6 / 7-8 / 9-11
        'grade_group',

        // monthly / quarterly / yearly / daily / once
        'payment_period',

        'amount',
        'currency',
        'start_date',
        'end_date',

        // للترانسفير / اليونيفورم / خيارات أخرى
        'option_type',
        'option_value',
        'size',
        'item',

        'notes',
        'change_reason',
        'is_active',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function fee()
    {
        return $this->belongsTo(Fee::class);
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function scopeForAcademicYear($query, int $academicYearId)
    {
        return $query->where('academic_year_id', $academicYearId);
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeCurrent($query, $date = null)
    {
        $date = $date ?? now()->toDateString();

        return $query
            ->whereDate('start_date', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('end_date')
                  ->orWhereDate('end_date', '>=', $date);
            });
    }

    /**
     * The single shared "is this row actually chargeable right now" scope:
     * active, EGP (the only currency InvoiceCalculationService ever bills
     * in), and date-current. Every place that needs to know whether a fee
     * has a usable tariff — the resolver itself, the classic Invoice
     * Create preview payload, and Quick Registration's availability
     * gating — composes this scope instead of re-deriving it, so they
     * cannot silently drift from each other again.
     */
    public function scopeSellable($query, $date = null)
    {
        return $query->active()->current($date)->where('currency', 'EGP');
    }

    public function status(?string $date = null): string
    {
        $date = \Illuminate\Support\Carbon::parse($date ?? now()->toDateString());
        if (! $this->is_active) {
            return 'inactive';
        }
        if ($this->start_date->gt($date)) {
            return 'future';
        }
        if ($this->end_date && $this->end_date->lt($date)) {
            return 'expired';
        }

        return 'current';
    }

    public function getDisplayNameAttribute(): string
    {
        $parts = [];

        if ($this->fee?->name_ru) {
            $parts[] = $this->fee->name_ru;
        }

        if ($this->grade_group) {
            $parts[] = $this->grade_group;
        }

        if ($this->payment_period) {
            $parts[] = $this->payment_period;
        }

        if ($this->option_type && $this->option_value) {
            $parts[] = $this->option_type . ': ' . $this->option_value;
        }

        if ($this->size) {
            $parts[] = 'Размер: ' . $this->size;
        }

        if ($this->item) {
            $parts[] = 'Предмет: ' . $this->item;
        }

        return implode(' / ', $parts);
    }
}
