<?php

namespace Sellinnate\SalesCrm\Enums;

use Sellinnate\SalesCrm\Enums\Concerns\HasLabel;
use Sellinnate\SalesCrm\Services\DealRequirements;

/**
 * The closed catalogue of what a stage may require to enter it: each entry knows how to check
 * itself ({@see DealRequirements}) and how the mini form asks it.
 */
enum RequiredField: string
{
    use HasLabel;

    case Company = 'company';
    case Person = 'person';
    case Owner = 'owner';
    case Amount = 'amount';
    case ExpectedCloseDate = 'expected_close_date';
    case OfferValidUntil = 'offer_valid_until';
    case PaymentTerms = 'payment_terms';
    case NextActivity = 'next_activity';
    case OfferDocument = 'offer_document';
}
