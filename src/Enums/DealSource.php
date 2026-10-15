<?php

namespace Sellinnate\SalesCrm\Enums;

use Sellinnate\SalesCrm\Enums\Concerns\HasLabel;

enum DealSource: string
{
    use HasLabel;

    case Inbound = 'inbound';
    case Outbound = 'outbound';
    case Referral = 'referral';
    case Email = 'email';
    case Event = 'event';
    case Partner = 'partner';
    case Website = 'website';
    case Integration = 'integration';
    case Import = 'import';
    case Other = 'other';
}
