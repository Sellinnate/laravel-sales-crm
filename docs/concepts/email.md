---
title: "Email"
description: "Send emails from a deal with templates and variables, thread the replies, and bring inbound emails back to the right deal with IMAP, a signed webhook or your own code."
---

# Email

The CRM can **send** an email from a deal (one at a time, from a person of your
team to the customer) and **receive** the replies, putting both on the deal's
timeline. It is not a mass-mailing tool: there are no campaigns or newsletters.

::: callout info "In plain words"
*Outbound* email is what your team sends from the CRM. *Inbound* email is what
comes back: replies, or emails your team sends from Gmail/Outlook that you want
on the timeline anyway. The CRM recognises which deal an inbound email belongs
to by its **thread headers** (the hidden `In-Reply-To`/`References` headers
every mail client adds to a reply) or by the sender's address.
:::

## Sending an email

```php
use Sellinnate\SalesCrm\Facades\SalesCrm;

$email = SalesCrm::sendEmail($deal, [
    'to'      => ['jane@acme.example'],          // array or "a@x.test, b@y.test"
    'cc'      => 'cfo@acme.example',
    'subject' => 'Your proposal for {{deal}}',
    'body'    => "Hi {{first_name}},\n\nhere is the proposal: {{quote_link}}\n\nBest,\n{{sender}}",
], $user);

$email->status;   // EmailStatus::Queued
```

| Key | Rules |
|---|---|
| `to` | required, at least one valid address |
| `cc` | optional |
| `subject` | required, ≤ 255 characters |
| `body` | required, plain text, ≤ 20,000 characters |
| `reply_to_id` | optional: the id of an email of the same deal this one answers |

- `to` + `cc` may contain at most `sales-crm.email.max_recipients` addresses
  (10 by default). Lists can be arrays or strings separated by commas,
  semicolons or spaces.
- The email is stored as **queued** and delivered by a queued job through
  Laravel's mailer, after the transaction commits. It is retried 3 times (after
  1 and 5 minutes). On success it becomes **sent**, fires `EmailSent` and
  updates the deal's last contact; after the last failure it becomes **failed**
  and fires `EmailFailed`.
- Every email gets its own `Message-ID`. With `reply_to_id` it also carries the
  `In-Reply-To` and `References` of that thread, so it shows up as a reply in
  the customer's mail client.
- `sales-crm.email.enabled = false` refuses to send.

### Template variables

Subject and body can contain variables, written with double curly braces:

| Variable | Replaced with |
|---|---|
| `{{first_name}}`, `{{last_name}}` | The recipient, when the first `to` address is a person of the deal |
| `{{company}}` | The deal's company |
| `{{deal}}` | The deal's title |
| `{{amount}}` | The one-off amount, formatted |
| `{{recurring}}` | The recurring amount with its period |
| `{{offer_valid_until}}` | The offer validity date |
| `{{quote_link}}` | A signed link to the latest quote of the deal |
| `{{sender}}` | The name of the user sending |
| `{{workspace}}` | The name of the workspace |

A variable without a value becomes empty; an unknown variable is left as
written. Managers save reusable **email templates** per workspace in the
Settings page; users pick one in the deal panel.

### Who the email comes from

The default `ConfigSenderResolver` builds the sender from
`sales-crm.email`:

- **From address**: `email.from.address`, else `mail.from.address`. One is
  required.
- **From name**: the sending user's name (`from_name = user`) or
  `email.from.name` / `mail.from.name` (`from_name = config`).
- **Reply-To**: the user's own address (`reply_to = user`), none
  (`reply_to = from`), or a fixed address such as your CRM inbox.
- **Mailer**: `email.mailer`, else the default mailer.

```dotenv
SALES_CRM_MAIL_FROM_ADDRESS=sales@example.test
SALES_CRM_MAIL_FROM_NAME="Example Sales"
SALES_CRM_MAIL_REPLY_TO=crm@example.test
```

To send from a different domain per workspace, or through each user's own
mailbox, bind your own `SenderResolver`: see
[Custom contracts](/extending/contracts#senderresolver).

## Inbound email {#inbound-email}

Inbound emails can reach the CRM in three ways:

| Driver | `SALES_CRM_INBOUND_DRIVER` | How |
|---|---|---|
| None | `null` (default) | Nothing is read. |
| IMAP | `imap` | `sales-crm:poll-inbox` reads a mailbox. Needs `directorytree/imapengine`. |
| Webhook | `webhook` | Your mail provider POSTs JSON to `/sales-crm/inbound`. |
| Your code | any | Call `SalesCrm::inbound()->receive(...)`. |

### Where inbound emails go

Every inbound email is checked in this order:

1. **Automatic messages are dropped**: auto-replies, out-of-office messages and
   bounces (detected from the `Auto-Submitted`, `Precedence`, `X-Autoreply`,
   `X-Autorespond`, `X-Auto-Response-Suppress` headers, senders like
   `mailer-daemon@` and `postmaster@`, and subjects like "Automatic reply",
   "Out of office", "Undeliverable", "Delivery Status Notification").
2. **A reply in a known thread**: if `In-Reply-To` or `References` contains the
   Message-ID of an email already in the CRM, the email goes to **that deal and
   person** (in every workspace that has that thread).
3. **An email from a known person**: otherwise, if the sender's address belongs
   to a person of the CRM, the email goes to **that person and their most
   recently updated open deal** (or to the person only, when they have no open
   deal).
4. **An email a user sent** (the "BCC dropbox"): if the sender is one of your
   users and a recipient is a person of the CRM, the email is stored as
   **outbound**, sent by that user. Your team can put the CRM inbox address in
   Bcc from their own mail client.
5. **Everything else is ignored**: personal mail, newsletters, and senders that
   match a person in **more than one workspace** (it would be impossible to
   tell whose matter it is).

Each email is stored **once per workspace and Message-ID**, so reading the same
message twice is harmless. A stored email fires `EmailReceived` and refreshes
the deal's last contact.

::: callout tip "Make replies come back"
The simplest setup: create a mailbox such as `crm@example.test`, set
`SALES_CRM_MAIL_REPLY_TO=crm@example.test` and read it with the IMAP driver.
Customer replies then go to that mailbox and land on the deal. With
`reply_to = user` replies go to each user's personal mailbox, which the CRM
doesn't read.
:::

### IMAP

```bash
composer require directorytree/imapengine
```

```dotenv
SALES_CRM_INBOUND_DRIVER=imap
SALES_CRM_IMAP_HOST=imap.example.test
SALES_CRM_IMAP_PORT=993
SALES_CRM_IMAP_ENCRYPTION=ssl
SALES_CRM_IMAP_USERNAME=crm@example.test
SALES_CRM_IMAP_PASSWORD=secret
```

```php
// routes/console.php
Schedule::command('sales-crm:poll-inbox')->everyFiveMinutes()->withoutOverlapping();
```

The driver reads `email.inbound.imap.folders` (default `INBOX`) and
`sent_folders` (for emails users sent from that mailbox), at most `batch`
messages per folder per run. Messages are left **unread**. It keeps a UID
cursor per folder in the **cache**; the first run reads the last two days.

::: callout warning "The cursor lives in the cache"
Clearing the cache makes the next run start again from the last two days.
That's safe (emails are de-duplicated by Message-ID) but slower.
:::

### Webhook {#webhook}

Many mail providers can forward incoming email to a URL as JSON. Set a secret:

```dotenv
SALES_CRM_INBOUND_DRIVER=webhook
SALES_CRM_INBOUND_SECRET=a-long-random-string
```

The endpoint is `POST /sales-crm/inbound` (path from
`sales-crm.email.inbound.webhook_path`, route name `sales-crm.inbound`, limited
to 120 requests per minute). **Without a secret it answers 404.** Each request
must carry the header `X-Sales-Crm-Signature` with the hex HMAC-SHA256 of the
raw body, computed with the secret (an optional `sha256=` prefix is accepted);
a wrong signature gets 401.

The body is one email, or `{"emails": [ ... ]}` with up to 100:

```json
{
  "message_id": "<CAB123@mail.acme.example>",
  "from": "Jane Doe <jane@acme.example>",
  "to": ["crm@example.test"],
  "cc": [],
  "subject": "Re: Your proposal for Website redesign",
  "text": "Looks good, let's talk on Monday.",
  "html": "<p>Looks good, let's talk on Monday.</p>",
  "in_reply_to": "<crm.01jabc...@example.test>",
  "references": "<crm.01jabc...@example.test>",
  "date": "2026-10-15T09:12:00+02:00",
  "reply_to": null,
  "headers": { "auto-submitted": "no" }
}
```

| Key | Format |
|---|---|
| `message_id` | string (angle brackets optional) |
| `from` | `"address"`, `"Name <address>"` or `{"email": "...", "name": "..."}` (or `from_name` separately) |
| `to`, `cc` | array of addresses (or of `{"email": ...}`), or a comma-separated string |
| `subject`, `text`, `html` | strings (`html` is converted to text when `text` is empty) |
| `in_reply_to` | string |
| `references` | array or space-separated string |
| `date` | any date string Carbon can parse (never later than the moment it's read) |
| `reply_to` | string |
| `headers` | object of header name → value (used to detect auto-replies) |

The response is `202 Accepted` with `{"received": 1, "stored": 1}`.

A small adapter signing the payload, for example in a test:

```php
$payload = json_encode(['message_id' => '<m1@acme.example>', 'from' => 'jane@acme.example', 'to' => ['crm@example.test'], 'subject' => 'Hello', 'text' => 'Hi!']);

Http::withHeaders([
    'Content-Type'          => 'application/json',
    'X-Sales-Crm-Signature' => hash_hmac('sha256', $payload, config('sales-crm.email.inbound.webhook_secret')),
])->withBody($payload, 'application/json')->post(url('/sales-crm/inbound'));
```

### From your own code

If you already process inbound mail (a mail parser, a provider SDK), push the
emails directly:

```php
use Sellinnate\SalesCrm\Data\InboundEmail;
use Sellinnate\SalesCrm\Facades\SalesCrm;

$stored = SalesCrm::inbound()->receive(InboundEmail::fromArray([
    'message_id'  => $message->id,
    'from'        => ['email' => $message->fromAddress, 'name' => $message->fromName],
    'to'          => $message->to,
    'subject'     => $message->subject,
    'text'        => $message->textBody,
    'in_reply_to' => $message->inReplyTo,
    'references'  => $message->references,
]));

// $stored is the Email model, or null when the email doesn't belong to the CRM.
```

`receive()` takes an optional second argument to restrict matching to one
workspace: `receive($email, $team)`.

### A custom polling driver

For a provider with an API, write a class implementing `InboundDriver` and
register it with the driver manager; see
[Custom contracts](/extending/contracts#inbounddriver).

## Reading emails

```php
$deal->emails()->latest('sent_at')->get();
$person->emails;

$email->direction;   // EmailDirection::Outbound ('out') or Inbound ('in')
$email->status;      // queued, sent, failed, received
$email->body_text;
```

## Events

| Event | When |
|---|---|
| `EmailQueued` | An email was written from a deal and queued. |
| `EmailSent` | It left the mailer. |
| `EmailFailed` | Delivery failed after the retries (`$email->error` has a safe message). |
| `EmailReceived` | An inbound email was matched and stored. |

## Best practices

- **Use a dedicated reply-to mailbox** read by an inbound driver.
- **Use an `email.from.address` on a domain with SPF/DKIM** set up for your
  mailer, and the user's name as From name.
- **Run a queue worker**: emails stay "queued" otherwise.

## Common pitfalls

- No From address configured: sending throws "set `sales-crm.email.from.address`
  (or `mail.from.address`)".
- Webhook returns 404: no `SALES_CRM_INBOUND_SECRET` is set.
- Webhook returns 401: the signature must be computed on the **raw** body
  exactly as sent, not on a re-encoded copy.
- A customer's email doesn't appear: the sender is not a person in the CRM (or
  is a person in two workspaces), and the email isn't a reply to a CRM thread.
