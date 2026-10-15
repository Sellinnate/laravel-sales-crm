<?php

namespace Sellinnate\SalesCrm\Workspaces;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Sellinnate\SalesCrm\Contracts\WorkspaceResolver;

/**
 * One CRM for the whole application: no workspace, every user who may access the CRM works in it.
 */
class SingleWorkspaceResolver implements WorkspaceResolver
{
    use QueriesMembers;

    public function enabled(): bool
    {
        return false;
    }

    public function workspacesFor(Authenticatable $user): array
    {
        return [];
    }

    public function allows(Authenticatable $user, ?Workspace $workspace): bool
    {
        return $workspace === null;
    }

    public function constrain(Builder|QueryBuilder $query, Authenticatable $user, string $typeColumn, string $idColumn): void
    {
        // One shared CRM: nothing to restrict.
    }

    public function members(?Workspace $workspace): Builder
    {
        return $this->usersQuery();
    }

    public function label(?Workspace $workspace): string
    {
        return (string) config('sales-crm.ui.brand', 'Sales CRM');
    }
}
