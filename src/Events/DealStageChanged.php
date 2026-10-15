<?php

namespace Sellinnate\SalesCrm\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Stage;

/**
 * A deal moved to another stage of its pipeline.
 */
class DealStageChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Deal $deal, public Stage $from, public Stage $to, public ?Authenticatable $by = null) {}
}
