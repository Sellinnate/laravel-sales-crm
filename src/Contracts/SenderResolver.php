<?php

namespace Sellinnate\SalesCrm\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Sellinnate\SalesCrm\Data\Sender;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * Decides the From, the Reply-To and the mailer of the emails sent from the deals. Bind your own to
 * send from a domain per workspace or through the personal mailbox of each user.
 */
interface SenderResolver
{
    public function resolve(?Authenticatable $user, ?Workspace $workspace): Sender;
}
