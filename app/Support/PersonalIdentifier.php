<?php

namespace App\Support;

class PersonalIdentifier
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = preg_replace('/\D+/u', '', $value);

        return $normalized === '' ? null : $normalized;
    }

    public static function validSnils(?string $value): bool
    {
        $digits = self::normalize($value);
        if ($digits === null || strlen($digits) !== 11) {
            return false;
        }

        $sum = 0;
        for ($index = 0; $index < 9; $index++) {
            $sum += (int) $digits[$index] * (9 - $index);
        }
        $checksum = $sum < 100 ? $sum : ($sum === 100 || $sum === 101 ? 0 : $sum % 101);
        if ($checksum === 100) {
            $checksum = 0;
        }

        return (int) substr($digits, 9, 2) === $checksum;
    }

    public static function validInn(?string $value): bool
    {
        $digits = self::normalize($value);
        if ($digits === null || ! in_array(strlen($digits), [10, 12], true)) {
            return false;
        }

        if (strlen($digits) === 10) {
            return (int) $digits[9] === self::innChecksum($digits, [2, 4, 10, 3, 5, 9, 4, 6, 8]);
        }

        return (int) $digits[10] === self::innChecksum($digits, [7, 2, 4, 10, 3, 5, 9, 4, 6, 8])
            && (int) $digits[11] === self::innChecksum($digits, [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8]);
    }

    private static function innChecksum(string $digits, array $weights): int
    {
        $sum = 0;
        foreach ($weights as $index => $weight) {
            $sum += (int) $digits[$index] * $weight;
        }

        return ($sum % 11) % 10;
    }
}
