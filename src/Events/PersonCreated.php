<?php

namespace Sellinnate\SalesCrm\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Sellinnate\SalesCrm\Models\Person;

/**
 * A person was created.
 */
class PersonCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Person $person, public ?Authenticatable $by = null) {}
}
