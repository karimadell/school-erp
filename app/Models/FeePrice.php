<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class FeePrice extends Model
{
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
                if (! $year || $price->start_date->lt($year->start_date) || $price->start_date->gt($year->end_date)
                    || ($price->end_date && $price->end_date->gt($year->end_date))) {
                    throw ValidationException::withMessages(['academic_year_id' => 'Период действия цены должен находиться в пределах учебного года.']);
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
