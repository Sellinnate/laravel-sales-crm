<?php

namespace Sellinnate\SalesCrm\Enums;

use Sellinnate\SalesCrm\Enums\Concerns\HasLabel;

enum ApprovalStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }

    public function isDecided(): bool
    {
        return $this !== self::Pending;
    }
}
