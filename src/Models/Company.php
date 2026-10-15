<?php

namespace Sellinnate\SalesCrm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Sellinnate\SalesCrm\Database\Factories\CompanyFactory;
use Sellinnate\SalesCrm\Models\Concerns\BelongsToWorkspace;
use Sellinnate\SalesCrm\Services\Matcher;

/**
 * A company of a workspace: it exists once per workspace (deduplicated by VAT number, domain and
 * normalised name, {@see Matcher}).
 *
 * @property int $id
 * @property string $name
 * @property string $name_key
 * @property string|null $legal_name
 * @property string|null $vat_number
 * @property string|null $vat_key
 * @property string|null $website
 * @property string|null $domain
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $city
 * @property string|null $region
 * @property string|null $postal_code
 * @property string|null $country
 * @property string|null $industry
 * @property string|null $notes
 * @property int|string|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Person> $people
 * @property-read Collection<int, Deal> $deals
 * @property-read Collection<int, Activity> $activities
 * @property-read Collection<int, Note> $crmNotes
 * @property-read Collection<int, Document> $documents
 */
class Company extends CrmModel
{
    use BelongsToWorkspace;

    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected string $baseTable = 'companies';

    protected $fillable = [
        'workspace_type', 'workspace_id', 'name', 'legal_name', 'vat_number', 'website', 'email', 'phone', 'address', 'city',
        'region', 'postal_code', 'country', 'industry', 'notes', 'created_by',
    ];

    /**
     * The keys of the deduplication follow the values they come from.
     */
    protected static function booted(): void
    {
        static::saving(function (Company $company): void {
            $company->name_key = Matcher::nameKey($company->name);
            $company->vat_key = Matcher::vatKey($company->vat_number);
            $company->domain = Matcher::domainOf($company->website, $company->email);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
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
     * @return HasMany<Person, $this>
     */
    public function people(): HasMany
    {
        return $this->hasMany(Person::class, 'company_id');
    }

    /**
     * @return HasMany<Deal, $this>
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'company_id');
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'company_id');
    }

    /**
     * The notes written on the company (the `notes` attribute is its free-text description).
     *
     * @return HasMany<Note, $this>
     */
    public function crmNotes(): HasMany
    {
        return $this->hasMany(Note::class, 'company_id');
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'company_id');
    }
}
