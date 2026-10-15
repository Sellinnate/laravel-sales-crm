<?php

namespace Sellinnate\SalesCrm\Inbound\Drivers;

use Sellinnate\SalesCrm\Contracts\InboundDriver;

/**
 * No inbound source: replies are not read (the default).
 */
class NullDriver implements InboundDriver
{
    public function fetch(): iterable
    {
        return [];
    }
}
