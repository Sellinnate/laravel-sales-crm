<?php

namespace Sellinnate\SalesCrm\Enums\Concerns;

use Illuminate\Support\Str;

/**
 * Translated labels and select options for the backed enums of the CRM: the label of a case is
 * `sales-crm::enums.<enum>.<value>`.
 */
trait HasLabel
{
    public function label(): string
    {
        $key = 'sales-crm::enums.'.Str::snake(class_basename(static::class)).'.'.$this->value;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : Str::headline($this->value);
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[(string) $case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => (string) $case->value, self::cases());
    }
}
