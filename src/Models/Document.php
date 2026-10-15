<?php

namespace Sellinnate\SalesCrm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Sellinnate\SalesCrm\Enums\DocumentKind;
use Sellinnate\SalesCrm\Models\Concerns\BelongsToWorkspace;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * A document on a deal or a company (quote, contract, evidence of signature or payment), stored on
 * a private disk and downloaded through the CRM. A quote has a version and a validity date.
 *
 * @property int $id
 * @property int|null $deal_id
 * @property int|null $company_id
 * @property DocumentKind $kind
 * @property string $name
 * @property string $disk
 * @property string $path
 * @property string|null $mime
 * @property int $size
 * @property int $version
 * @property CarbonImmutable|null $document_date
 * @property CarbonImmutable|null $valid_until
 * @property int|string|null $uploaded_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Deal|null $deal
 * @property-read Company|null $company
 * @property-read Model|null $uploader
 */
class Document extends CrmModel
{
    use BelongsToWorkspace;

    protected string $baseTable = 'documents';

    protected $fillable = [
        'workspace_type', 'workspace_id', 'deal_id', 'company_id', 'kind', 'name', 'disk', 'path', 'mime', 'size', 'version',
        'document_date', 'valid_until', 'uploaded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deal_id' => 'integer',
            'company_id' => 'integer',
            'kind' => DocumentKind::class,
            'size' => 'integer',
            'version' => 'integer',
            'document_date' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
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
     * @return BelongsTo<Model, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(Crm::userModel(), 'uploaded_by');
    }

    /**
     * Seen with their deal (see BelongsToWorkspace::scopeVisibleTo()).
     */
    protected function followsDealVisibility(): bool
    {
        return true;
    }
}
