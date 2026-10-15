<?php

namespace Sellinnate\SalesCrm\Data;

/**
 * Who an email of a deal leaves as: the From address and name, the Reply-To and the mailer of
 * `config/mail.php` it goes through (null: the default one).
 */
final class Sender
{
    public function __construct(
        public readonly string $address,
        public readonly ?string $name = null,
        public readonly ?string $replyTo = null,
        public readonly ?string $mailer = null,
    ) {}
}
