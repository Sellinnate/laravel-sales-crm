<?php

namespace Sellinnate\SalesCrm\Enums;

use Sellinnate\SalesCrm\Enums\Concerns\HasLabel;

enum EmailStatus: string
{
    use HasLabel;

    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Received = 'received';

    public function tone(): string
    {
        return match ($this) {
            self::Queued => 'info',
            self::Sent, self::Received => 'success',
            self::Failed => 'danger',
        };
    }
}
