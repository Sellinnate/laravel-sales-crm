<?php

namespace Sellinnate\SalesCrm\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\Person;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * People are shared by whoever works in the workspace; deleting one is for managers.
 */
class PersonPolicy
{
    public function __construct(protected Access $access) {}

    public function viewAny(Authenticatable $user): bool
    {
        return $this->access->canAccess($user);
    }

    public function view(Authenticatable $user, Person $person): bool
    {
        return $this->access->worksOn($user, $person);
    }

    public function create(Authenticatable $user, ?Workspace $workspace = null): bool
    {
        return $this->access->works($user, $workspace);
    }

    public function update(Authenticatable $user, Person $person): bool
    {
        return $this->access->worksOn($user, $person);
    }

    public function delete(Authenticatable $user, Person $person): bool
    {
        return $this->access->canManage($user, $person->workspaceRef());
    }
}
