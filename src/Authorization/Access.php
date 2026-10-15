<?php

namespace Sellinnate\SalesCrm\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Sellinnate\SalesCrm\Contracts\Authorizer;
use Sellinnate\SalesCrm\Contracts\WorkspaceResolver;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * One place for the scope of the CRM, used by the policies, the actions, the UI, the API and the MCP
 * tools: the workspaces of the user ({@see WorkspaceResolver}) and the rules of the {@see Authorizer}.
 */
class Access
{
    public function __construct(private WorkspaceResolver $workspaces, private Authorizer $authorizer) {}

    public function resolver(): WorkspaceResolver
    {
        return $this->workspaces;
    }

    public function authorizer(): Authorizer
    {
        return $this->authorizer;
    }

    public function canAccess(?Authenticatable $user): bool
    {
        return $user !== null && $this->authorizer->canAccess($user);
    }

    public function canManage(?Authenticatable $user, Model|Workspace|null $workspace): bool
    {
        $workspace = Workspace::of($workspace);

        return $user !== null && $this->works($user, $workspace) && $this->authorizer->canManage($user, $workspace);
    }

    /**
     * Whether the user works in the workspace.
     */
    public function works(?Authenticatable $user, Model|Workspace|null $workspace): bool
    {
        return $user !== null && $this->canAccess($user) && $this->workspaces->allows($user, Workspace::of($workspace));
    }

    /**
     * Whether the user works in the workspace of a CRM record.
     */
    public function worksOn(?Authenticatable $user, Model $record): bool
    {
        return $this->works($user, Workspace::fromRecord($record));
    }

    public function canSeeDeal(?Authenticatable $user, Deal $deal): bool
    {
        return $user !== null && $this->worksOn($user, $deal) && $this->authorizer->canSeeDeal($user, $deal);
    }

    /**
     * Restrict a query of CRM rows to the workspaces of the user (nothing without access).
     *
     * @param  Builder<covariant Model>|QueryBuilder  $query
     */
    public function constrain(Builder|QueryBuilder $query, ?Authenticatable $user, ?string $table = null): void
    {
        $table ??= $query instanceof Builder ? $query->getModel()->getTable() : (is_string($query->from) ? $query->from : '');

        if (! $this->canAccess($user)) {
            $query->whereRaw('1 = 0');

            return;
        }

        /** @var Authenticatable $user */
        $this->workspaces->constrain($query, $user, $table.'.workspace_type', $table.'.workspace_id');
    }

    /**
     * Restrict a query of deals to the ones the user sees.
     *
     * @param  Builder<Deal>  $query
     */
    public function constrainDeals(Builder $query, ?Authenticatable $user): void
    {
        $this->constrain($query, $user);

        if ($user !== null && $this->canAccess($user)) {
            $this->authorizer->constrainDeals($query, $user);
        }
    }

    /**
     * The workspaces the user works in (empty without workspaces).
     *
     * @return list<Workspace>
     */
    public function workspacesFor(?Authenticatable $user): array
    {
        return $user === null || ! $this->canAccess($user) || ! $this->workspaces->enabled() ? [] : $this->workspaces->workspacesFor($user);
    }

    /**
     * The users who work in the workspace.
     *
     * @return Builder<Model>
     */
    public function members(Model|Workspace|null $workspace): Builder
    {
        return $this->workspaces->members(Workspace::of($workspace));
    }

    /**
     * Whether the user (or the key of a user) works in the workspace: owner, assignee, approver, mention.
     */
    public function isMember(Authenticatable|Model|int|string|null $user, Model|Workspace|null $workspace): bool
    {
        $key = $user instanceof Authenticatable || $user instanceof Model ? Crm::userKey($user) : $user;

        return Crm::isUserKey($key) && $this->members($workspace)->whereKey($key)->exists();
    }

    public function label(Model|Workspace|null $workspace): string
    {
        return $this->workspaces->label(Workspace::of($workspace));
    }
}
