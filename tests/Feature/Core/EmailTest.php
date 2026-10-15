<?php

use DirectoryTree\ImapEngine\Collections\MessageCollection;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\FolderRepositoryInterface;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;
use DirectoryTree\ImapEngine\Testing\FakeMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Actions\SaveEmailTemplate;
use Sellinnate\SalesCrm\Contracts\InboundDriver;
use Sellinnate\SalesCrm\Data\InboundEmail;
use Sellinnate\SalesCrm\Enums\EmailStatus;
use Sellinnate\SalesCrm\Events\EmailFailed;
use Sellinnate\SalesCrm\Events\EmailReceived;
use Sellinnate\SalesCrm\Events\EmailSent;
use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Inbound\Drivers\ImapDriver;
use Sellinnate\SalesCrm\Jobs\DeliverEmail;
use Sellinnate\SalesCrm\Mail\DealEmail;
use Sellinnate\SalesCrm\Models\Email;

beforeEach(function () {
    $this->seller = crmUser(['name' => 'Sam Seller', 'email' => 'sam@vendor.example']);
    $this->admin = crmUser(['is_admin' => true]);
    $this->deal = SalesCrm::shared()->createDeal(['title' => 'Website', 'company_name' => 'Acme', 'amount_once' => 1500, 'person' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@acme.example']], $this->seller);
});

it('sends an email from a deal with the variables of the template written in', function () {
    Mail::fake();
    Event::fake([EmailSent::class]);
    app(SaveEmailTemplate::class)->handle(null, ['name' => 'Follow-up', 'subject' => 'About {{deal}}', 'body' => 'Hi {{first_name}}, the offer for {{company}} is {{amount}}. {{unknown}} — {{sender}}'], $this->admin);

    $email = SalesCrm::sendEmail($this->deal, ['to' => 'jane@acme.example', 'cc' => 'boss@acme.example; ', 'subject' => 'About {{deal}}', 'body' => 'Hi {{first_name}}, the offer for {{company}} is {{amount}}. {{unknown}} — {{sender}}'], $this->seller);
    $email = $email->fresh();

    expect($email->status)->toBe(EmailStatus::Sent)
        ->and($email->subject)->toBe('About Website')
        ->and($email->body_text)->toBe('Hi Jane, the offer for Acme is €1,500. {{unknown}} — Sam Seller')
        ->and($email->from_email)->toBe('crm@example.test')
        ->and($email->from_name)->toBe('Sam Seller')
        ->and($email->reply_to)->toBe('sam@vendor.example')
        ->and($email->person_id)->toBe($this->deal->people->first()->id)
        ->and($email->cc)->toBe(['boss@acme.example'])
        ->and($this->deal->fresh()->last_contacted_at)->not->toBeNull();

    Mail::assertSent(DealEmail::class, fn (DealEmail $mail): bool => $mail->hasTo('jane@acme.example') && $mail->hasCc('boss@acme.example') && $mail->hasReplyTo('sam@vendor.example'));
    Event::assertDispatched(EmailSent::class);
});

it('threads a reply with In-Reply-To and References', function () {
    Mail::fake();
    $first = SalesCrm::sendEmail($this->deal, ['to' => 'jane@acme.example', 'subject' => 'Hello', 'body' => 'First'], $this->seller);
    $reply = SalesCrm::sendEmail($this->deal, ['to' => 'jane@acme.example', 'subject' => 'Re: Hello', 'body' => 'Second', 'reply_to_id' => $first->id], $this->seller);

    expect($reply->in_reply_to)->toBe($first->message_id)
        ->and($reply->references)->toBe($first->message_id);

    $mailable = new DealEmail($reply->fresh());
    expect($mailable->headers()->messageId)->toBe($reply->message_id)
        ->and($mailable->headers()->text['In-Reply-To'])->toBe('<'.$first->message_id.'>');
});

it('refuses mass mailing, invalid addresses and replies to other deals', function () {
    Mail::fake();
    $many = implode(',', array_map(fn (int $i): string => "user{$i}@acme.example", range(1, 11)));
    $other = SalesCrm::shared()->createDeal(['title' => 'Other'], $this->seller);
    $foreign = SalesCrm::sendEmail($other, ['to' => 'x@acme.example', 'subject' => 'S', 'body' => 'B'], $this->seller);

    expect(fn () => SalesCrm::sendEmail($this->deal, ['to' => $many, 'subject' => 'S', 'body' => 'B'], $this->seller))->toThrow(ValidationException::class)
        ->and(fn () => SalesCrm::sendEmail($this->deal, ['to' => 'not-an-address', 'subject' => 'S', 'body' => 'B'], $this->seller))->toThrow(ValidationException::class)
        ->and(fn () => SalesCrm::sendEmail($this->deal, ['to' => 'jane@acme.example', 'subject' => 'S', 'body' => 'B', 'reply_to_id' => $foreign->id], $this->seller))->toThrow(ValidationException::class);

    config(['sales-crm.email.enabled' => false]);
    expect(fn () => SalesCrm::sendEmail($this->deal, ['to' => 'jane@acme.example', 'subject' => 'S', 'body' => 'B'], $this->seller))->toThrow(ValidationException::class);
});

it('marks an email not sent when the delivery fails for good, without quoting the exception', function () {
    Event::fake([EmailFailed::class]);
    config(['queue.default' => 'database']);
    $email = SalesCrm::sendEmail($this->deal, ['to' => 'jane@acme.example', 'subject' => 'S', 'body' => 'B'], $this->seller);

    (new DeliverEmail($email->id))->failed(new RuntimeException('SMTP host smtp.internal refused'));

    expect($email->fresh()->status)->toBe(EmailStatus::Failed)
        ->and($email->fresh()->error)->not->toContain('smtp.internal');
    Event::assertDispatched(EmailFailed::class);
});

it('stores an answer in the thread of an email of the deal', function () {
    Mail::fake();
    Event::fake([EmailReceived::class]);
    $sent = SalesCrm::sendEmail($this->deal, ['to' => 'jane@acme.example', 'subject' => 'Offer', 'body' => 'Here it is'], $this->seller);

    $stored = SalesCrm::inbound()->receive(InboundEmail::fromArray([
        'message_id' => '<reply-1@acme.example>',
        'from' => 'Jane Doe <Jane@Acme.example>',
        'to' => ['crm@example.test'],
        'subject' => 'Re: Offer',
        'text' => "Looks good!\n\n> Here it is",
        'in_reply_to' => '<'.$sent->message_id.'>',
        'date' => now()->subMinute()->toIso8601String(),
    ]));

    expect($stored?->deal_id)->toBe($this->deal->id)
        ->and($stored?->status)->toBe(EmailStatus::Received)
        ->and($stored?->from_email)->toBe('jane@acme.example')
        ->and($stored?->from_name)->toBe('Jane Doe');
    Event::assertDispatched(EmailReceived::class);

    // The same email read twice is stored once.
    expect(SalesCrm::inbound()->receive(InboundEmail::fromArray(['message_id' => '<reply-1@acme.example>', 'from' => 'jane@acme.example', 'in_reply_to' => $sent->message_id])))->toBeNull()
        ->and(Email::query()->where('message_id', 'reply-1@acme.example')->count())->toBe(1);
});

it('stores an email from a person on their most recent open deal', function () {
    $stored = SalesCrm::inbound()->receive(InboundEmail::fromArray(['message_id' => 'new-1@acme.example', 'from' => 'jane@acme.example', 'subject' => 'A question', 'html' => '<p>Can we <b>talk</b>?</p>']));

    expect($stored?->deal_id)->toBe($this->deal->id)
        ->and($stored?->body_text)->toBe('Can we talk?');
});

it('ignores unknown senders, auto-replies and bounces', function () {
    expect(SalesCrm::inbound()->receive(InboundEmail::fromArray(['message_id' => 'a@x.example', 'from' => 'stranger@x.example'])))->toBeNull()
        ->and(SalesCrm::inbound()->receive(InboundEmail::fromArray(['message_id' => 'b@x.example', 'from' => 'jane@acme.example', 'headers' => ['Auto-Submitted' => 'auto-replied']])))->toBeNull()
        ->and(SalesCrm::inbound()->receive(InboundEmail::fromArray(['message_id' => 'c@x.example', 'from' => 'jane@acme.example', 'subject' => 'Out of office until Monday'])))->toBeNull()
        ->and(SalesCrm::inbound()->receive(InboundEmail::fromArray(['message_id' => 'd@x.example', 'from' => 'MAILER-DAEMON@acme.example'])))->toBeNull()
        ->and(Email::query()->count())->toBe(0);
});

it('stores as outbound the emails users write from their own client to people of the CRM', function () {
    $stored = SalesCrm::inbound()->receive(InboundEmail::fromArray(['message_id' => 'bcc-1@vendor.example', 'from' => 'sam@vendor.example', 'to' => ['jane@acme.example'], 'subject' => 'Sent from Outlook', 'text' => 'Hi Jane']));

    expect($stored?->direction->value)->toBe('out')
        ->and($stored?->sent_by)->toBe($this->seller->id)
        ->and($stored?->deal_id)->toBe($this->deal->id);
});

it('drops an email from a person found in more workspaces', function () {
    useTeams();
    $alpha = crmTeam('Alpha', $this->seller);
    $beta = crmTeam('Beta', $this->seller);
    SalesCrm::for($alpha)->createDeal(['title' => 'A', 'person' => ['first_name' => 'Max', 'email' => 'max@shared.example']], $this->seller);
    SalesCrm::for($beta)->createDeal(['title' => 'B', 'person' => ['first_name' => 'Max', 'email' => 'max@shared.example']], $this->seller);

    expect(SalesCrm::inbound()->receive(InboundEmail::fromArray(['message_id' => 'max-1@shared.example', 'from' => 'max@shared.example'])))->toBeNull()
        ->and(SalesCrm::inbound()->receive(InboundEmail::fromArray(['message_id' => 'max-2@shared.example', 'from' => 'max@shared.example']), $alpha)?->deal?->title)->toBe('A');
});

it('receives emails from the signed webhook only', function () {
    $payload = json_encode(['emails' => [['message_id' => 'w-1@acme.example', 'from' => 'jane@acme.example', 'subject' => 'Via webhook', 'text' => 'Hello']]]);

    $this->call('POST', '/sales-crm/inbound', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)->assertNotFound();

    config(['sales-crm.email.inbound.webhook_secret' => 'top-secret']);
    $this->call('POST', '/sales-crm/inbound', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SALES_CRM_SIGNATURE' => 'sha256=wrong'], $payload)->assertUnauthorized();
    $this->call('POST', '/sales-crm/inbound', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SALES_CRM_SIGNATURE' => 'sha256='.hash_hmac('sha256', $payload, 'top-secret')], $payload)
        ->assertStatus(202)
        ->assertJson(['received' => 1, 'stored' => 1]);

    expect(Email::query()->where('message_id', 'w-1@acme.example')->value('deal_id'))->toBe($this->deal->id);
});

it('polls the inbound driver and maps IMAP messages', function () {
    $message = new FakeMessage(7, [], implode("\r\n", [
        'Message-ID: <imap-1@acme.example>',
        'From: Jane Doe <jane@acme.example>',
        'To: crm@example.test',
        'Subject: From IMAP',
        'Date: '.now()->subMinutes(5)->toRfc2822String(),
        'Content-Type: text/plain; charset=utf-8',
        '',
        'Hello from the mailbox',
    ]));

    $inbound = ImapDriver::toInbound($message);

    expect($inbound->messageId)->toBe('imap-1@acme.example')
        ->and($inbound->fromEmail)->toBe('jane@acme.example')
        ->and($inbound->subject)->toBe('From IMAP')
        ->and(trim((string) $inbound->text))->toBe('Hello from the mailbox');

    SalesCrm::inbound()->drivers()->extend('memory', fn () => new class([$inbound]) implements InboundDriver
    {
        public function __construct(private array $emails) {}

        public function fetch(): iterable
        {
            return $this->emails;
        }
    });

    $this->artisan('sales-crm:poll-inbox', ['--driver' => 'memory'])->expectsOutputToContain('1 email(s) read, 1 stored')->assertSuccessful();
    expect(Email::query()->where('message_id', 'imap-1@acme.example')->value('deal_id'))->toBe($this->deal->id);
});

it('refuses a webhook batch larger than the limit instead of cutting it', function () {
    config(['sales-crm.email.inbound.webhook_secret' => 'top-secret']);
    $emails = array_map(fn (int $i): array => ['message_id' => 'b-'.$i.'@acme.example', 'from' => 'jane@acme.example', 'subject' => 'Batch '.$i, 'text' => 'Hello'], range(1, 101));
    $payload = json_encode(['emails' => $emails]);

    $this->call('POST', '/sales-crm/inbound', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SALES_CRM_SIGNATURE' => hash_hmac('sha256', $payload, 'top-secret')], $payload)
        ->assertStatus(413)
        ->assertJson(['max' => 100]);

    expect(Email::query()->where('message_id', 'like', 'b-%')->count())->toBe(0);
});

it('reads the IMAP folders oldest first, from the message after the cursor', function () {
    $message = new FakeMessage(42, [], "Message-ID: <imap-42@acme.example>\r\nFrom: jane@acme.example\r\nSubject: Hi\r\n\r\nHello");
    $calls = [];
    $query = Mockery::mock(MessageQueryInterface::class);

    foreach (['withHeaders', 'withBody', 'leaveUnread', 'setFetchOrderAsc', 'uid', 'since', 'limit'] as $method) {
        $query->shouldReceive($method)->andReturnUsing(function (...$arguments) use ($method, &$calls, $query) {
            $calls[] = $method;

            return $query;
        });
    }

    $query->shouldReceive('get')->andReturn(new MessageCollection([$message]));
    $folder = Mockery::mock(FolderInterface::class);
    $folder->shouldReceive('messages')->andReturn($query);
    $folders = Mockery::mock(FolderRepositoryInterface::class);
    $folders->shouldReceive('find')->with('INBOX')->andReturn($folder);
    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->shouldReceive('folders')->andReturn($folders);
    $mailbox->shouldReceive('config')->andReturn('imap.example.test');

    $cache = app('cache')->store();
    $cache->forever('sales-crm:imap:'.md5('imap.example.test|imap.example.test|INBOX'), 41);

    // Polling stopped at the email (it could not be stored): the cursor stays before it.
    foreach ((new ImapDriver($mailbox, $cache, ['INBOX'], [], 10))->fetch() as $email) {
        break;
    }
    expect($cache->get('sales-crm:imap:'.md5('imap.example.test|imap.example.test|INBOX')))->toBe(41);

    $calls = [];
    $emails = iterator_to_array((new ImapDriver($mailbox, $cache, ['INBOX'], [], 10))->fetch(), false);

    expect($emails)->toHaveCount(1)
        ->and($calls)->toContain('setFetchOrderAsc')
        ->and(array_search('setFetchOrderAsc', $calls, true))->toBeLessThan(array_search('limit', $calls, true))
        ->and($calls)->toContain('uid')->not->toContain('since')
        ->and($cache->get('sales-crm:imap:'.md5('imap.example.test|imap.example.test|INBOX')))->toBe(42);
});

it('asks the sender to retry a webhook batch when an email could not be stored', function () {
    config(['sales-crm.email.inbound.webhook_secret' => 'top-secret']);
    $payload = json_encode(['emails' => [
        ['message_id' => 'r-1@acme.example', 'from' => 'jane@acme.example', 'subject' => 'First', 'text' => 'Hello'],
        ['message_id' => 'r-2@acme.example', 'from' => 'jane@acme.example', 'subject' => 'Second', 'text' => 'Hello'],
    ]]);
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SALES_CRM_SIGNATURE' => hash_hmac('sha256', $payload, 'top-secret')];
    Event::listen(EmailReceived::class, function (EmailReceived $event): void {
        if ($event->email->message_id === 'r-2@acme.example' && ! app()->bound('crm-test-retried')) {
            throw new RuntimeException('Deadlock');
        }
    });

    $this->call('POST', '/sales-crm/inbound', [], [], [], $headers, $payload)->assertStatus(503)->assertJson(['received' => 2, 'failed' => 1]);

    app()->instance('crm-test-retried', true);
    $this->call('POST', '/sales-crm/inbound', [], [], [], $headers, $payload)->assertStatus(202)->assertJson(['failed' => 0]);

    expect(Email::query()->where('message_id', 'r-1@acme.example')->count())->toBe(1)
        ->and(Email::query()->where('message_id', 'r-2@acme.example')->count())->toBe(1);
});

it('stores as outbound the reply a user writes from their own client in a thread of the deal', function () {
    Mail::fake();
    $sent = SalesCrm::sendEmail($this->deal, ['to' => 'jane@acme.example', 'subject' => 'Offer', 'body' => 'Here it is'], $this->seller);

    $stored = SalesCrm::inbound()->receive(InboundEmail::fromArray([
        'message_id' => '<follow-up-1@vendor.example>',
        'from' => 'sam@vendor.example',
        'to' => ['jane@acme.example'],
        'subject' => 'Re: Offer',
        'text' => 'Any news?',
        'in_reply_to' => '<'.$sent->message_id.'>',
    ]));

    expect($stored?->deal_id)->toBe($this->deal->id)
        ->and($stored?->direction->value)->toBe('out')
        ->and($stored?->status)->toBe(EmailStatus::Sent)
        ->and($stored?->sent_by)->toBe($this->seller->id);
});

it('stops polling at an email it cannot store, which the driver returns again at the next poll', function () {
    $emails = [
        InboundEmail::fromArray(['message_id' => 'p-1@acme.example', 'from' => 'jane@acme.example', 'subject' => 'One', 'text' => 'Hello']),
        InboundEmail::fromArray(['message_id' => 'p-2@acme.example', 'from' => 'jane@acme.example', 'subject' => 'Two', 'text' => 'Hello']),
    ];
    $driver = new class($emails) implements InboundDriver
    {
        public int $cursor = 0;

        public function __construct(private array $emails) {}

        public function fetch(): iterable
        {
            foreach (array_slice($this->emails, $this->cursor) as $email) {
                yield $email;
                $this->cursor++;
            }
        }
    };
    SalesCrm::inbound()->drivers()->extend('flaky', fn () => $driver);
    $fail = true;
    Event::listen(EmailReceived::class, function (EmailReceived $event) use (&$fail): void {
        if ($fail && $event->email->message_id === 'p-1@acme.example') {
            throw new RuntimeException('Deadlock');
        }
    });

    $this->artisan('sales-crm:poll-inbox', ['--driver' => 'flaky'])->assertFailed();
    expect($driver->cursor)->toBe(0)->and(Email::query()->where('message_id', 'p-2@acme.example')->exists())->toBeFalse();

    $fail = false;
    $this->artisan('sales-crm:poll-inbox', ['--driver' => 'flaky'])->expectsOutputToContain('2 email(s) read')->assertSuccessful();
    expect($driver->cursor)->toBe(2)
        ->and(Email::query()->whereIn('message_id', ['p-1@acme.example', 'p-2@acme.example'])->count())->toBe(2);
});
