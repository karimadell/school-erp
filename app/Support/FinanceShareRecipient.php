<?php

namespace App\Support;

use App\Models\Student;

final class FinanceShareRecipient
{
    /**
     * @return array{phone: string, source: string}|null
     */
    public static function forStudent(?Student $student): ?array
    {
        if (! $student) {
            return null;
        }

        $student->loadMissing('representatives');
        $representative = $student->representatives
            ->sortByDesc(fn ($item) => (int) $item->is_primary_contact)
            ->first(fn ($item) => self::normalize($item->phone) !== null);

        if ($representative) {
            return [
                'phone' => self::normalize($representative->phone),
                'source' => 'representative',
            ];
        }

        $phone = self::normalize($student->phone);

        return $phone ? ['phone' => $phone, 'source' => 'student'] : null;
    }

    public static function normalize(?string $phone): ?string
    {
        $raw = trim((string) $phone);
        if ($raw === '') {
            return null;
        }

        $international = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === null) {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (! $international && preg_match('/^01\d{9}$/', $digits)) {
            $digits = '20'.substr($digits, 1);
        }

        return preg_match('/^[1-9]\d{7,14}$/', $digits) ? $digits : null;
    }

    public static function whatsappUrl(array $recipient, string $message): string
    {
        return 'https://wa.me/'.$recipient['phone'].'?text='.rawurlencode($message);
    }
}
