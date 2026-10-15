<?php

namespace Sellinnate\SalesCrm\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Sellinnate\SalesCrm\Models\Activity;

/**
 * The reminder of an activity is due (fired by `sales-crm:remind-activities`).
 */
class ActivityDue implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Activity $activity) {}
}
