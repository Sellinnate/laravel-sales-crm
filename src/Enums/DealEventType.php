<?php

namespace Sellinnate\SalesCrm\Enums;

use Sellinnate\SalesCrm\Enums\Concerns\HasLabel;

enum DealEventType: string
{
    use HasLabel;

    case Created = 'created';
    case StageChanged = 'stage_changed';
    case Updated = 'updated';
    case Won = 'won';
    case Lost = 'lost';
    case Reopened = 'reopened';

    public function icon(): string
    {
        return match ($this) {
            self::Created => 'plus',
            self::StageChanged => 'arrow-right',
            self::Updated => 'pencil',
            self::Won => 'award',
            self::Lost => 'circle-x',
            self::Reopened => 'rotate-ccw',
        };
    }
}
