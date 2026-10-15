<?php

namespace Sellinnate\SalesCrm\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Sellinnate\SalesCrm\Models\Import;

/**
 * An import of deals finished (done or failed); counts and report are on the import.
 */
class ImportFinished implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Import $import) {}
}
