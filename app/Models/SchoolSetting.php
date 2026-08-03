<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SchoolSetting extends Model
{
    public const SINGLETON_ID = 1;

    protected $guarded = ['id'];

    protected $casts = [
        'print_date_enabled' => 'boolean',
        'page_numbers_enabled' => 'boolean',
        'decimal_places' => 'integer',
        'school_year_start' => 'date',
        'school_year_end' => 'date',
    ];

    public static function current(): self
    {
        return static::query()->findOrFail(self::SINGLETON_ID);
    }

    public function defaultAcademicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'default_academic_year_id');
    }

    public function documentLogoPath(): ?string
    {
        $path = $this->printing_logo_path ?: $this->logo_path;

        return $path && Storage::disk('public')->exists($path)
            ? Storage::disk('public')->path($path)
            : null;
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path && Storage::disk('public')->exists($this->logo_path)
            ? Storage::disk('public')->url($this->logo_path)
            : null;
    }
}
