<?php

namespace Sellinnate\SalesCrm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Sellinnate\SalesCrm\Database\Factories\PersonFactory;
use Sellinnate\SalesCrm\Models\Concerns\BelongsToWorkspace;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * A person (a contact) of a workspace, optionally of a company, deduplicated by email, phone and
 * name within the company.
 *
 * @property int $id
 * @property int|null $company_id
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $job_title
 * @property string|null $email
 * @property string|null $mobile
 * @property string|null $phone
 * @property string|null $phone_source
 * @property string|null $notes
 * @property int|string|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read string $name
 * @property-read Company|null $company
 * @property-read Collection<int, Deal> $deals
 * @property-read Collection<int, Activity> $activities
 */
class Person extends CrmModel
{
    use BelongsToWorkspace;

    /** @use HasFactory<PersonFactory> */
    use HasFactory;

    protected string $baseTable = 'people';

    protected $fillable = [
        'workspace_type', 'workspace_id', 'company_id', 'first_name', 'last_name', 'job_title', 'email', 'mobile', 'phone',
        'phone_source', 'notes', 'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): PersonFactory
    {
        return PersonFactory::new();
    }

    /**
     * Emails are stored trimmed and lowercase.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function email(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => blank($value) ? null : mb_strtolower(trim((string) $value)));
    }

    /**
     * The full name, else the email, else the number.
     *
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(function (): string {
            $name = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

            return $name !== '' ? $name : (string) ($this->email ?? $this->mobile ?? $this->phone ?? '#'.$this->id);
        });
    }

    /**
     * The number to call: the mobile first.
     */
    public function callableNumber(): ?string
    {
        return $this->mobile ?? $this->phone;
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * @return BelongsToMany<Deal, $this>
     */
    public function deals(): BelongsToMany
    {
        return $this->belongsToMany(Deal::class, Crm::table('deal_person'), 'person_id', 'deal_id')
            ->withPivot(['role', 'is_primary'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'person_id');
    }

    /**
     * The notes written on the person (the `notes` attribute is its free-text description).
     *
     * @return HasMany<Note, $this>
     */
    public function crmNotes(): HasMany
    {
        return $this->hasMany(Note::class, 'person_id');
    }

    /**
     * @return HasMany<Email, $this>
     */
    public function emails(): HasMany
    {
        return $this->hasMany(Email::class, 'person_id');
    }
}
