<?php

namespace Sellinnate\SalesCrm\Inbound;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Sellinnate\SalesCrm\Data\InboundEmail;
use Sellinnate\SalesCrm\Enums\EmailDirection;
use Sellinnate\SalesCrm\Enums\EmailStatus;
use Sellinnate\SalesCrm\Events\EmailReceived;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Email;
use Sellinnate\SalesCrm\Models\Person;
use Sellinnate\SalesCrm\Services\DealActivityState;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Support\MessageId;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * An email read by an inbound driver enters the timeline only when it belongs there:
 *
 * - an answer in the thread of an email of a deal (In-Reply-To, References), in the workspaces that
 *   have that thread only;
 * - else an email from a person of the CRM, on the person and their most recent open deal;
 * - else an email a user of the CRM sent from their own mail client to people of the CRM (the "BCC
 *   dropbox": put the inbound address in Bcc), stored as outbound.
 *
 * Everything else — personal mail, a person found in more workspaces, auto-replies and bounces — is
 * not saved. Once per workspace and Message-ID.
 */
class HandleInboundEmail
{
    private const MAX_ID = 255;

    private const MAX_TEXT = 200_000;

    public function __construct(private DealActivityState $state) {}

    /**
     * @param  Workspace|false|null  $workspace  false: any workspace; null or a workspace: only that one
     */
    public function handle(InboundEmail $email, Workspace|false|null $workspace = false): ?Email
    {
        if (self::isAutomatic($email)) {
            return null;
        }

        $messageId = MessageId::normalize($email->messageId);
        $messageId = $messageId !== null && strlen($messageId) <= self::MAX_ID ? $messageId : self::syntheticId($email);
        $threads = $this->threads($email, $workspace);
        // Written by a user from their own client (a sent folder, the BCC dropbox), in a thread or not.
        $outbound = $this->senderIsUser($email);

        if ($threads === []) {
            $people = $this->people($email, $workspace, $outbound);
            // The same person in more workspaces says nothing of whose matter it is: not kept.
            $targets = count($people) === 1 ? $people : [];
        } else {
            $targets = $threads;
        }

        $stored = null;

        foreach ($targets as [$target, $dealId, $personId]) {
            if (! Email::query()->where('workspace_key', $target?->key() ?? '')->where('message_id', $messageId)->exists()) {
                $stored = $this->store($email, $messageId, $target, $dealId, $personId, $outbound) ?? $stored;
            }
        }

        return $stored;
    }

    /**
     * Auto-replies, out of office messages and bounces: never kept.
     */
    public static function isAutomatic(InboundEmail $email): bool
    {
        $headers = $email->headers;
        $auto = strtolower($headers['auto-submitted'] ?? 'no');
        $precedence = strtolower($headers['precedence'] ?? '');
        $from = strtolower((string) $email->fromEmail);
        $subject = strtolower((string) $email->subject);

        return ($auto !== '' && $auto !== 'no')
            || isset($headers['x-autoreply']) || isset($headers['x-autorespond'])
            || in_array($precedence, ['bulk', 'junk', 'auto_reply', 'list'], true)
            || str_starts_with($from, 'mailer-daemon@') || str_starts_with($from, 'postmaster@')
            || preg_match('/^(auto(matic)?[ -]?reply|out of (the )?office|undeliver|delivery status notification|mail delivery failed|returned mail|risposta automatica|fuori sede|non recapitat)/u', $subject) === 1;
    }

    public static function syntheticId(InboundEmail $email): string
    {
        return 'synthetic.'.sha1(implode('|', [(string) $email->fromEmail, (string) $email->date?->toIso8601String(), (string) $email->subject, (string) $email->text, (string) $email->html])).'@sales-crm.local';
    }

    private function store(InboundEmail $email, string $messageId, ?Workspace $workspace, ?int $dealId, ?int $personId, bool $outbound): ?Email
    {
        $now = CarbonImmutable::now();
        // The Date header is of the sender: never later than the moment it is read.
        $at = ($email->date !== null && $email->date->lte($now) ? $email->date : $now)->setTimezone((string) config('app.timezone', 'UTC'));

        try {
            return Crm::db()->transaction(function () use ($email, $messageId, $workspace, $dealId, $personId, $outbound, $at): Email {
                $inReplyTo = MessageId::normalize($email->inReplyTo);

                $stored = (new Email([
                    'deal_id' => $dealId,
                    'person_id' => $personId,
                    'direction' => $outbound ? EmailDirection::Outbound : EmailDirection::Inbound,
                    'status' => $outbound ? EmailStatus::Sent : EmailStatus::Received,
                    'from_email' => Str::limit(Str::lower((string) $email->fromEmail), 191, ''),
                    'from_name' => $email->fromName === null ? null : Str::limit($email->fromName, 191, ''),
                    'to' => $email->to,
                    'cc' => $email->cc === [] ? null : $email->cc,
                    'subject' => Str::limit(Str::squish((string) $email->subject) ?: (string) __('sales-crm::ui.no_subject'), 252, '…'),
                    'body_text' => $this->body($email),
                    'reply_to' => $email->replyTo === null ? null : Str::limit(Str::lower(trim($email->replyTo)), 191, ''),
                    'message_id' => $messageId,
                    'in_reply_to' => $inReplyTo !== null && strlen($inReplyTo) <= self::MAX_ID ? $inReplyTo : null,
                    'references' => $email->references === [] ? null : implode(' ', array_slice($email->references, -20)),
                    'sent_by' => $outbound ? $this->userKeyOf($email->fromEmail) : null,
                    'sent_at' => $at,
                ]))->setWorkspace($workspace);
                $stored->save();

                if ($dealId !== null) {
                    $this->state->refresh($dealId, $at, $at);
                }

                EmailReceived::dispatch($stored);

                return $stored;
            });
        } catch (UniqueConstraintViolationException) {
            // Read at the same time by another worker.
            return null;
        }
    }

    private function body(InboundEmail $email): string
    {
        $text = filled($email->text) ? (string) $email->text : self::htmlToText((string) $email->html);

        return Str::limit(trim(str_replace(["\r\n", "\r"], "\n", $text)), self::MAX_TEXT, '…');
    }

    public static function htmlToText(string $html): string
    {
        $html = (string) preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', '', $html);
        $html = (string) preg_replace('/<br\s*\/?>|<\/(p|div|li|tr|h[1-6])>/i', "\n", $html);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /**
     * The deal and person of the thread the email answers, in each workspace that has the thread.
     *
     * @return list<array{0: Workspace|null, 1: int|null, 2: int|null}>
     */
    private function threads(InboundEmail $email, Workspace|false|null $workspace): array
    {
        $ids = array_values(array_filter(array_map(MessageId::normalize(...), [$email->inReplyTo, ...array_reverse($email->references)])));

        if ($ids === []) {
            return [];
        }

        // In-Reply-To first, then the references from the newest: the email it answers wins.
        return array_values(Email::query()
            ->when($workspace !== false, fn (Builder $query) => $query->inWorkspace($workspace === false ? null : $workspace))
            ->whereIn('message_id', $ids)->latest('id')->get()
            ->sortBy(fn (Email $parent): int => (int) array_search($parent->message_id, $ids, true))
            ->unique('workspace_key')
            ->map(fn (Email $parent): array => [$parent->workspaceRef(), $parent->deal_id, $parent->person_id])
            ->all());
    }

    /**
     * The people of the CRM who wrote the email (or got it, for a user's own email), one per
     * workspace, each with their most recent open deal.
     *
     * @return list<array{0: Workspace|null, 1: int|null, 2: int}>
     */
    private function people(InboundEmail $email, Workspace|false|null $workspace, bool $outbound): array
    {
        $addresses = array_values(array_unique(array_filter(array_map(
            fn (?string $address): string => Str::lower(trim((string) $address)),
            $outbound ? [...$email->to, ...$email->cc] : [$email->fromEmail],
        ))));

        if ($addresses === []) {
            return [];
        }

        $targets = [];
        $people = Person::query()
            ->when($workspace !== false, fn (Builder $query) => $query->inWorkspace($workspace === false ? null : $workspace))
            ->whereIn('email', $addresses)->orderBy('id')->get()
            ->unique(fn (Person $person): string => $person->workspaceRef()?->key() ?? '');

        foreach ($people as $person) {
            $deal = Deal::query()
                ->open()
                ->whereIn('id', Crm::db()->table(Crm::table('deal_person'))->select('deal_id')->where('person_id', $person->id))
                ->latest('updated_at')
                ->latest('id')
                ->value('id');

            $targets[] = [$person->workspaceRef(), $deal === null ? null : (int) $deal, $person->id];
        }

        return $targets;
    }

    private function senderIsUser(InboundEmail $email): bool
    {
        return $this->userKeyOf($email->fromEmail) !== null;
    }

    private function userKeyOf(?string $address): int|string|null
    {
        if ($address === null || $address === '') {
            return null;
        }

        $model = Crm::userModel();
        $query = $model::query();
        // Quoted by the connection of the users, which may not be the one of the CRM.
        $column = $query->getModel()->getConnection()->getQueryGrammar()->wrap(Crm::emailColumn());
        /** @var Model|null $user */
        $user = $query->where(Crm::raw('LOWER('.$column.')'), Str::lower($address))->first();

        return Crm::userKey($user);
    }
}
