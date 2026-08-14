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

        if (! $path || ! Storage::disk(config('filesystems.uploads.public'))->exists($path)) {
            return null;
        }

        $diskName = config('filesystems.uploads.public');
        $disk = Storage::disk($diskName);

        if (config("filesystems.disks.{$diskName}.driver") === 'local') {
            return $disk->path($path);
        }

        $mimeType = $disk->mimeType($path) ?: 'image/png';

        return sprintf('data:%s;base64,%s', $mimeType, base64_encode($disk->get($path)));
    }

    public function logoUrl(): ?string
    {
        return $this->publicAssetUrl($this->logo_path);
    }

    public function printingLogoUrl(): ?string
    {
        return $this->publicAssetUrl($this->printing_logo_path);
    }

    public function stampUrl(): ?string
    {
        return $this->publicAssetUrl($this->stamp_path);
    }

    public function directorSignatureUrl(): ?string
    {
        return $this->publicAssetUrl($this->director_signature_path);
    }

    private function publicAssetUrl(?string $path): ?string
    {
        return $path && Storage::disk(config('filesystems.uploads.public'))->exists($path)
            ? Storage::disk(config('filesystems.uploads.public'))->url($path)
            : null;
    }
}
