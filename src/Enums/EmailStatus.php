<?php

namespace Sellinnate\SalesCrm\Enums;

use Sellinnate\SalesCrm\Enums\Concerns\HasLabel;

enum EmailStatus: string
{
    use HasLabel;

    case Queued = 'queued';
    // Handed to the mailer: a retry of the job never sends it again.
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Received = 'received';

    public function tone(): string
    {
        return match ($this) {
            self::Queued, self::Sending => 'info',
            self::Sent, self::Received => 'success',
            self::Failed => 'danger',
        };
    }
}
