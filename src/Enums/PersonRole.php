<?php

namespace Sellinnate\SalesCrm\Enums;

use Sellinnate\SalesCrm\Enums\Concerns\HasLabel;

enum PersonRole: string
{
    use HasLabel;

    case DecisionMaker = 'decision_maker';
    case Influencer = 'influencer';
    case Operational = 'operational';
    case Other = 'other';
}
