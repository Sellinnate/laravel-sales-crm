<?php

namespace Sellinnate\SalesCrm\Enums;

use Sellinnate\SalesCrm\Enums\Concerns\HasLabel;

enum ImportStatus: string
{
    use HasLabel;

    case Uploaded = 'uploaded';
    case Mapped = 'mapped';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';

    public function tone(): string
    {
        return match ($this) {
            self::Uploaded, self::Mapped => 'neutral',
            self::Running => 'info',
            self::Done => 'success',
            self::Failed => 'danger',
        };
    }
}
