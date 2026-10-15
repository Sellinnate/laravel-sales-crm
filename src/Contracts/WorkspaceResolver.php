<?php

namespace Sellinnate\SalesCrm\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * Splits the CRM by workspace (optional multi-tenancy). The default resolver keeps one CRM for the
 * whole application; bind your own to tie pipelines, deals and contacts to your Team, Company or
 * Client model.
 */
interface WorkspaceResolver
{
    /**
     * Whether the records are split by workspace. When false every record has a null workspace.
     */
    public function enabled(): bool;

    /**
     * The workspaces the user works in, for the selectors of the UI and the checks of the policies.
     *
     * @return list<Workspace>
     */
    public function workspacesFor(Authenticatable $user): array;

    /**
     * Whether the user works in the workspace (null: the shared CRM of an application without workspaces).
     */
    public function allows(Authenticatable $user, ?Workspace $workspace): bool;

    /**
     * Restrict a query of CRM rows to the workspaces of the user.
     *
     * @param  Builder<covariant Model>|QueryBuilder  $query
     */
    public function constrain(Builder|QueryBuilder $query, Authenticatable $user, string $typeColumn, string $idColumn): void;

    /**
     * The users who work in the workspace: deal owners, activity assignees, approvers and the people a
     * note can mention.
     *
     * @return Builder<Model>
     */
    public function members(?Workspace $workspace): Builder;

    /**
     * The name of the workspace shown in the UI.
     */
    public function label(?Workspace $workspace): string;
}
