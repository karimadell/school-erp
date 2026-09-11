<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin-manageable revenue categories. `code` is the stable, immutable
 * identifier UI logic keys off (e.g. showing the Student field only for
 * "fine") — never the mutable name_ru display label.
 */
class RevenueCategory extends Model
{
    const CODE_CAFETERIA = 'cafeteria';

    const CODE_DONATION = 'donation';

    const CODE_FINE = 'fine';

    const CODE_OTHER = 'other';

    protected $fillable = [
        'code',
        'name_ru',
        'name_ar',
        'name_en',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function revenueEntries()
    {
        return $this->hasMany(RevenueEntry::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
