<?php

namespace Sellinnate\SalesCrm\Enums;

use Sellinnate\SalesCrm\Enums\Concerns\HasLabel;

enum EmailDirection: string
{
    use HasLabel;

    case Outbound = 'out';
    case Inbound = 'in';

    public function icon(): string
    {
        return match ($this) {
            self::Outbound => 'send',
            self::Inbound => 'inbox',
        };
    }
}
