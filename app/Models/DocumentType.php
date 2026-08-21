<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentType extends Model
{
    public const HOLDER_STUDENT = 'student';

    public const HOLDER_REPRESENTATIVE = 'representative';

    public const HOLDER_EITHER = 'either';

    protected $guarded = [];

    protected $casts = [
        'is_identity_document' => 'boolean',
        'supports_series' => 'boolean',
        'supports_subdivision_code' => 'boolean',
        'supports_expiration' => 'boolean',
        'requires_expiration' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function studentFiles()
    {
        return $this->hasMany(StudentFile::class);
    }

    public function getLocalizedNameAttribute(): string
    {
        $locale = in_array(app()->getLocale(), ['ru', 'en', 'ar'], true) ? app()->getLocale() : 'ru';

        return $this->{"name_{$locale}"} ?: $this->name_ru;
    }
}
