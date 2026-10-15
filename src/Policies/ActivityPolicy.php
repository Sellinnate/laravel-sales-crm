<?php

namespace Sellinnate\SalesCrm\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\Activity;

/**
 * Activities are worked by whoever works in the workspace (and sees their deal).
 */
class ActivityPolicy
{
    public function __construct(protected Access $access) {}

    public function view(Authenticatable $user, Activity $activity): bool
    {
        return $this->access->worksOn($user, $activity) && ($activity->deal === null || $this->access->canSeeDeal($user, $activity->deal));
    }

    public function update(Authenticatable $user, Activity $activity): bool
    {
        return $this->view($user, $activity);
    }

    public function delete(Authenticatable $user, Activity $activity): bool
    {
        return $this->view($user, $activity);
    }
}
