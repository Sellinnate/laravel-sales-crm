<?php

namespace Sellinnate\SalesCrm\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Phone numbers in E.164 (+393331234567). With giggsey/libphonenumber-for-php installed the full
 * rules of libphonenumber apply (national numbers read in `sales-crm.phone.default_region`);
 * without it a number is cleaned up, `00` becomes `+` and a national number takes the calling code
 * of `sales-crm.phone.default_calling_code`.
 */
final class Phone
{
    /**
     * The number in E.164, or null when it is not a phone number.
     */
    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || preg_match('/[a-z]{2,}/i', $value) === 1) {
            return null;
        }

        if (class_exists(PhoneNumberUtil::class)) {
            $util = PhoneNumberUtil::getInstance();

            try {
                $number = $util->parse($value, strtoupper((string) config('sales-crm.phone.default_region', 'IT')));
            } catch (NumberParseException) {
                return null;
            }

            return $util->isValidNumber($number) ? $util->format($number, PhoneNumberFormat::E164) : null;
        }

        $international = str_starts_with($value, '+') || str_starts_with($value, '00');
        $digits = (string) preg_replace('/\D+/', '', $value);

        if (str_starts_with($value, '00')) {
            $digits = substr($digits, 2);
        }

        if (! $international) {
            $code = (string) preg_replace('/\D+/', '', (string) config('sales-crm.phone.default_calling_code', ''));
            $digits = $code.$digits;
        }

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? '+'.$digits : null;
    }

    /**
     * A number as people read it: «+39 333 123 4567» with libphonenumber, the E.164 otherwise.
     */
    public static function display(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (class_exists(PhoneNumberUtil::class)) {
            $util = PhoneNumberUtil::getInstance();

            try {
                return $util->format($util->parse($value, strtoupper((string) config('sales-crm.phone.default_region', 'IT'))), PhoneNumberFormat::INTERNATIONAL);
            } catch (NumberParseException) {
                return $value;
            }
        }

        return $value;
    }
}
