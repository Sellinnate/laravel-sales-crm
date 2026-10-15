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
     * The emails not read yet. The driver keeps its own cursor and moves it past an email only when
     * the next one is asked for (a generator resuming after `yield`): polling stops at an email that
     * could not be stored, which is then returned again by the next poll.
     *
     * @return iterable<InboundEmail>
     */
    public function fetch(): iterable;
}
