<?php

namespace Sellinnate\SalesCrm\Enums;

use Sellinnate\SalesCrm\Enums\Concerns\HasLabel;

enum LostReason: string
{
    use HasLabel;

    case Price = 'price';
    case Timing = 'timing';
    case Competitor = 'competitor';
    case NoResponse = 'no_response';
    case NoBudget = 'no_budget';
    case NotInTarget = 'not_in_target';
    case Postponed = 'postponed';
    case Other = 'other';
}
