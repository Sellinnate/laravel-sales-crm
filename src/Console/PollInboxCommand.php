<?php

namespace Sellinnate\SalesCrm\Console;

use Illuminate\Console\Command;
use Sellinnate\SalesCrm\Inbound\Inbound;

/**
 * Reads the new emails of the inbound driver and stores those that belong to the CRM. Schedule it
 * every few minutes when `sales-crm.email.inbound.driver` is a polling driver (imap).
 */
class PollInboxCommand extends Command
{
    protected $signature = 'sales-crm:poll-inbox {--driver= : The inbound driver to poll (default: the configured one)}';

    protected $description = 'Read the replies to the CRM emails from the inbound driver';

    public function handle(Inbound $inbound): int
    {
        $driver = $this->option('driver');
        $result = $inbound->poll(is_string($driver) && $driver !== '' ? $driver : null);

        $this->components->info($result['read'].' email(s) read, '.$result['stored'].' stored on the CRM.');

        if ($result['failed'] > 0) {
            $this->components->warn('An email could not be stored: it is read again at the next poll.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
