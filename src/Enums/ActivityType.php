<?php

namespace Sellinnate\SalesCrm\Enums;

use Sellinnate\SalesCrm\Enums\Concerns\HasLabel;

enum ActivityType: string
{
    use HasLabel;

    case Call = 'call';
    case Email = 'email';
    case Meeting = 'meeting';
    case Deadline = 'deadline';
    case Task = 'task';

    public function icon(): string
    {
        return match ($this) {
            self::Call => 'phone',
            self::Email => 'mail',
            self::Meeting => 'users',
            self::Deadline => 'flag',
            self::Task => 'check',
        };
    }

    /**
     * Whether doing it means talking to the customer: it moves the last contact of the deal.
     */
    public function isContact(): bool
    {
        return in_array($this, [self::Call, self::Email, self::Meeting], true);
    }
}
