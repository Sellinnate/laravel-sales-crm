<?php

namespace Sellinnate\SalesCrm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\Concerns\BelongsToWorkspace;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * A saved view of the deals: filters, mode, columns, sorting and card fields under a name, personal
 * or shared with the workspace.
 *
 * @property int $id
 * @property int|string $user_id
 * @property string $workspace_key
 * @property string $name
 * @property bool $shared
 * @property array<string, mixed> $state
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Model|null $user
 */
class SavedView extends CrmModel
{
    use BelongsToWorkspace;

    protected string $baseTable = 'saved_views';

    public bool $hasWorkspaceKey = true;

    protected $fillable = ['user_id', 'workspace_type', 'workspace_id', 'name', 'shared', 'state'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shared' => 'boolean',
            'state' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Crm::userModel(), 'user_id');
    }

    /**
     * The views the user may apply in a workspace: their own, and the shared ones of a workspace they work in.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsableBy(Builder $query, Authenticatable $user, ?Workspace $workspace): Builder
    {
        $key = $workspace?->key() ?? '';
        $shares = app(Access::class)->works($user, $workspace);

        return $query->where(fn (Builder $inner) => $inner
            ->where(fn (Builder $own) => $own->where('user_id', Crm::userKey($user))->where('workspace_key', $key))
            ->when($shares, fn (Builder $either) => $either->orWhere(fn (Builder $shared) => $shared->where('shared', true)->where('workspace_key', $key))));
    }
}
