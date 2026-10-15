<?php

namespace Sellinnate\SalesCrm\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Enums\DocumentKind;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Document;
use Sellinnate\SalesCrm\Models\Person;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Support\Format;

/**
 * The variables of the email templates: `{{first_name}}` and the others of {@see self::VARIABLES},
 * written with the deal, the person the email goes to and the sender. A variable without a value
 * becomes empty; an unknown one stays as written. `{{quote_link}}` is a signed link to the latest quote
 * of the deal, valid for `sales-crm.documents.signed_link_days`.
 */
class EmailTemplates
{
    /**
     * The variables (descriptions: `sales-crm::ui.variables.<variable>`).
     */
    public const VARIABLES = ['first_name', 'last_name', 'company', 'deal', 'amount', 'recurring', 'offer_valid_until', 'quote_link', 'sender', 'workspace'];

    private const PATTERN = '/\{\{\s*([a-z_]+)\s*\}\}/';

    public function __construct(private Access $access) {}

    /**
     * The text with the variables of the deal written in.
     */
    public function render(string $text, Deal $deal, ?Person $person, ?Authenticatable $sender): string
    {
        if (! str_contains($text, '{{')) {
            return $text;
        }

        $values = $this->values($deal, $person, $sender, needsLink: preg_match('/\{\{\s*quote_link\s*\}\}/', $text) === 1);

        return (string) preg_replace_callback(self::PATTERN, fn (array $match): string => $values[$match[1]] ?? $match[0], $text);
    }

    /**
     * @return array<string, string>
     */
    private function values(Deal $deal, ?Person $person, ?Authenticatable $sender, bool $needsLink): array
    {
        $deal->loadMissing('company:id,name');

        return [
            'first_name' => (string) $person?->first_name,
            'last_name' => (string) $person?->last_name,
            'company' => (string) $deal->company?->name,
            'deal' => $deal->title,
            'amount' => $deal->amount_once_cents ? Format::money($deal->amount_once_cents) : '',
            'recurring' => $deal->recurring_cents ? Format::money($deal->recurring_cents).($deal->recurring_period?->suffix() ?? '') : '',
            'offer_valid_until' => Format::date($deal->offer_valid_until),
            'quote_link' => $needsLink ? $this->quoteLink($deal) : '',
            'sender' => Crm::userName($sender),
            'workspace' => $this->access->label($deal->workspaceRef()),
        ];
    }

    private function quoteLink(Deal $deal): string
    {
        $quote = Document::query()->where('deal_id', $deal->id)->where('kind', DocumentKind::Quote->value)->latest('id')->first();

        if ($quote === null || ! Route::has('sales-crm.documents.signed')) {
            return '';
        }

        return URL::temporarySignedRoute(
            'sales-crm.documents.signed',
            CarbonImmutable::now()->addDays(max(1, (int) config('sales-crm.documents.signed_link_days', 30))),
            ['document' => $quote->id],
        );
    }
}
