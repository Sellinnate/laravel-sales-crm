<?php

namespace Sellinnate\SalesCrm\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * Companies are shared by whoever works in the workspace; deleting one is for managers.
 */
class CompanyPolicy
{
    public function __construct(protected Access $access) {}

    public function viewAny(Authenticatable $user): bool
    {
        return $this->access->canAccess($user);
    }

    public function view(Authenticatable $user, Company $company): bool
    {
        return $this->access->worksOn($user, $company);
    }

    public function create(Authenticatable $user, ?Workspace $workspace = null): bool
    {
        return $this->access->works($user, $workspace);
    }

    public function update(Authenticatable $user, Company $company): bool
    {
        return $this->access->worksOn($user, $company);
    }

    public function delete(Authenticatable $user, Company $company): bool
    {
        return $this->access->canManage($user, $company->workspaceRef());
    }
}
