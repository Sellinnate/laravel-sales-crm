<?php

namespace Sellinnate\SalesCrm\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * Deals are worked by whoever works in their workspace (and, with owner visibility, owns them or
 * manages the workspace); deleting one is for managers.
 */
class DealPolicy
{
    public function __construct(protected Access $access) {}

    public function viewAny(Authenticatable $user): bool
    {
        return $this->access->canAccess($user);
    }

    public function view(Authenticatable $user, Deal $deal): bool
    {
        return $this->access->canSeeDeal($user, $deal);
    }

    public function create(Authenticatable $user, ?Workspace $workspace = null): bool
    {
        return $this->access->works($user, $workspace);
    }

    public function update(Authenticatable $user, Deal $deal): bool
    {
        return $this->access->canSeeDeal($user, $deal);
    }

    public function delete(Authenticatable $user, Deal $deal): bool
    {
        return $this->access->canSeeDeal($user, $deal) && $this->access->canManage($user, $deal->workspaceRef());
    }
}
