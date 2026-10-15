<?php

namespace Sellinnate\SalesCrm\Services;

use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Support\Format;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The columns of the list of deals and of its export: the fixed ones and the custom fields of the
 * workspace (`custom:<key>`). The list shows those chosen (or the defaults); the export writes all.
 *
 * @phpstan-import-type CustomField from CustomFields
 */
class DealColumns
{
    /**
     * The fixed columns (labels: `sales-crm::ui.columns.<column>`).
     */
    public const FIXED = [
        'company', 'workspace', 'stage', 'owner', 'priority', 'amount_once', 'recurring', 'first_year', 'weighted', 'next_step',
        'next_activity', 'close', 'offer', 'signature', 'payment', 'approval', 'source', 'last_contact', 'tags', 'external_ref',
    ];

    public const DEFAULTS = ['company', 'stage', 'owner', 'priority', 'first_year', 'next_activity', 'close', 'next_step'];

    /**
     * The columns that sort the list, with their sort key ({@see DealBoard::SORTS}).
     */
    public const SORTABLE = ['first_year' => 'value', 'next_activity' => 'next', 'close' => 'close', 'offer' => 'offer'];

    public static function label(string $column): string
    {
        $key = 'sales-crm::ui.columns.'.$column;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : $column;
    }

    /**
     * Every column of the workspace (the fixed ones and its custom fields), key => label.
     *
     * @return array<string, string>
     */
    public static function all(?Workspace $workspace): array
    {
        $columns = [];

        foreach (self::FIXED as $column) {
            if ($column === 'workspace' && ! app(Access::class)->resolver()->enabled()) {
                continue;
            }

            $columns[$column] = self::label($column);
        }

        foreach (CustomFields::of($workspace) as $field) {
            $columns['custom:'.$field['key']] = $field['label'];
        }

        return $columns;
    }

    /**
     * The columns chosen, in the order of the catalogue, or the defaults.
     *
     * @param  list<string>  $chosen
     * @return array<string, string>
     */
    public static function chosen(array $chosen, ?Workspace $workspace): array
    {
        $all = self::all($workspace);
        $picked = array_intersect_key($all, array_flip($chosen));

        return $picked !== [] ? $picked : array_intersect_key($all, array_flip(self::DEFAULTS));
    }

    /**
     * The value of a column for a deal, as text.
     *
     * @param  array<string, CustomField>  $custom  by key
     */
    public static function text(Deal $deal, string $column, array $custom = []): string
    {
        if (str_starts_with($column, 'custom:')) {
            $key = substr($column, 7);

            return isset($custom[$key]) ? CustomFields::display($custom[$key], $deal->custom_values[$key] ?? null) : '';
        }

        return match ($column) {
            'company' => (string) $deal->company?->name,
            'workspace' => app(Access::class)->label($deal->workspaceRef()),
            'stage' => $deal->stage->name,
            'owner' => Crm::userName($deal->owner),
            'priority' => (string) $deal->priority?->label(),
            'amount_once' => Format::money($deal->amount_once_cents),
            'recurring' => $deal->recurring_cents === null ? '' : Format::money($deal->recurring_cents).($deal->recurring_period?->suffix() ?? ''),
            // A deal with no amount yet has no value, not «0».
            'first_year' => $deal->isPriced() ? Format::money($deal->first_year_value_cents) : '',
            'weighted' => $deal->isPriced() && $deal->isOpen() ? Format::money($deal->weightedCents()) : '',
            'next_step' => (string) $deal->next_step,
            'next_activity' => $deal->next_activity_at === null ? '' : ($deal->nextActivityHasTime() ? Format::dateTime($deal->next_activity_at) : Format::date($deal->next_activity_at)),
            'close' => Format::date($deal->expected_close_date),
            'offer' => Format::date($deal->offer_valid_until),
            'signature' => $deal->signature_status->label(),
            'payment' => $deal->payment_status->label(),
            'approval' => (string) $deal->approval_status?->label(),
            'source' => $deal->source->label(),
            'last_contact' => Format::date($deal->last_contacted_at),
            'tags' => implode(', ', $deal->tags ?? []),
            'external_ref' => (string) $deal->external_ref,
            default => '',
        };
    }
}
