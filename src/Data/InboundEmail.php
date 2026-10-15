<?php

namespace Sellinnate\SalesCrm\Data;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * An email read by an inbound driver (IMAP, webhook) or pushed by the host with
 * `SalesCrm::inbound()->receive()`: the envelope and the text the CRM keeps.
 */
final class InboundEmail
{
    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $references
     * @param  array<string, string>  $headers  lowercase header name => value (for auto-reply and bounce detection)
     */
    public function __construct(
        public readonly ?string $messageId,
        public readonly ?string $fromEmail,
        public readonly ?string $fromName = null,
        public readonly array $to = [],
        public readonly array $cc = [],
        public readonly ?string $subject = null,
        public readonly ?string $text = null,
        public readonly ?string $html = null,
        public readonly ?string $inReplyTo = null,
        public readonly array $references = [],
        public readonly ?CarbonImmutable $date = null,
        public readonly ?string $replyTo = null,
        public readonly array $headers = [],
    ) {}

    /**
     * An email from an array, as the JSON of the inbound webhook:
     * message_id, from (address or {email, name}), to, cc, subject, text, html, in_reply_to, references,
     * date, reply_to, headers.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $from = $data['from'] ?? null;
        $fromEmail = is_array($from) ? self::string($from['email'] ?? null) : self::string($from);
        $fromName = is_array($from) ? self::string($from['name'] ?? null) : self::string($data['from_name'] ?? null);

        if ($fromEmail !== null && preg_match('/^(.*)<([^>]+)>\s*$/', $fromEmail, $matches) === 1) {
            $fromName ??= trim(trim($matches[1]), '"') ?: null;
            $fromEmail = trim($matches[2]);
        }

        $date = null;

        if (is_string($data['date'] ?? null) && $data['date'] !== '') {
            try {
                $date = CarbonImmutable::parse($data['date']);
            } catch (Throwable) {
                $date = null;
            }
        }

        $headers = [];

        foreach (is_array($data['headers'] ?? null) ? $data['headers'] : [] as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $headers[strtolower($name)] = (string) $value;
            }
        }

        return new self(
            messageId: self::string($data['message_id'] ?? null),
            fromEmail: $fromEmail === null ? null : strtolower($fromEmail),
            fromName: $fromName,
            to: self::addresses($data['to'] ?? []),
            cc: self::addresses($data['cc'] ?? []),
            subject: self::string($data['subject'] ?? null),
            text: self::string($data['text'] ?? null),
            html: self::string($data['html'] ?? null),
            inReplyTo: self::string($data['in_reply_to'] ?? null),
            references: self::references($data['references'] ?? []),
            date: $date,
            replyTo: self::string($data['reply_to'] ?? null),
            headers: $headers,
        );
    }

    private static function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @return list<string>
     */
    private static function addresses(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/[,;]/', is_scalar($value) ? (string) $value : '');
        $addresses = [];

        foreach ($items ?: [] as $item) {
            $address = is_array($item) ? self::string($item['email'] ?? null) : self::string($item);

            if ($address === null) {
                continue;
            }

            if (preg_match('/<([^>]+)>/', $address, $matches) === 1) {
                $address = $matches[1];
            }

            $addresses[] = strtolower(trim($address));
        }

        return array_values(array_unique(array_filter($addresses, fn (string $address): bool => str_contains($address, '@'))));
    }

    /**
     * @return list<string>
     */
    private static function references(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/\s+/', is_scalar($value) ? (string) $value : '');

        return array_values(array_filter(array_map(fn (mixed $id): string => is_scalar($id) ? trim((string) $id) : '', $items ?: []), fn (string $id): bool => $id !== ''));
    }
}
