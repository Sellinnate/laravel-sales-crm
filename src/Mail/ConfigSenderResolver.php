<?php

namespace Sellinnate\SalesCrm\Mail;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Sellinnate\SalesCrm\Contracts\SenderResolver;
use Sellinnate\SalesCrm\Data\Sender;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The default sender, from `sales-crm.email`: the From address of the configuration (or of
 * `mail.from`), the name of the user who writes, and the Reply-To of `reply_to` ("user", "from" or an
 * address read by an inbound driver).
 */
class ConfigSenderResolver implements SenderResolver
{
    public function resolve(?Authenticatable $user, ?Workspace $workspace): Sender
    {
        $address = config('sales-crm.email.from.address') ?: config('mail.from.address');

        if (! is_string($address) || ! str_contains($address, '@')) {
            throw new InvalidArgumentException('sales-crm: set `sales-crm.email.from.address` (or `mail.from.address`) to send emails from the deals.');
        }

        $name = config('sales-crm.email.from_name') === 'user' && $user !== null
            ? Crm::userName($user)
            : (config('sales-crm.email.from.name') ?: config('mail.from.name'));
        $replyTo = match ($reply = (string) config('sales-crm.email.reply_to', 'user')) {
            'user' => Crm::userEmail($user),
            'from', '' => null,
            default => str_contains($reply, '@') ? $reply : null,
        };
        $mailer = config('sales-crm.email.mailer');

        return new Sender($address, is_string($name) && $name !== '' ? $name : null, $replyTo, is_string($mailer) && $mailer !== '' ? $mailer : null);
    }
}
