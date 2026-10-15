<?php

namespace Sellinnate\SalesCrm\Inbound;

use DirectoryTree\ImapEngine\Mailbox;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Manager;
use RuntimeException;
use Sellinnate\SalesCrm\Contracts\InboundDriver;
use Sellinnate\SalesCrm\Inbound\Drivers\ImapDriver;
use Sellinnate\SalesCrm\Inbound\Drivers\NullDriver;

/**
 * The inbound drivers polled by `sales-crm:poll-inbox`, chosen by `sales-crm.email.inbound.driver`.
 * Add yours with `SalesCrm::inbound()->drivers()->extend('name', fn ($app) => new MyDriver)`.
 * The "webhook" driver is push-based: polling it reads nothing.
 *
 * @method InboundDriver driver(?string $driver = null)
 */
class InboundManager extends Manager
{
    public function getDefaultDriver(): string
    {
        $driver = $this->config->get('sales-crm.email.inbound.driver', 'null');

        return is_string($driver) && $driver !== '' ? $driver : 'null';
    }

    public function createNullDriver(): InboundDriver
    {
        return new NullDriver;
    }

    public function createWebhookDriver(): InboundDriver
    {
        return new NullDriver;
    }

    public function createImapDriver(): InboundDriver
    {
        if (! class_exists(Mailbox::class)) {
            throw new RuntimeException('sales-crm: the imap inbound driver needs directorytree/imapengine (composer require directorytree/imapengine).');
        }

        /** @var array<string, mixed> $config */
        $config = (array) $this->config->get('sales-crm.email.inbound.imap', []);

        if (blank($config['host'] ?? null) || blank($config['username'] ?? null)) {
            throw new RuntimeException('sales-crm: set SALES_CRM_IMAP_HOST, SALES_CRM_IMAP_USERNAME and SALES_CRM_IMAP_PASSWORD for the imap inbound driver.');
        }

        $mailbox = new Mailbox([
            'host' => (string) $config['host'],
            'port' => (int) ($config['port'] ?? 993),
            'encryption' => $config['encryption'] ?? 'ssl',
            'username' => (string) $config['username'],
            'password' => (string) ($config['password'] ?? ''),
        ]);

        return new ImapDriver(
            $mailbox,
            $this->container->make(Repository::class),
            array_values(array_map('strval', (array) ($config['folders'] ?? ['INBOX']))),
            array_values(array_map('strval', (array) ($config['sent_folders'] ?? []))),
            max(1, (int) ($config['batch'] ?? 50)),
        );
    }
}
