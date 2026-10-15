<?php

namespace Sellinnate\SalesCrm\Services;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Enums\ApprovalStatus;
use Sellinnate\SalesCrm\Enums\CommissionType;
use Sellinnate\SalesCrm\Enums\DealSource;
use Sellinnate\SalesCrm\Enums\DocumentKind;
use Sellinnate\SalesCrm\Enums\LostReason;
use Sellinnate\SalesCrm\Enums\PaymentStatus;
use Sellinnate\SalesCrm\Enums\Priority;
use Sellinnate\SalesCrm\Enums\RecurringPeriod;
use Sellinnate\SalesCrm\Enums\SignatureStatus;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Document;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The editable fields of a deal: one validation and one way of writing them for the quick add, the
 * side panel, the inline edit of the list, the bulk actions, the mini form of the board, the import,
 * the JSON API and the MCP tools. Amounts come in major units (euro, dollars) and are stored in cents;
 * the commission percent is stored in basis points. Only the keys given are validated and written.
 */
class DealFields
{
    public function __construct(private Matcher $matcher, private Access $access) {}

    /**
     * @return array<string, mixed>
     */
    public function rules(?Workspace $workspace, ?Deal $deal = null): array
    {
        $amount = ['nullable', 'numeric', 'min:0', 'max:1000000000'];
        $text = ['nullable', 'string', 'max:2000'];
        $companies = Rule::exists(Company::class, 'id');
        $workspace === null
            ? $companies->whereNull('workspace_type')
            : $companies->where('workspace_type', $workspace->type)->where('workspace_id', $workspace->id);

        return [
            'title' => ['required', 'string', 'max:200'],
            'company_id' => ['nullable', 'integer', $companies],
            'company_name' => ['nullable', 'string', 'max:200'],
            // With company_name: matched by VAT number and domain first, and given to a new company.
            'company_vat' => ['nullable', 'string', 'max:32'],
            'company_website' => ['nullable', 'string', 'max:255'],
            'owner_id' => ['nullable', $this->memberOf($workspace)],
            'priority' => ['nullable', Rule::enum(Priority::class)],
            'source' => ['required', Rule::enum(DealSource::class)],
            'tags' => ['nullable', 'array', 'max:'.max(1, (int) config('sales-crm.deals.max_tags', 10))],
            'tags.*' => ['string', 'max:30'],
            'external_ref' => ['nullable', 'string', 'max:64', Rule::unique(Deal::class, 'external_ref')->where('workspace_key', $workspace?->key() ?? '')->ignore($deal?->getKey())],
            'amount_once' => $amount,
            'deposit' => $amount,
            'recurring' => $amount,
            'recurring_period' => ['nullable', Rule::enum(RecurringPeriod::class)],
            'recurring_options' => $text,
            'payment_terms' => $text,
            'offer_details' => $text,
            'offer_valid_until' => ['nullable', 'date'],
            'expected_close_date' => ['nullable', 'date'],
            'sub_status' => ['nullable', 'string', 'max:160'],
            'next_step' => $text,
            'blocker' => $text,
            'missing_data' => $text,
            'call_context' => $text,
            'closing_tip' => $text,
            'approval_required' => ['boolean'],
            'approval_status' => ['nullable', Rule::enum(ApprovalStatus::class)],
            'approver_id' => ['nullable', $this->memberOf($workspace)],
            'approval_note' => ['nullable', 'string', 'max:1000'],
            'signature_status' => ['required', Rule::enum(SignatureStatus::class)],
            'signature_evidence' => ['nullable', 'string', 'max:500'],
            'payment_status' => ['required', Rule::enum(PaymentStatus::class)],
            'amount_collected' => $amount,
            'payment_evidence' => ['nullable', 'string', 'max:500'],
            'commission_type' => ['nullable', Rule::enum(CommissionType::class)],
            'commission_value' => $amount,
            'commission_note' => ['nullable', 'string', 'max:255'],
            'lost_reason' => ['nullable', Rule::enum(LostReason::class)],
            'lost_note' => ['nullable', 'string', 'max:1000'],
            'last_contacted_at' => ['nullable', 'date'],
            'data_verified_at' => ['nullable', 'date'],
        ];
    }

    /**
     * The names of the fields in the validation messages.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        $attributes = [];

        foreach (['title', 'company_id', 'company_name', 'company_vat', 'company_website', 'owner_id', 'priority', 'source', 'tags', 'external_ref', 'amount_once', 'deposit', 'recurring',
            'recurring_period', 'recurring_options', 'payment_terms', 'offer_details', 'offer_valid_until', 'expected_close_date', 'sub_status',
            'next_step', 'blocker', 'missing_data', 'call_context', 'closing_tip', 'approval_required', 'approval_status', 'approver_id',
            'approval_note', 'signature_status', 'signature_evidence', 'payment_status', 'amount_collected', 'payment_evidence',
            'commission_type', 'commission_value', 'commission_note', 'lost_reason', 'lost_note', 'last_contacted_at', 'data_verified_at'] as $field) {
            $attributes[$field] = mb_strtolower(DealHistory::label($field));
        }

        $attributes['tags.*'] = mb_strtolower(DealHistory::label('tags'));

        return $attributes;
    }

    /**
     * Validate the keys given (and only those).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(?Workspace $workspace, array $data, ?Deal $deal = null): array
    {
        // Values are validated as they are stored: trimmed, blank as null.
        $data = array_map(fn (mixed $value): mixed => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $data);
        $all = $this->rules($workspace, $deal);
        $rules = array_intersect_key($all, $data);

        if (array_key_exists('tags', $data)) {
            $rules['tags.*'] = ['string', 'max:30'];
        }

        $custom = array_key_exists('custom_values', $data) ? CustomFields::of($workspace) : [];

        if (array_key_exists('custom_values', $data)) {
            $data['custom_values'] = is_array($data['custom_values'])
                ? array_map(fn (mixed $value): mixed => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $data['custom_values'])
                : $data['custom_values'];
            $rules = [...$rules, ...CustomFields::rules($custom)];
        }

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($data, $rules, [
            'external_ref.unique' => __('sales-crm::validation.external_ref_taken'),
        ], [...self::attributes(), ...CustomFields::attributes($custom)])->validate();

        if (array_key_exists('custom_values', $validated)) {
            $validated['custom_values'] = CustomFields::normalize($custom, is_array($validated['custom_values']) ? $validated['custom_values'] : []);
        }

        return array_intersect_key($validated, $data);
    }

    /**
     * Write the validated values on the deal, without saving it.
     *
     * @param  array<string, mixed>  $validated
     */
    public function fill(Deal $deal, array $validated, ?Authenticatable $by): void
    {
        $now = CarbonImmutable::now();
        $signature = $deal->signature_status;
        $payment = $deal->payment_status;
        $approval = $deal->approval_status;

        foreach ($validated as $key => $value) {
            match ($key) {
                'company_name', 'company_vat', 'company_website' => null,
                'amount_once' => $deal->amount_once_cents = self::cents($value),
                'deposit' => $deal->deposit_cents = self::cents($value),
                'recurring' => $deal->recurring_cents = self::cents($value),
                'amount_collected' => $deal->amount_collected_cents = self::cents($value),
                'commission_value' => $deal->commission_value = self::cents($value),
                'tags' => $deal->tags = self::tags($value),
                // The values given join those already there; an emptied value goes.
                'custom_values' => $deal->custom_values = array_filter([...$deal->custom_values ?? [], ...(is_array($value) ? $value : [])], fn (mixed $one): bool => $one !== null) ?: null,
                default => $deal->setAttribute($key, is_string($value) ? trim($value) : $value),
            };
        }

        if (array_key_exists('company_name', $validated) && ! array_key_exists('company_id', $validated)) {
            $name = trim(is_scalar($validated['company_name']) ? (string) $validated['company_name'] : '');
            $deal->company_id = $name === '' ? null : $this->matcher->company($deal->workspaceRef(), [
                'name' => $name,
                'vat_number' => $validated['company_vat'] ?? null,
                'website' => $validated['company_website'] ?? null,
            ], $by)->id;
        }

        // A contact recorded by hand is activity too: the deal is no longer stale. Not a future date.
        if ($deal->isDirty('last_contacted_at') && $deal->last_contacted_at !== null && $deal->last_contacted_at->lte($now)
            && ($deal->last_activity_at === null || $deal->last_contacted_at->gt($deal->last_activity_at))) {
            $deal->last_activity_at = $deal->last_contacted_at;
        }

        if ($deal->recurring_cents === null) {
            $deal->recurring_period = null;
        } elseif ($deal->recurring_period === null) {
            $deal->recurring_period = RecurringPeriod::Yearly;
        }

        // The commission value is in the unit of its type (cents, basis points): another type without
        // its own value starts empty, never reading the old number in the new unit.
        if ($deal->commission_type === null || ($deal->isDirty('commission_type') && ! array_key_exists('commission_value', $validated))) {
            $deal->commission_value = null;
        }

        if ($deal->signature_status !== $signature) {
            $signed = $deal->signature_status === SignatureStatus::Signed;
            $deal->signature_verified_at = $signed ? $now : null;
            $deal->signature_verified_by = $signed ? Crm::userKey($by) : null;
        }

        if ($deal->payment_status !== $payment) {
            $verified = $deal->payment_status->needsEvidence();
            $deal->payment_verified_at = $verified ? $now : null;
            $deal->payment_verified_by = $verified ? Crm::userKey($by) : null;
        }

        if (! $deal->approval_required) {
            $deal->approval_status = null;
            $deal->approval_decided_at = null;
        } elseif ($deal->approval_status === null) {
            $deal->approval_status = ApprovalStatus::Pending;
        }

        // A new approver decides again: the decision of the previous one does not hold.
        if ($deal->approval_required && $deal->isDirty('approver_id') && $deal->getOriginal('approver_id') !== null
            && $deal->approval_status?->isDecided() === true && $deal->approval_status === $approval) {
            $deal->approval_status = ApprovalStatus::Pending;
        }

        if ($deal->approval_status !== $approval) {
            $deal->approval_decided_at = $deal->approval_status?->isDecided() === true ? $now : null;
        }
    }

    /**
     * The rules that span several fields: evidence of signature and payment, who records the approval,
     * the reason of a lost deal and the percent of the commission.
     *
     * @throws ValidationException
     */
    public function check(Deal $deal, ?Authenticatable $by): void
    {
        $errors = $this->evidenceErrors($deal);
        $original = $deal->getOriginal('approval_status');
        $original = $original instanceof ApprovalStatus ? $original : ApprovalStatus::tryFrom((string) $original);

        // The decision — given, changed or taken back — is of the approver already chosen...
        if ($deal->isDirty('approval_status') && ($deal->approval_status?->isDecided() === true || $original?->isDecided() === true)) {
            if (! $this->approves($deal, $by)) {
                $errors['approval_status'] = __('sales-crm::validation.approval_decision');
            } elseif (self::owns($deal, $by)) {
                // ...never of who owns the deal, even when they manage the CRM: no deal approves itself.
                $errors['approval_status'] = __('sales-crm::validation.own_approval');
            }
        }

        // ...while the approval is asked, the approver changes and the request is withdrawn only by the
        // approver or by a manager...
        if ((bool) $deal->getOriginal('approval_required') && $deal->getOriginal('approver_id') !== null
            && ($deal->isDirty('approver_id') || ! $deal->approval_required)
            && ! $this->approves($deal, $by)) {
            $errors['approver_id'] = __('sales-crm::validation.approver_change');
        }

        // ...and nobody names themselves approver, to decide later, but a manager.
        if ($deal->approval_required && $by !== null && Crm::sameUser($deal->approver_id, Crm::userKey($by))
            && ($deal->isDirty('approver_id') || $deal->isDirty('approval_required'))
            && ! $this->manages($deal, $by)) {
            $errors['approver_id'] ??= __('sales-crm::validation.self_approver');
        }

        if ($deal->lost_at !== null && $deal->lost_reason === null) {
            $errors['lost_reason'] = __('sales-crm::validation.lost_reason_kept');
        }

        if ($deal->commission_type === CommissionType::Percent && (int) $deal->commission_value > 10000) {
            $errors['commission_value'] = __('sales-crm::validation.commission_percent');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Signature and payment marked as verified keep their evidence: a link or a reference on the deal,
     * or a document among its files (but the one about to go).
     *
     * @return array<string, string>
     */
    public function evidenceErrors(Deal $deal, ?int $without = null): array
    {
        $errors = [];

        if ($deal->signature_status === SignatureStatus::Signed && blank($deal->signature_evidence)
            && ! $this->hasDocument($deal, [DocumentKind::Contract, DocumentKind::SignatureEvidence], $without)) {
            $errors['signature_evidence'] = (string) __('sales-crm::validation.evidence_signature');
        }

        if ($deal->payment_status->needsEvidence() && ($deal->amount_collected_cents === null
            || (blank($deal->payment_evidence) && ! $this->hasDocument($deal, [DocumentKind::PaymentEvidence], $without)))) {
            $errors['payment_evidence'] = (string) __('sales-crm::validation.evidence_payment');
        }

        return $errors;
    }

    /**
     * Whether the user owns the deal, now or before this change.
     */
    public static function owns(Deal $deal, ?Authenticatable $by): bool
    {
        $key = Crm::userKey($by);

        return $key !== null && (Crm::sameUser($deal->owner_id, $key) || Crm::sameUser($deal->getOriginal('owner_id'), $key));
    }

    /**
     * Whether the user is the approver already chosen or manages the workspace; the system (no user)
     * never takes an approval decision.
     */
    private function approves(Deal $deal, ?Authenticatable $by): bool
    {
        return $by !== null && (Crm::sameUser($deal->getOriginal('approver_id'), Crm::userKey($by)) || $this->manages($deal, $by));
    }

    private function manages(Deal $deal, Authenticatable $by): bool
    {
        return $this->access->canManage($by, $deal->workspaceRef());
    }

    /**
     * @param  list<DocumentKind>  $kinds
     */
    private function hasDocument(Deal $deal, array $kinds, ?int $without = null): bool
    {
        return $deal->exists && Document::query()
            ->where('deal_id', $deal->getKey())
            ->whereIn('kind', array_map(fn (DocumentKind $kind): string => $kind->value, $kinds))
            ->when($without !== null, fn ($query) => $query->whereKeyNot($without))
            ->exists();
    }

    /**
     * Major units (number or numeric string) to cents.
     */
    public static function cents(mixed $value): ?int
    {
        return $value === null || $value === '' || ! is_numeric($value) ? null : (int) round((float) $value * 100);
    }

    /**
     * @return list<string>|null
     */
    private static function tags(mixed $value): ?array
    {
        $tags = array_values(array_unique(array_filter(
            array_map(fn (mixed $tag): string => mb_substr(trim(is_scalar($tag) ? (string) $tag : ''), 0, 30), (array) $value),
            fn (string $tag): bool => $tag !== '',
        )));

        return $tags === [] ? null : $tags;
    }

    /**
     * The value must be a user who works in the workspace.
     */
    private function memberOf(?Workspace $workspace): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($workspace): void {
            if ($value !== null && $value !== '' && (! is_scalar($value) || ! $this->access->isMember((string) $value, $workspace))) {
                $fail((string) __('sales-crm::validation.member'));
            }
        };
    }
}
