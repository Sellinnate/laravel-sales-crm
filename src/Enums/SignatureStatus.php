<?php

namespace Sellinnate\SalesCrm\Enums;

use Sellinnate\SalesCrm\Enums\Concerns\HasLabel;

enum SignatureStatus: string
{
    use HasLabel;

    case NotVerified = 'not_verified';
    case ToSign = 'to_sign';
    case Signed = 'signed';

    public function tone(): string
    {
        return match ($this) {
            self::NotVerified => 'neutral',
            self::ToSign => 'warning',
            self::Signed => 'success',
        };
    }
}
