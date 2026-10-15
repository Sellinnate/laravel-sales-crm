<?php

namespace Sellinnate\SalesCrm\Enums;

use Sellinnate\SalesCrm\Enums\Concerns\HasLabel;

enum DocumentKind: string
{
    use HasLabel;

    case Quote = 'quote';
    case Contract = 'contract';
    case SignatureEvidence = 'signature_evidence';
    case PaymentEvidence = 'payment_evidence';
    case Other = 'other';

    public function icon(): string
    {
        return match ($this) {
            self::Quote => 'receipt',
            self::Contract, self::SignatureEvidence => 'file-check',
            self::PaymentEvidence => 'coins',
            self::Other => 'file-text',
        };
    }

    /**
     * Whether the document proves the signature of the deal.
     */
    public function provesSignature(): bool
    {
        return in_array($this, [self::Contract, self::SignatureEvidence], true);
    }
}
