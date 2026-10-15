<?php

namespace Sellinnate\SalesCrm\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Sellinnate\SalesCrm\Models\WorkspaceSetting;
use Sellinnate\SalesCrm\Support\Format;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The custom fields of the deals of a workspace: definitions (key, label, type, options of a choice)
 * kept in the workspace settings; values on each deal under `custom_values.<key>`. A field taken
 * away keeps its values on the deals, unseen.
 *
 * @phpstan-type CustomField array{key: string, label: string, type: string, options?: list<string>}
 */
class CustomFields
{
    public const TYPES = ['text', 'number', 'date', 'boolean', 'choice'];

    /**
     * The fields of the workspace.
     *
     * @return list<CustomField>
     */
    public static function of(?Workspace $workspace): array
    {
        $fields = WorkspaceSetting::query()->where('workspace_key', $workspace?->key() ?? '')->value('custom_fields');
        $fields = is_string($fields) ? json_decode($fields, true) : $fields;

        if (! is_array($fields)) {
            return [];
        }

        $valid = [];

        foreach ($fields as $field) {
            if (is_array($field) && is_string($field['key'] ?? null) && is_string($field['label'] ?? null) && is_string($field['type'] ?? null)) {
                $definition = ['key' => $field['key'], 'label' => $field['label'], 'type' => $field['type']];

                if (is_array($field['options'] ?? null)) {
                    $definition['options'] = array_values(array_map(fn (mixed $option): string => is_scalar($option) ? (string) $option : '', $field['options']));
                }

                $valid[] = $definition;
            }
        }

        return $valid;
    }

    /**
     * The rules of the values, as `custom_values.<key>`.
     *
     * @param  list<CustomField>  $fields
     * @return array<string, mixed>
     */
    public static function rules(array $fields): array
    {
        $rules = ['custom_values' => ['nullable', 'array']];

        foreach ($fields as $field) {
            $rules['custom_values.'.$field['key']] = match ($field['type']) {
                'number' => ['nullable', 'numeric', 'min:-1000000000', 'max:1000000000'],
                'date' => ['nullable', 'date_format:Y-m-d'],
                'boolean' => ['nullable', 'boolean'],
                'choice' => ['nullable', 'string', Rule::in($field['options'] ?? [])],
                default => ['nullable', 'string', 'max:500'],
            };
        }

        return $rules;
    }

    /**
     * @param  list<CustomField>  $fields
     * @return array<string, string>
     */
    public static function attributes(array $fields): array
    {
        $attributes = [];

        foreach ($fields as $field) {
            $attributes['custom_values.'.$field['key']] = mb_strtolower($field['label']);
        }

        return $attributes;
    }

    /**
     * The values given for the fields (the others dropped), as they are stored.
     *
     * @param  list<CustomField>  $fields
     * @param  array<string, mixed>  $values
     * @return array<string, string|int|float|bool|null>
     */
    public static function normalize(array $fields, array $values): array
    {
        $normalized = [];

        foreach ($fields as $field) {
            if (! array_key_exists($field['key'], $values)) {
                continue;
            }

            $value = $values[$field['key']];
            $value = is_string($value) ? trim($value) : $value;

            $normalized[$field['key']] = $value === null || $value === '' ? null : match ($field['type']) {
                'number' => is_numeric($value) ? $value + 0 : null,
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                default => is_scalar($value) ? (string) $value : null,
            };
        }

        return $normalized;
    }

    /**
     * A value as the list and the export show it.
     *
     * @param  CustomField  $field
     */
    public static function display(array $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($field['type']) {
            'boolean' => $value ? (string) __('sales-crm::ui.yes') : (string) __('sales-crm::ui.no'),
            'number' => is_numeric($value) ? Format::number((float) $value, fmod((float) $value, 1.0) === 0.0 ? 0 : 2) : '',
            'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? Format::date(CarbonImmutable::parse($value)) : (is_scalar($value) ? (string) $value : ''),
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    /**
     * The maximum number of custom fields of a workspace.
     */
    public static function limit(): int
    {
        return max(1, (int) config('sales-crm.deals.custom_fields_limit', 20));
    }
}
