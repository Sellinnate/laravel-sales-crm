<?php

namespace Sellinnate\SalesCrm\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * Pipelines and stages are seen by whoever works in the workspace and configured by its managers.
 * `create` with a workspace also guards the other settings of the workspace (custom fields,
 * templates, imports).
 */
class PipelinePolicy
{
    public function __construct(protected Access $access) {}

    public function viewAny(Authenticatable $user): bool
    {
        return $this->access->canAccess($user);
    }

    public function view(Authenticatable $user, Pipeline $pipeline): bool
    {
        return $this->access->worksOn($user, $pipeline);
    }

    public function create(Authenticatable $user, ?Workspace $workspace = null): bool
    {
        return $this->access->canManage($user, $workspace);
    }

    public function update(Authenticatable $user, Pipeline $pipeline): bool
    {
        return $this->access->canManage($user, $pipeline->workspaceRef());
    }

    public function delete(Authenticatable $user, Pipeline $pipeline): bool
    {
        return $this->update($user, $pipeline);
    }
}
