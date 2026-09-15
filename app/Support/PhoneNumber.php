<?php

namespace App\Support;

class PhoneNumber
{
    public const E164_PATTERN = '/^\+[1-9]\d{7,14}$/';

    /**
     * Normalize a phone number to E.164, returning null when the result is not a valid E.164 number.
     *
     * An unencoded "+" in a query string (`?phone=+5511999990000`) arrives decoded as a space, so leading
     * spaces followed only by an E.164 body are read as the "+" sign.
     */
    public static function normalize(string $phone): ?string
    {
        if (preg_match('/^ +[1-9]\d{7,14}$/', $phone) === 1) {
            $phone = '+'.ltrim($phone, ' ');
        }

        $normalized = str_replace([' ', '.', '-', '(', ')'], '', trim($phone));

        return preg_match(self::E164_PATTERN, $normalized) === 1 ? $normalized : null;
    }

    /**
     * Format a phone number for display: Brazilian numbers as "+55 41 99812-3344", others unchanged.
     */
    public static function format(string $phone): string
    {
        $normalized = self::normalize($phone);

        if ($normalized === null || preg_match('/^\+55(\d{2})(\d{4,5})(\d{4})$/', $normalized, $matches) !== 1) {
            return $phone;
        }

        return "+55 {$matches[1]} {$matches[2]}-{$matches[3]}";
    }
}
