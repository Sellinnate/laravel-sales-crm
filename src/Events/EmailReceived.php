<?php

namespace Sellinnate\SalesCrm\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Sellinnate\SalesCrm\Models\Email;

/**
 * An inbound email was matched to a deal or a person and stored.
 */
class EmailReceived implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Email $email) {}
}
