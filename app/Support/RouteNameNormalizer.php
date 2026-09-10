<?php

namespace App\Support;

use Illuminate\Support\Str;
use Normalizer;

final class RouteNameNormalizer
{
    private const CANONICAL_NAMES = [
        'Арабия',
        'Бествэй',
        'Бритиш',
        'Каусер',
        'Эль Ахья',
    ];

    public static function normalize(?string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
        if ($normalized === false) {
            throw new \UnexpectedValueException('Route name could not be normalized to NFC.');
        }

        return $normalized;
    }

    public static function key(?string $value): string
    {
        return Str::lower(self::normalize($value));
    }

    public static function canonicalName(?string $value): string
    {
        $key = self::key($value);

        foreach (self::CANONICAL_NAMES as $name) {
            if (self::key($name) === $key) {
                return $name;
            }
        }

        return self::normalize($value);
    }
}
