<?php

namespace Sellinnate\SalesCrm\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Sellinnate\SalesCrm\Contracts\Authorizer;
use Sellinnate\SalesCrm\Contracts\WorkspaceResolver;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The default rules:
 *
 * - entering the CRM is the `sales-crm.access` gate, configuring it the `sales-crm.manage` gate
 *   (with the workspace as argument): both allow every authenticated user until the application
 *   defines them;
 * - with `deal_visibility` = "workspace" everybody in a workspace works all its deals; with "owner"
 *   who does not manage the workspace sees only the deals they own and the unassigned ones.
 */
class DefaultAuthorizer implements Authorizer
{
    public function __construct(private WorkspaceResolver $workspaces) {}

    public function canAccess(Authenticatable $user): bool
    {
        return Gate::forUser($user)->allows((string) config('sales-crm.authorization.access_ability', 'sales-crm.access'));
    }

    public function canManage(Authenticatable $user, ?Workspace $workspace): bool
    {
        return $this->canAccess($user)
            && Gate::forUser($user)->allows((string) config('sales-crm.authorization.manage_ability', 'sales-crm.manage'), [$workspace?->model()]);
    }

    public function canSeeDeal(Authenticatable $user, Deal $deal): bool
    {
        if (! $this->ownerVisibility()) {
            return true;
        }

        return $deal->owner_id === null
            || Crm::sameUser($deal->owner_id, Crm::userKey($user))
            || $this->canManage($user, Workspace::fromRecord($deal));
    }

    public function constrainDeals(Builder $query, Authenticatable $user): void
    {
        if (! $this->ownerVisibility()) {
            return;
        }

        $table = $query->getModel()->getTable();

        if (! $this->workspaces->enabled()) {
            if (! $this->canManage($user, null)) {
                $query->where(fn (Builder $mine) => $mine->whereNull($table.'.owner_id')->orWhere($table.'.owner_id', Crm::userKey($user)));
            }

            return;
        }

        $managed = array_values(array_filter($this->workspaces->workspacesFor($user), fn (Workspace $workspace): bool => $this->canManage($user, $workspace)));

        $query->where(function (Builder $visible) use ($table, $user, $managed): void {
            $visible->whereNull($table.'.owner_id')->orWhere($table.'.owner_id', Crm::userKey($user));

            foreach ($managed as $workspace) {
                $visible->orWhere(fn (Builder $all) => $all->where($table.'.workspace_type', $workspace->type)->where($table.'.workspace_id', $workspace->id));
            }
        });
    }

    private function ownerVisibility(): bool
    {
        return config('sales-crm.authorization.deal_visibility') === 'owner';
    }
}
