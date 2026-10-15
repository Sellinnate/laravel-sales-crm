<?php

namespace Sellinnate\SalesCrm\Enums;

use Sellinnate\SalesCrm\Enums\Concerns\HasLabel;

enum PaymentStatus: string
{
    use HasLabel;

    case NotVerified = 'not_verified';
    case Expected = 'expected';
    case Partial = 'partial';
    case Paid = 'paid';

    public function tone(): string
    {
        return match ($this) {
            self::NotVerified => 'neutral',
            self::Expected => 'info',
            self::Partial => 'warning',
            self::Paid => 'success',
        };
    }

    /**
     * Partial and paid need the amount collected and an evidence.
     */
    public function needsEvidence(): bool
    {
        return in_array($this, [self::Partial, self::Paid], true);
    }
}
