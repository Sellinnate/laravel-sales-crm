<?php

namespace Sellinnate\SalesCrm\Enums;

use Sellinnate\SalesCrm\Enums\Concerns\HasLabel;

enum StageColor: string
{
    use HasLabel;

    case Neutral = 'neutral';
    case Brand = 'brand';
    case Info = 'info';
    case Accent = 'accent';
    case Warning = 'warning';
    case Success = 'success';
    case Danger = 'danger';

    /**
     * The CSS class of the dot of the stage in the UI.
     */
    public function dotClass(): string
    {
        return 'crm-dot-'.$this->value;
    }
}
