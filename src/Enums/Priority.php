<?php

namespace Sellinnate\SalesCrm\Enums;

use Sellinnate\SalesCrm\Enums\Concerns\HasLabel;

enum Priority: string
{
    use HasLabel;

    case P1 = 'p1';
    case P2 = 'p2';
    case P3 = 'p3';
    case P4 = 'p4';
    case Excluded = 'excluded';

    public function tone(): string
    {
        return match ($this) {
            self::P1 => 'danger',
            self::P2 => 'warning',
            self::P3 => 'info',
            self::P4, self::Excluded => 'neutral',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::P1 => 1,
            self::P2 => 2,
            self::P3 => 3,
            self::P4 => 4,
            self::Excluded => 5,
        };
    }
}
