<?php

namespace Sellinnate\SalesCrm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Sellinnate\SalesCrm\Enums\EmailDirection;
use Sellinnate\SalesCrm\Enums\EmailStatus;
use Sellinnate\SalesCrm\Models\Concerns\BelongsToWorkspace;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * An email of a deal or a person: sent from the CRM (queued, sent or failed) or received and matched
 * by an inbound driver, threaded by Message-ID.
 *
 * @property int $id
 * @property string $workspace_key
 * @property int|null $deal_id
 * @property int|null $person_id
 * @property EmailDirection $direction
 * @property EmailStatus $status
 * @property string $from_email
 * @property string|null $from_name
 * @property string|null $reply_to
 * @property list<string> $to
 * @property list<string>|null $cc
 * @property string $subject
 * @property string|null $body_text
 * @property string $message_id
 * @property string|null $in_reply_to
 * @property string|null $references
 * @property int|string|null $sent_by
 * @property CarbonImmutable|null $sent_at
 * @property string|null $error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Deal|null $deal
 * @property-read Person|null $person
 * @property-read Model|null $sender
 */
class Email extends CrmModel
{
    use BelongsToWorkspace;

    protected string $baseTable = 'emails';

    public bool $hasWorkspaceKey = true;

    protected $fillable = [
        'workspace_type', 'workspace_id', 'deal_id', 'person_id', 'direction', 'status', 'from_email', 'from_name', 'reply_to',
        'to', 'cc', 'subject', 'body_text', 'message_id', 'in_reply_to', 'references', 'sent_by', 'sent_at', 'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deal_id' => 'integer',
            'person_id' => 'integer',
            'direction' => EmailDirection::class,
            'status' => EmailStatus::class,
            'to' => 'array',
            'cc' => 'array',
            'sent_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * The Message-IDs of the thread up to this email: its references and its own id.
     *
     * @return list<string>
     */
    public function thread(): array
    {
        $references = preg_split('/\s+/', (string) $this->references) ?: [];

        return array_values(array_filter([...$references, $this->message_id], fn (string $id): bool => $id !== ''));
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(Crm::userModel(), 'sent_by');
    }

    /**
     * Seen with their deal (see BelongsToWorkspace::scopeVisibleTo()).
     */
    protected function followsDealVisibility(): bool
    {
        return true;
    }
}
