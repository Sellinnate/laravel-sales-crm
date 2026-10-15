<?php

namespace Sellinnate\SalesCrm\Inbound;

use Illuminate\Database\Eloquent\Model;
use Sellinnate\SalesCrm\Data\InboundEmail;
use Sellinnate\SalesCrm\Models\Email;
use Sellinnate\SalesCrm\Workspaces\Workspace;
use Throwable;

/**
 * The inbound side of the CRM emails: `receive()` an email from any source (a webhook, your own
 * mail processing), or `poll()` the configured driver.
 */
class Inbound
{
    public function __construct(private InboundManager $manager, private HandleInboundEmail $handler) {}

    /**
     * Match and store an inbound email; null when it does not belong to the CRM.
     *
     * @param  Model|Workspace|false|null  $workspace  false: any workspace; a workspace: only that one
     */
    public function receive(InboundEmail $email, Model|Workspace|false|null $workspace = false): ?Email
    {
        return $this->handler->handle($email, $workspace === false ? false : Workspace::of($workspace));
    }

    /**
     * Read the new emails of the configured driver.
     *
     * @return array{read: int, stored: int}
     */
    public function poll(?string $driver = null): array
    {
        $read = 0;
        $stored = 0;

        foreach ($this->manager->driver($driver)->fetch() as $email) {
            $read++;

            try {
                $stored += $this->receive($email) === null ? 0 : 1;
            } catch (Throwable $exception) {
                // One broken email never stops the others; its text is never logged.
                report($exception);
            }
        }

        return ['read' => $read, 'stored' => $stored];
    }

    public function drivers(): InboundManager
    {
        return $this->manager;
    }
}
