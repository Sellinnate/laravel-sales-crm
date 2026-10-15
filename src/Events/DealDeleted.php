<?php

namespace Sellinnate\SalesCrm\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A deal was deleted. `$attributes` keeps its attributes as they were.
 */
class DealDeleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(public int $dealId, public array $attributes, public ?Authenticatable $by = null) {}
}
