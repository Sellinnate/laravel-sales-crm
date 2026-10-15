<?php

namespace Sellinnate\SalesCrm\Enums;

use Sellinnate\SalesCrm\Enums\Concerns\HasLabel;

enum StageKind: string
{
    use HasLabel;

    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';

    public function icon(): string
    {
        return match ($this) {
            self::Open => 'circle-dot',
            self::Won => 'award',
            self::Lost => 'circle-x',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open => 'neutral',
            self::Won => 'success',
            self::Lost => 'danger',
        };
    }

    public function isClosed(): bool
    {
        return $this !== self::Open;
    }
}
