<?php

namespace Sellinnate\SalesCrm\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * Who sees and changes what in the CRM. The policies, the actions, the UI, the JSON API and the MCP
 * tools all ask this contract, on top of the workspace scope of {@see WorkspaceResolver}.
 */
interface Authorizer
{
    /**
     * Whether the user may enter the CRM at all.
     */
    public function canAccess(Authenticatable $user): bool;

    /**
     * Whether the user configures the CRM of the workspace: pipelines and stages, custom fields,
     * email templates, imports, deleting deals and deciding any approval.
     */
    public function canManage(Authenticatable $user, ?Workspace $workspace): bool;

    /**
     * Whether the user sees (and works) the deal, within a workspace they work in.
     */
    public function canSeeDeal(Authenticatable $user, Deal $deal): bool;

    /**
     * Restrict a query of deals to the ones the user sees within their workspaces.
     *
     * @param  Builder<Deal>  $query
     */
    public function constrainDeals(Builder $query, Authenticatable $user): void;
}
