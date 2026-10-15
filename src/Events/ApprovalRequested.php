<?php

namespace Sellinnate\SalesCrm\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Sellinnate\SalesCrm\Models\Deal;

/**
 * The approval of a deal was asked to its approver.
 */
class ApprovalRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Deal $deal, public ?Authenticatable $by = null) {}
}
