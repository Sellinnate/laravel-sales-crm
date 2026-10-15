<?php

namespace Sellinnate\SalesCrm\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use NumberFormatter;

/**
 * Money, numbers and dates as the UI, the exports and the history show them, in the locale of
 * `sales-crm.locale` (or the application locale) and the currency of `sales-crm.currency`.
 */
final class Format
{
    private const SYMBOLS = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'CHF' => 'CHF', 'JPY' => '¥'];

    public static function locale(): string
    {
        $locale = config('sales-crm.locale') ?: app()->getLocale();

        return is_string($locale) && $locale !== '' ? $locale : 'en';
    }

    public static function currency(): string
    {
        $currency = config('sales-crm.currency', 'EUR');

        return is_string($currency) && $currency !== '' ? strtoupper($currency) : 'EUR';
    }

    /**
     * An amount in cents, without decimals when it is a whole number: «€1,500», «1.500,50 €».
     */
    public static function money(?int $cents): string
    {
        if ($cents === null) {
            return '';
        }

        $decimals = $cents % 100 === 0 ? 0 : 2;
        $amount = $cents / 100;

        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter(self::locale(), NumberFormatter::CURRENCY);
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $decimals);
            $formatted = $formatter->formatCurrency($amount, self::currency());

            if (is_string($formatted)) {
                return str_replace("\u{00A0}", ' ', $formatted);
            }
        }

        $symbol = self::SYMBOLS[self::currency()] ?? self::currency();
        $number = self::number($amount, $decimals);

        return self::usesCommaDecimals() ? $number.' '.$symbol : $symbol.$number;
    }

    public static function number(float|int $value, int $decimals = 0): string
    {
        return self::usesCommaDecimals()
            ? number_format($value, $decimals, ',', '.')
            : number_format($value, $decimals, '.', ',');
    }

    public static function percent(float|int|null $value): string
    {
        return $value === null ? '—' : self::number($value, fmod((float) $value, 1.0) === 0.0 ? 0 : 1).'%';
    }

    public static function date(?DateTimeInterface $date): string
    {
        return $date === null ? '' : self::localized($date)->isoFormat('L');
    }

    public static function dateTime(?DateTimeInterface $date): string
    {
        return $date === null ? '' : self::localized($date)->isoFormat('L LT');
    }

    public static function time(?DateTimeInterface $date): string
    {
        return $date === null ? '' : self::localized($date)->isoFormat('LT');
    }

    /**
     * «15 Oct» / «15 ott»: the day and month of a date of the current year.
     */
    public static function shortDate(?DateTimeInterface $date): string
    {
        return $date === null ? '' : self::localized($date)->isoFormat('D MMM');
    }

    public static function month(DateTimeInterface $date): string
    {
        return self::localized($date)->isoFormat('MMM YYYY');
    }

    private static function usesCommaDecimals(): bool
    {
        return ! in_array(strtolower(substr(self::locale(), 0, 2)), ['en', 'ja', 'zh', 'ko', 'he', 'th'], true);
    }

    private static function localized(DateTimeInterface $date): CarbonImmutable
    {
        $carbon = self::carbon($date)->locale(self::locale());

        return $carbon instanceof CarbonImmutable ? $carbon : self::carbon($date);
    }

    private static function carbon(DateTimeInterface $date): CarbonImmutable
    {
        return $date instanceof CarbonImmutable ? $date : new CarbonImmutable($date->format('Y-m-d H:i:s.u'), $date->getTimezone());
    }
}
