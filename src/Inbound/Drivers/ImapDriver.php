<?php

namespace Sellinnate\SalesCrm\Inbound\Drivers;

use Carbon\CarbonImmutable;
use DirectoryTree\ImapEngine\Address;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Sellinnate\SalesCrm\Contracts\InboundDriver;
use Sellinnate\SalesCrm\Data\InboundEmail;
use Throwable;

/**
 * Reads a mailbox over IMAP with directorytree/imapengine: the new messages of the configured
 * folders (and of the sent folders, for the emails users write from their own client), from a UID
 * cursor kept in the cache. The first run reads the last two days.
 */
class ImapDriver implements InboundDriver
{
    /**
     * @param  list<string>  $folders
     * @param  list<string>  $sentFolders
     */
    public function __construct(
        private MailboxInterface $mailbox,
        private Cache $cache,
        private array $folders = ['INBOX'],
        private array $sentFolders = [],
        private int $batch = 50,
    ) {}

    public function fetch(): iterable
    {
        foreach ([...$this->folders, ...$this->sentFolders] as $path) {
            $folder = $this->mailbox->folders()->find($path);

            if ($folder === null) {
                continue;
            }

            $key = 'sales-crm:imap:'.md5($this->mailbox->config('host').'|'.$this->mailbox->config('username').'|'.$path);
            $cursor = $this->cache->get($key);
            // Oldest first: the cursor is the highest UID read, so a newer batch must never come before an older one.
            $query = $folder->messages()->withHeaders()->withBody()->leaveUnread()->setFetchOrderAsc();
            is_numeric($cursor) ? $query->uid((int) $cursor + 1, INF) : $query->since(CarbonImmutable::now()->subDays(2));
            $last = is_numeric($cursor) ? (int) $cursor : 0;

            foreach ($query->limit($this->batch)->get() as $message) {
                $last = max($last, $message->uid());

                try {
                    yield self::toInbound($message);
                } finally {
                    $this->cache->forever($key, $last);
                }
            }
        }
    }

    public static function toInbound(MessageInterface $message): InboundEmail
    {
        $address = fn (?Address $address): ?string => $address === null || $address->email() === '' ? null : strtolower($address->email());
        $header = function (string $name) use ($message): ?string {
            try {
                $value = $message->header($name)?->getValue();
            } catch (Throwable) {
                return null;
            }

            return is_string($value) && $value !== '' ? $value : null;
        };
        $date = $message->date();

        return new InboundEmail(
            messageId: $message->messageId(),
            fromEmail: $address($message->from()),
            fromName: $message->from()?->name() ?: null,
            to: array_values(array_filter(array_map($address, $message->to()))),
            cc: array_values(array_filter(array_map($address, $message->cc()))),
            subject: $message->subject(),
            text: $message->text(),
            html: $message->html(),
            inReplyTo: $message->inReplyTo()[0] ?? null,
            references: array_values(array_filter(preg_split('/\s+/', (string) $header('References')) ?: [])),
            date: $date === null ? null : CarbonImmutable::instance($date),
            replyTo: $address($message->replyTo()),
            headers: array_filter([
                'auto-submitted' => $header('Auto-Submitted'),
                'precedence' => $header('Precedence'),
                'x-autoreply' => $header('X-Autoreply'),
                'x-autorespond' => $header('X-Autorespond'),
                'x-auto-response-suppress' => $header('X-Auto-Response-Suppress'),
            ], fn (?string $value): bool => $value !== null),
        );
    }
}
