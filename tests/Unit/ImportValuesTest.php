<?php

use Sellinnate\SalesCrm\Enums\PaymentStatus;
use Sellinnate\SalesCrm\Enums\RecurringPeriod;
use Sellinnate\SalesCrm\Enums\SignatureStatus;
use Sellinnate\SalesCrm\Services\Import\ImportValues;

it('reads amounts only when they are readable', function (string $text, ?int $cents) {
    expect(ImportValues::cents($text))->toBe($cents);
})->with([
    ['1500', 150000],
    ['1.500', 150000],
    ['1,500', 150000],
    ['1.500,50', 150050],
    ['1,500.50', 150050],
    ['€ 1500', 150000],
    ['$2,000', 200000],
    ['2.000 €', 200000],
    ['1500 EUR', 150000],
    ['euro 1500', 150000],
    ['1.200 Euro', 120000],
    ['12,5', 1250],
    ['about 1500', null],
    ['1500 or 2000', null],
    ['', null],
]);

it('reads a recurring amount only with one amount and one period', function (string $text, ?array $expected) {
    $read = ImportValues::recurring($text);

    expect($read === null ? null : [$read['cents'], $read['period']])->toBe($expected);
})->with([
    ['3.000 €/anno', [300000, RecurringPeriod::Yearly]],
    ['79 € al mese', [7900, RecurringPeriod::Monthly]],
    ['$49 per month', [4900, RecurringPeriod::Monthly]],
    ['79 €/mese o 869 €/anno', null],
    ['10 hours at 50 €/month', null],
    ['-79 €/month', null],
    ['nothing', null],
]);

it('reads dates in known forms only', function (string $text, bool $monthFirst, ?string $date) {
    expect(ImportValues::date($text, $monthFirst))->toBe($date);
})->with([
    ['2026-10-13', false, '2026-10-13'],
    ['13/10/2026', false, '2026-10-13'],
    ['10/13/2026', true, '2026-10-13'],
    ['13 ottobre 2026', false, '2026-10-13'],
    ['13 October 2026', false, '2026-10-13'],
    ['October 13, 2026', false, '2026-10-13'],
    ['31/02/2026', false, null],
    ['2 ottobre scaduta', false, null],
]);

it('reads numbers, booleans and priorities without guessing', function () {
    expect(ImportValues::number('45'))->toBe(45)
        ->and(ImportValues::number('1,5'))->toBe(1.5)
        ->and(ImportValues::number('1.234'))->toBeNull()
        ->and(ImportValues::number('1.234.567'))->toBe(1234567.0)
        ->and(ImportValues::number('1,234,567'))->toBe(1234567.0)
        ->and(ImportValues::boolean('Sì'))->toBeTrue()
        ->and(ImportValues::boolean('no'))->toBeFalse()
        ->and(ImportValues::boolean('maybe'))->toBeNull()
        ->and(ImportValues::priority('P 2'))->toBe('p2')
        ->and(ImportValues::priority('escluso'))->toBe('excluded')
        ->and(ImportValues::priority('high'))->toBeNull();
});

it('never imports a signature or a payment as verified', function () {
    expect(ImportValues::signature('Signed')['status'])->toBe(SignatureStatus::ToSign)
        ->and(ImportValues::signature('Signed')['warning'])->not->toBeNull()
        ->and(ImportValues::signature('non firmato')['status'])->toBe(SignatureStatus::ToSign)
        ->and(ImportValues::signature('non firmato')['warning'])->toBeNull()
        ->and(ImportValues::signature('?'))->toBeNull()
        ->and(ImportValues::payment('Paid')['status'])->toBe(PaymentStatus::Expected)
        ->and(ImportValues::payment('Paid')['warning'])->not->toBeNull()
        ->and(ImportValues::payment('not paid yet')['warning'])->toBeNull()
        ->and(ImportValues::payment('da incassare')['status'])->toBe(PaymentStatus::Expected);
});
