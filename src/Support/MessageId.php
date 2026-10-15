<?php

namespace Sellinnate\SalesCrm\Support;

use Illuminate\Support\Str;

/**
 * Message-IDs of the emails of the CRM: generated for the outgoing ones, normalised (without angle
 * brackets, lowercase domain) to thread the answers.
 */
final class MessageId
{
    public static function generate(string $fromEmail): string
    {
        $domain = Str::lower(Str::after($fromEmail, '@'));
        $domain = $domain !== '' && $domain !== $fromEmail ? $domain : 'sales-crm.local';

        return 'crm.'.Str::lower((string) Str::ulid()).'@'.$domain;
    }

    public static function normalize(?string $id): ?string
    {
        $id = trim((string) $id);
        $id = trim($id, '<> ');

        if ($id === '' || ! str_contains($id, '@')) {
            return null;
        }

        return Str::before($id, '@').'@'.Str::lower(Str::after($id, '@'));
    }
}
