<?php

use Sellinnate\SalesCrm\Services\Matcher;

it('normalises company names without legal forms, accents and punctuation', function (string $name, string $key) {
    expect(Matcher::nameKey($name))->toBe($key);
})->with([
    ['Acme S.r.l.', 'acme'],
    ['ACME srl', 'acme'],
    ['Café Rossi & Figli S.p.A.', 'cafe rossi figli'],
    ['Northwind Traders Ltd', 'northwind traders'],
    ['  Blue   Harbor, Inc. ', 'blue harbor'],
    ['S.r.l.', 'srl'],
]);

it('normalises VAT numbers dropping the IT prefix of Italian ones', function (?string $vat, ?string $key) {
    expect(Matcher::vatKey($vat))->toBe($key);
})->with([
    ['IT 01234567890', '01234567890'],
    ['01234567890', '01234567890'],
    ['de123456789', 'DE123456789'],
    ['', null],
    [null, null],
]);

it('reads the domain of a company from its website, else from a business email', function (?string $website, ?string $email, ?string $domain) {
    expect(Matcher::domainOf($website, $email))->toBe($domain);
})->with([
    ['https://www.acme.example/about', null, 'acme.example'],
    ['acme.example', null, 'acme.example'],
    ['https://www.facebook.com/acme', 'info@acme.example', 'acme.example'],
    [null, 'jane@gmail.com', null],
    [null, 'jane@pec.libero.it', null],
    [null, 'jane@acme.example', 'acme.example'],
    [null, 'not-an-email', null],
]);
