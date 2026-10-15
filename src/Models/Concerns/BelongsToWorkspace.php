<?php

namespace Sellinnate\SalesCrm\Models\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * A CRM record of a workspace (`workspace_type`, `workspace_id`; both null in the shared CRM).
 * Tables with unique indexes per workspace also keep `workspace_key`, written here.
 *
 * @property string|null $workspace_type
 * @property int|string|null $workspace_id
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::saving(function (Model $record): void {
            if (property_exists($record, 'hasWorkspaceKey') && $record->hasWorkspaceKey) {
                $record->setAttribute('workspace_key', Workspace::fromRecord($record)?->key() ?? '');
            }
        });
    }

    /**
     * The workspace model of the host.
     *
     * @return MorphTo<Model, $this>
     */
    public function workspace(): MorphTo
    {
        return $this->morphTo('workspace');
    }

    /**
     * The workspace as a value object (null in the shared CRM).
     */
    public function workspaceRef(): ?Workspace
    {
        return Workspace::fromRecord($this);
    }

    /**
     * Put the record in a workspace (null: the shared CRM).
     */
    public function setWorkspace(Model|Workspace|null $workspace): static
    {
        $workspace = Workspace::of($workspace);
        $this->setAttribute('workspace_type', $workspace?->type);
        $this->setAttribute('workspace_id', $workspace?->id);

        return $this;
    }

    /**
     * Whether the record is in the workspace.
     */
    public function inWorkspace(Model|Workspace|null $workspace): bool
    {
        return Workspace::same($this->workspaceRef(), Workspace::of($workspace));
    }

    /**
     * Scope to the records of a workspace (or of the shared CRM).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInWorkspace(Builder $query, Model|Workspace|null $workspace): Builder
    {
        $workspace = Workspace::of($workspace);
        $table = $this->getTable();

        return $workspace === null
            ? $query->whereNull($table.'.workspace_type')
            : $query->where($table.'.workspace_type', $workspace->type)->where($table.'.workspace_id', $workspace->id);
    }

    /**
     * Scope to the records the user may see: the workspaces they work in and, for the records of a
     * deal (activities, notes, documents, emails), the deals they see — `deal_visibility` "owner" or
     * an Authorizer of the host may hide some deals of a workspace.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $user): Builder
    {
        app(Access::class)->constrain($query, $user);

        if ($this->followsDealVisibility() && $user !== null) {
            $column = $this->qualifyColumn('deal_id');
            $query->where(fn (Builder $records) => $records->whereNull($column)->orWhereIn($column, Deal::query()->visibleTo($user)->select(Crm::table('deals').'.id')));
        }

        return $query;
    }

    /**
     * Whether the records belong to a deal (a nullable `deal_id`) and are seen with it.
     */
    protected function followsDealVisibility(): bool
    {
        return false;
    }
}
