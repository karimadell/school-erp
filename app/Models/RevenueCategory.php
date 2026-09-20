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

    // Owner-approved Finance category separation (pre-go-live): School
    // Food and Buffet are two distinct operational activities and must
    // never share a revenue category with each other or with the legacy,
    // deliberately-untouched CODE_CAFETERIA row.
    const CODE_SCHOOL_FOOD = 'school_food';

    const CODE_BUFFET = 'buffet';

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
