<?php

namespace Sellinnate\SalesCrm\Enums;

use Sellinnate\SalesCrm\Enums\Concerns\HasLabel;

enum RecurringPeriod: string
{
    use HasLabel;

    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function suffix(): string
    {
        return (string) __('sales-crm::enums.recurring_period_suffix.'.$this->value);
    }

    public function timesPerYear(): int
    {
        return match ($this) {
            self::Monthly => 12,
            self::Yearly => 1,
        };
    }
}
