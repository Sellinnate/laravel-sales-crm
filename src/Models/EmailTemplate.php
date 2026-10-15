<?php

namespace Sellinnate\SalesCrm\Models;

use Carbon\CarbonImmutable;
use Sellinnate\SalesCrm\Models\Concerns\BelongsToWorkspace;

/**
 * An email template of a workspace, with `{{variables}}` filled from the deal.
 *
 * @property int $id
 * @property string $workspace_key
 * @property string $name
 * @property string $subject
 * @property string $body
 * @property int|string|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class EmailTemplate extends CrmModel
{
    use BelongsToWorkspace;

    protected string $baseTable = 'email_templates';

    public bool $hasWorkspaceKey = true;

    protected $fillable = ['workspace_type', 'workspace_id', 'name', 'subject', 'body', 'created_by'];

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
}
