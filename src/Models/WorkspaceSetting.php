<?php

namespace Sellinnate\SalesCrm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Sellinnate\SalesCrm\Models\Concerns\BelongsToWorkspace;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The settings of the CRM of a workspace: the stage new deals from integrations enter, the
 * definitions of the custom fields of its deals and free options for the host.
 *
 * @property int $id
 * @property string $workspace_key
 * @property int|null $entry_stage_id
 * @property list<array{key: string, label: string, type: string, options?: list<string>}>|null $custom_fields
 * @property array<string, mixed>|null $options
 * @property int|string|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Stage|null $entryStage
 */
class WorkspaceSetting extends CrmModel
{
    use BelongsToWorkspace;

    protected string $baseTable = 'workspace_settings';

    public bool $hasWorkspaceKey = true;

    protected $fillable = ['workspace_type', 'workspace_id', 'entry_stage_id', 'custom_fields', 'options', 'updated_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_stage_id' => 'integer',
            'custom_fields' => 'array',
            'options' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * The settings of the workspace, or new (unsaved) ones.
     */
    public static function for(Model|Workspace|null $workspace): self
    {
        $workspace = Workspace::of($workspace);

        return self::query()->where('workspace_key', $workspace?->key() ?? '')->first()
            ?? (new self)->setWorkspace($workspace);
    }

    /**
     * @return BelongsTo<Stage, $this>
     */
    public function entryStage(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'entry_stage_id');
    }
}
