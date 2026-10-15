<?php

namespace Sellinnate\SalesCrm\Enums;

use Sellinnate\SalesCrm\Enums\Concerns\HasLabel;

enum CommissionType: string
{
    use HasLabel;

    case Amount = 'amount';
    case Percent = 'percent';
}
