<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SchoolSetting extends Model
{
    public const SINGLETON_ID = 1;

    private const BRANDING_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

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
        $asset = $this->documentLogoAsset();

        if (! $asset) {
            return null;
        }

        $diskName = config('filesystems.uploads.public');
        $disk = Storage::disk($diskName);

        if (config("filesystems.disks.{$diskName}.driver") === 'local') {
            return $disk->path($asset['path']);
        }

        return $asset['data_uri'];
    }

    /** @return array{path: string, mime_type: string, data_uri: string, width: int, height: int}|null */
    public function documentLogoAsset(): ?array
    {
        return $this->resolveBrandingAsset($this->printing_logo_path ?: $this->logo_path);
    }

    /** @return array{path: string, mime_type: string, data_uri: string, width: int, height: int}|null */
    public function stampAsset(): ?array
    {
        return $this->resolveBrandingAsset($this->stamp_path);
    }

    /** @return array{path: string, mime_type: string, data_uri: string, width: int, height: int}|null */
    public function directorSignatureAsset(): ?array
    {
        return $this->resolveBrandingAsset($this->director_signature_path);
    }

    /** @return array{path: string, mime_type: string, data_uri: string, width: int, height: int}|null */
    public function resolveBrandingAsset(?string $path): ?array
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        try {
            $contents = Storage::disk(config('filesystems.uploads.public'))->get($path);
        } catch (\Throwable) {
            return null;
        }

        if ($contents === '') {
            return null;
        }

        $imageInfo = @getimagesizefromstring($contents);
        $mimeType = is_array($imageInfo) ? ($imageInfo['mime'] ?? null) : null;

        if (! is_string($mimeType) || ! in_array($mimeType, self::BRANDING_MIME_TYPES, true)) {
            return null;
        }

        return [
            'path' => $path,
            'mime_type' => $mimeType,
            'data_uri' => sprintf('data:%s;base64,%s', $mimeType, base64_encode($contents)),
            'width' => (int) $imageInfo[0],
            'height' => (int) $imageInfo[1],
        ];
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
        $asset = $this->resolveBrandingAsset($path);

        if (! $asset) {
            return null;
        }

        try {
            return Storage::disk(config('filesystems.uploads.public'))->url($asset['path']);
        } catch (\Throwable) {
            return null;
        }
    }
}
