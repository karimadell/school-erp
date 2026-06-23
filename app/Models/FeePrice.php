<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeePrice extends Model
{
    protected $fillable = [
        'fee_id',
        'grade_id',

        // للتعليم: Подготовительный / 1-4 / 5-6 / 7-8 / 9-11
        'grade_group',

        // monthly / quarterly / yearly / daily / once
        'payment_period',

        'amount',
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