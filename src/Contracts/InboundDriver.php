<?php

namespace Sellinnate\SalesCrm\Contracts;

use Sellinnate\SalesCrm\Data\InboundEmail;

/**
 * A source of inbound emails read by `sales-crm:poll-inbox` (IMAP, an API of your mail provider…).
 * Push-based sources (webhooks) call `SalesCrm::inbound()->receive()` instead.
 */
interface InboundDriver
{
    /**
     * The emails not read yet. The driver keeps its own cursor: an email is returned once.
     *
     * @return iterable<InboundEmail>
     */
    public function fetch(): iterable;
}
