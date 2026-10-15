<?php

namespace Sellinnate\SalesCrm\Services\Import;

use Illuminate\Support\Str;
use Sellinnate\SalesCrm\Services\CustomFields;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The fields a column of an imported sheet can fill — of the deal, of its company and of its person,
 * and the custom fields of the workspace (`custom:<key>`) — with the headers (English and Italian)
 * that suggest them.
 */
class ImportFields
{
    /**
     * The fields by group (labels: `sales-crm::import.fields.<field>`, groups: `sales-crm::import.groups.<group>`).
     */
    public const FIELDS = [
        'deal' => [
            'external_ref', 'title', 'stage', 'sub_status', 'priority', 'owner', 'source', 'tags', 'next_step', 'blocker',
            'approval_note', 'missing_data', 'call_context', 'closing_tip', 'expected_close_date', 'last_contacted_at', 'data_verified_at', 'note',
        ],
        'amounts' => ['amount_once', 'deposit', 'recurring_options', 'payment_terms', 'offer_details', 'offer_valid_until', 'commission', 'signature', 'payment'],
        'company' => ['company', 'company_vat', 'company_website'],
        'person' => ['person_name', 'person_email', 'person_mobile', 'person_phone', 'person_phone_source', 'person_job_title'],
    ];

    /**
     * The fields that take the text of every column mapped on them, one per line.
     */
    public const TEXTS = ['next_step', 'blocker', 'approval_note', 'missing_data', 'call_context', 'closing_tip', 'recurring_options', 'payment_terms', 'offer_details', 'note', 'sub_status'];

    /**
     * The normalised headers that suggest a field: exact first, then the longest contained.
     */
    private const SYNONYMS = [
        'external_ref' => ['id', 'ref', 'reference', 'external id', 'external ref', 'codice', 'riferimento', 'rif', 'chiave', 'key'],
        'title' => ['title', 'deal', 'deal name', 'opportunity', 'titolo', 'trattativa', 'opportunita'],
        'stage' => ['stage', 'deal stage', 'pipeline stage', 'status', 'stato trattativa', 'fase', 'pipeline'],
        'sub_status' => ['sub status', 'substatus', 'reason', 'sotto stato', 'stato'],
        'priority' => ['priority', 'priorita'],
        'owner' => ['owner', 'deal owner', 'salesperson', 'sales rep', 'assigned to', 'responsabile', 'commerciale', 'assegnato a'],
        'source' => ['source', 'lead source', 'channel', 'fonte trattativa', 'fonte', 'canale'],
        'tags' => ['tags', 'tag', 'labels', 'etichette'],
        'next_step' => ['next step', 'next action', 'prossimo passo operativo', 'prossima azione', 'prossimo passo'],
        'blocker' => ['blocker', 'objection', 'blocco dubbio cliente', 'blocco', 'dubbio'],
        'approval_note' => ['approval', 'to approve', 'da approvare', 'approvazione'],
        'missing_data' => ['missing data', 'missing info', 'dati mancanti'],
        'call_context' => ['call context', 'context', 'contesto per chiamata', 'contesto'],
        'closing_tip' => ['closing tip', 'consiglio di chiusura', 'consiglio'],
        'expected_close_date' => ['expected close date', 'close date', 'closing date', 'chiusura prevista', 'data chiusura'],
        'last_contacted_at' => ['last contact', 'last contacted', 'ultimo contatto', 'ultima email'],
        'data_verified_at' => ['verified', 'last verified', 'verifica', 'ultima verifica'],
        'note' => ['notes', 'note', 'comments', 'comment', 'note dopo contatto'],
        'amount_once' => ['amount', 'value', 'deal value', 'price', 'one off', 'one time', 'base progetto', 'importo', 'valore', 'prezzo', 'una tantum'],
        'deposit' => ['deposit', 'down payment', 'acconto fonte', 'acconto'],
        'recurring_options' => ['recurring', 'subscription', 'recurring and options', 'ricorrenti e opzioni', 'ricorrente', 'canone'],
        'payment_terms' => ['payment terms', 'payment', 'pagamento fonte', 'pagamento', 'condizioni di pagamento'],
        'offer_details' => ['offer details', 'offer', 'quote details', 'dettagli offerta', 'offerta'],
        'offer_valid_until' => ['offer valid until', 'quote valid until', 'offer expiry', 'validita offerta', 'scadenza offerta'],
        'commission' => ['commission', 'compenso', 'provvigione', 'commissione'],
        'signature' => ['signature', 'signed', 'firma'],
        'payment' => ['collected', 'payment status', 'incasso'],
        'company' => ['company', 'account', 'organization', 'organisation', 'customer', 'client', 'cliente', 'azienda', 'ragione sociale', 'societa'],
        'company_vat' => ['vat', 'vat number', 'tax id', 'partita iva', 'p iva', 'piva'],
        'company_website' => ['website', 'web site', 'domain', 'sito', 'sito web'],
        'person_name' => ['contact', 'contact name', 'person', 'full name', 'name', 'referente', 'contatto', 'persona', 'nome e cognome'],
        'person_email' => ['email', 'e mail', 'mail', 'contact email', 'email recapito'],
        'person_mobile' => ['mobile', 'cell', 'cellphone', 'cellulare'],
        'person_phone' => ['phone', 'telephone', 'tel', 'telefono operativo', 'telefono'],
        'person_phone_source' => ['phone source', 'fonte recapito'],
        'person_job_title' => ['job title', 'role', 'position', 'ruolo', 'qualifica'],
    ];

    public static function label(string $field): string
    {
        $key = 'sales-crm::import.fields.'.$field;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : $field;
    }

    /**
     * Every field of the workspace, by group label, for the select of the mapping.
     *
     * @return array<string, array<string, string>>
     */
    public static function grouped(?Workspace $workspace): array
    {
        $groups = [];

        foreach (self::FIELDS as $group => $fields) {
            $options = [];

            foreach ($fields as $field) {
                $options[$field] = self::label($field);
            }

            $groups[(string) __('sales-crm::import.groups.'.$group)] = $options;
        }

        $custom = [];

        foreach (CustomFields::of($workspace) as $field) {
            $custom['custom:'.$field['key']] = $field['label'];
        }

        if ($custom !== []) {
            $groups[(string) __('sales-crm::import.groups.custom')] = $custom;
        }

        return $groups;
    }

    /**
     * @return array<string, string>
     */
    public static function all(?Workspace $workspace): array
    {
        return array_merge(...array_values(self::grouped($workspace)));
    }

    public static function normalize(string $header): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($header)))));
    }

    /**
     * The field each header suggests (or none), each field once but the texts.
     *
     * @param  list<string>  $headers
     * @return array<int, string>
     */
    public static function suggest(array $headers, ?Workspace $workspace): array
    {
        $synonyms = self::SYNONYMS;

        foreach (CustomFields::of($workspace) as $field) {
            $synonyms['custom:'.$field['key']] = [self::normalize($field['label'])];
        }

        $suggested = [];

        // Exact headers first, so «Deal stage» takes the stage before «Status» takes the sub-status.
        foreach ([true, false] as $exact) {
            foreach ($headers as $index => $header) {
                if (isset($suggested[$index])) {
                    continue;
                }

                $normalized = self::normalize($header);
                $best = null;
                $length = 0;

                foreach ($synonyms as $field => $words) {
                    if (in_array($field, $suggested, true) && ! in_array($field, self::TEXTS, true)) {
                        continue;
                    }

                    foreach ($words as $word) {
                        $hit = $exact ? $normalized === $word : str_contains(' '.$normalized.' ', ' '.$word.' ');

                        if ($hit && mb_strlen($word) > $length) {
                            $best = $field;
                            $length = mb_strlen($word);
                        }
                    }
                }

                if ($best !== null) {
                    $suggested[$index] = $best;
                }
            }
        }

        ksort($suggested);

        return $suggested;
    }
}
