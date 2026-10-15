<?php

namespace Sellinnate\SalesCrm\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Sellinnate\SalesCrm\Models\Deal;

/**
 * Fields of a deal changed. `$changes` lists the names of the changed attributes (never their values).
 */
class DealUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  list<string>  $changes
     */
    public function __construct(public Deal $deal, public array $changes, public ?Authenticatable $by = null) {}
}
