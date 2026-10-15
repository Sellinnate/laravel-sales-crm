<?php

namespace Sellinnate\SalesCrm\Services\Import;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Sellinnate\SalesCrm\Enums\DealSource;
use Sellinnate\SalesCrm\Enums\PaymentStatus;
use Sellinnate\SalesCrm\Enums\RecurringPeriod;
use Sellinnate\SalesCrm\Enums\SignatureStatus;

/**
 * The cautious reading of the cells of an imported sheet: a value that cannot be read for sure is
 * never made up — the importer keeps the text where it can (a note, the options).
 */
class ImportValues
{
    private const MONTHS = [
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
        'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        'gennaio' => 1, 'febbraio' => 2, 'marzo' => 3, 'aprile' => 4, 'maggio' => 5, 'giugno' => 6, 'luglio' => 7, 'agosto' => 8,
        'settembre' => 9, 'ottobre' => 10, 'novembre' => 11, 'dicembre' => 12,
    ];

    /**
     * An amount in cents when the cell is just a readable amount: «1500», «1.500», «1,500», «1.500,50»,
     * «1,500.50», «€ 1500», «$2,000», «2.000 €», «1500 EUR». A lone separator followed by three digits
     * is a thousands separator.
     */
    public static function cents(string $text): ?int
    {
        $text = trim((string) preg_replace('/^(eur|euro|usd|gbp|chf)\s*|\s*(eur|euro|usd|gbp|chf)\.?$/iu', '', trim($text)));
        $text = trim(str_replace(['€', '$', '£', "\u{00A0}", ' '], '', $text));

        if ($text === '' || preg_match('/^\d{1,3}([.,]\d{3})*([.,]\d{1,2})?$|^\d+([.,]\d{1,2})?$/', $text) !== 1) {
            return null;
        }

        // The last separator followed by one or two digits is the decimal one; the others group thousands.
        if (preg_match('/^(.*?)[.,](\d{1,2})$/', $text, $matches) === 1) {
            $whole = str_replace(['.', ','], '', $matches[1]);
            $decimals = str_pad($matches[2], 2, '0');
        } else {
            $whole = str_replace(['.', ','], '', $text);
            $decimals = '00';
        }

        return $whole === '' ? null : (int) $whole * 100 + (int) $decimals;
    }

    /**
     * A number written without doubt: «45», «1,5», «1.25», «1.234.567», «1,234,567». A lone separator
     * followed by three digits («1.234») may be a thousand or a decimal: it is not read.
     */
    public static function number(string $text): int|float|null
    {
        $text = str_replace(["\u{00A0}", ' '], '', trim($text));

        return match (true) {
            preg_match('/^-?\d+$/', $text) === 1 => (int) $text,
            preg_match('/^-?\d+[.,](\d{1,2}|\d{4,})$/', $text) === 1 => (float) str_replace(',', '.', $text),
            preg_match('/^-?\d{1,3}(\.\d{3}){2,}(,\d+)?$|^-?\d{1,3}(\.\d{3})+,\d+$/', $text) === 1 => (float) str_replace(['.', ','], ['', '.'], $text),
            preg_match('/^-?\d{1,3}(,\d{3}){2,}(\.\d+)?$|^-?\d{1,3}(,\d{3})+\.\d+$/', $text) === 1 => (float) str_replace(',', '', $text),
            default => null,
        };
    }

    /**
     * A recurring amount when the text has exactly one amount and one period («3,000 €/year»,
     * «79 € al mese», «$49 per month»).
     *
     * @return array{cents: int, period: RecurringPeriod}|null
     */
    public static function recurring(string $text): ?array
    {
        if (preg_match('/[-−]\s*\d/u', $text) === 1) {
            return null;
        }

        $amounts = preg_match_all('/\d{1,3}(?:[.,]\d{3})+(?:[.,]\d{1,2})?|\d+(?:[.,]\d{1,2})?/u', $text, $matches);
        $monthly = preg_match_all('/\/\s*(mese|month|mo)\b|al\s+mese|per\s+month|monthly|mensil|\bmese\b|\bmonth\b/iu', $text);
        $yearly = preg_match_all('/\/\s*(anno|year|yr)\b|all\'?\s*anno|per\s+year|yearly|annual|\banno\b|\byear\b/iu', $text);

        if ($amounts !== 1 || ($monthly > 0) === ($yearly > 0)) {
            return null;
        }

        $cents = self::cents($matches[0][0]);

        return $cents === null || $cents === 0 ? null : ['cents' => $cents, 'period' => $monthly > 0 ? RecurringPeriod::Monthly : RecurringPeriod::Yearly];
    }

    /**
     * A date when the cell is a date in a known form, as Y-m-d: «2026-10-13», «13/10/2026» (day first,
     * or month first with `$monthFirst`), «13 October 2026», «13 ottobre 2026», «October 13, 2026».
     */
    public static function date(string $text, bool $monthFirst = false): ?string
    {
        $text = Str::lower(trim($text));
        $months = implode('|', array_keys(self::MONTHS));

        $parts = match (true) {
            preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ t]\d{2}:\d{2}(?::\d{2})?)?$/', $text, $m) === 1 => [(int) $m[1], (int) $m[2], (int) $m[3]],
            preg_match('/^(\d{1,2})[\/.](\d{1,2})[\/.](\d{4})$/', $text, $m) === 1 => $monthFirst ? [(int) $m[3], (int) $m[1], (int) $m[2]] : [(int) $m[3], (int) $m[2], (int) $m[1]],
            preg_match('/^(\d{1,2})\s+('.$months.')\s+(\d{4})$/u', $text, $m) === 1 => [(int) $m[3], self::MONTHS[$m[2]], (int) $m[1]],
            preg_match('/^('.$months.')\s+(\d{1,2}),?\s+(\d{4})$/u', $text, $m) === 1 => [(int) $m[3], self::MONTHS[$m[1]], (int) $m[2]],
            default => null,
        };

        if ($parts === null || ! checkdate($parts[1], $parts[2], $parts[0])) {
            return null;
        }

        return CarbonImmutable::create($parts[0], $parts[1], $parts[2])?->toDateString();
    }

    public static function boolean(string $text): ?bool
    {
        $text = Str::lower(trim($text));

        return match (true) {
            in_array($text, ['yes', 'y', 'true', 'x', '1', 'si', 'sì', 'vero'], true) => true,
            in_array($text, ['no', 'n', 'false', '0', 'falso'], true) => false,
            default => null,
        };
    }

    /**
     * P1 – P4, «excluded».
     */
    public static function priority(string $text): ?string
    {
        $text = Str::lower(trim($text));

        return match (true) {
            preg_match('/^p\s*([1-4])$/', $text, $m) === 1 => 'p'.$m[1],
            in_array($text, ['excluded', 'escluso', 'esclusa'], true) => 'excluded',
            default => null,
        };
    }

    /**
     * The signature from its label. «Signed» is imported as «to sign»: the evidence is not imported,
     * and a warning says so. A label not read is null: the deal keeps what it has.
     *
     * @return array{status: SignatureStatus, warning: string|null}|null
     */
    public static function signature(string $text): ?array
    {
        $text = Str::lower(trim($text));

        return match (true) {
            $text === '' || str_contains($text, 'not verified') || str_contains($text, 'non verificat') => ['status' => SignatureStatus::NotVerified, 'warning' => null],
            str_contains($text, 'to sign') || str_contains($text, 'da firmare') || str_contains($text, 'in firma')
                || preg_match('/\b(not|non)\s+(yet\s+|ancora\s+)?(signed|firmat)/u', $text) === 1 => ['status' => SignatureStatus::ToSign, 'warning' => null],
            str_contains($text, 'signed') || str_contains($text, 'firmat') => ['status' => SignatureStatus::ToSign, 'warning' => (string) __('sales-crm::import.warnings.signature', ['value' => $text])],
            default => null,
        };
    }

    /**
     * The payment from its label. A payment received is imported as «expected»: the evidence is not
     * imported, and a warning says so.
     *
     * @return array{status: PaymentStatus, warning: string|null}|null
     */
    public static function payment(string $text): ?array
    {
        $text = Str::lower(trim($text));

        return match (true) {
            $text === '' || str_contains($text, 'not verified') || str_contains($text, 'non verificat') => ['status' => PaymentStatus::NotVerified, 'warning' => null],
            preg_match('/\b(not|non)\s+(yet\s+|ancora\s+)?(paid|collected|pagat|incassat)/u', $text) === 1 => ['status' => PaymentStatus::Expected, 'warning' => null],
            str_contains($text, 'paid') || str_contains($text, 'collected') || str_contains($text, 'partial')
                || str_contains($text, 'incassat') || str_contains($text, 'pagat') || str_contains($text, 'parzial') => ['status' => PaymentStatus::Expected, 'warning' => (string) __('sales-crm::import.warnings.payment', ['value' => $text])],
            str_contains($text, 'expected') || str_contains($text, 'due') || str_contains($text, 'attes') || str_contains($text, 'da incassare') => ['status' => PaymentStatus::Expected, 'warning' => null],
            default => null,
        };
    }

    public static function source(string $text): ?DealSource
    {
        $text = Str::lower(trim($text));

        foreach (DealSource::cases() as $source) {
            if ($text === $source->value || $text === Str::lower($source->label())) {
                return $source;
            }
        }

        return null;
    }
}
