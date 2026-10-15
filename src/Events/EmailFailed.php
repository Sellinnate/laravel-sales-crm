<?php

namespace Sellinnate\SalesCrm\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Sellinnate\SalesCrm\Models\Email;

/**
 * An email of a deal could not be delivered; the reason is on `$email->error`.
 */
class EmailFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Email $email) {}
}
