<?php

namespace Sellinnate\SalesCrm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Sellinnate\SalesCrm\Database\Factories\NoteFactory;
use Sellinnate\SalesCrm\Models\Concerns\BelongsToWorkspace;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * A note on a deal, a company or a person, with its author, the users it mentions and its pin.
 *
 * @property int $id
 * @property int|null $deal_id
 * @property int|null $company_id
 * @property int|null $person_id
 * @property int|string|null $user_id
 * @property string $body
 * @property list<int|string>|null $mentions
 * @property CarbonImmutable|null $pinned_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Deal|null $deal
 * @property-read Company|null $company
 * @property-read Person|null $person
 * @property-read Model|null $author
 */
class Note extends CrmModel
{
    use BelongsToWorkspace;

    /** @use HasFactory<NoteFactory> */
    use HasFactory;

    protected string $baseTable = 'notes';

    protected $fillable = ['workspace_type', 'workspace_id', 'deal_id', 'company_id', 'person_id', 'user_id', 'body', 'mentions', 'pinned_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deal_id' => 'integer',
            'company_id' => 'integer',
            'person_id' => 'integer',
            'mentions' => 'array',
            'pinned_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): NoteFactory
    {
        return NoteFactory::new();
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(Crm::userModel(), 'user_id');
    }
}
