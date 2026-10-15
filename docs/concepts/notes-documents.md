---
title: "Notes & documents"
description: "Write notes with @mentions and pins on deals, companies and people; upload quotes, contracts and evidence on private storage with versioning and signed links."
---

# Notes & documents

**Notes** are short texts written by your team on a deal, a company or a
person ("Budget confirmed, decision next week"). **Documents** are files
attached to a deal or a company: quotes, contracts, proof of signature or
payment.

## Notes

```php
use Sellinnate\SalesCrm\Facades\SalesCrm;

$note = SalesCrm::addNote($deal, 'Call went well. @Mario Rossi please prepare the contract.', $user);

// Pinned: always shown on top of the timeline.
SalesCrm::addNote($deal, 'Never call before 10am.', $user, pinned: true);
```

- A note can't be empty and is limited to **5,000 characters**.
- A note on a deal counts as activity: it refreshes the deal's last activity
  (it won't look stale).
- `NoteAdded` fires after the note is saved.

### Mentions

Writing `@` followed by the **full name** of a user who works in the workspace
mentions them. The name is read from `sales-crm.users.name_attribute`; the
longest names are matched first, so `@Jane Doering` is not taken for
`@Jane Doe`. The author never mentions themselves.

For each mentioned user, `UserMentioned` fires and, by default, they receive a
`MentionedNotification` with a link to the deal, company or person. **The text
of the note is never put in the notification**, only the author and the
subject.

### Editing, pinning, deleting

These operations are on the action class used by the UI:

```php
use Sellinnate\SalesCrm\Actions\ManageNotes;

$notes = app(ManageNotes::class);

$notes->update($note, 'Updated text, now with @Laura Bianchi', $user); // author only
$notes->pin($note, true, $user);                                       // anyone in the workspace
$notes->delete($note, $user);                                          // author or a manager
```

When a note is edited, only the **newly** mentioned users are notified.

## Documents

```php
$document = SalesCrm::uploadDocument($deal, $request->file('quote'), [
    'kind'          => 'quote',        // required
    'document_date' => '2026-10-15',   // optional, Y-m-d
    'valid_until'   => '2026-11-15',   // optional, quotes only
], $user);
```

| Key | Values |
|---|---|
| `kind` | **required**: `quote`, `contract`, `signature_evidence`, `payment_evidence`, `other` |
| `document_date` | `Y-m-d` |
| `valid_until` | `Y-m-d`, kept for quotes only |

Documents can be uploaded on **deals** and **companies**. The upload is checked
against `sales-crm.documents.max_kilobytes` (20 MB by default) and
`sales-crm.documents.mimes`.

### What each kind does

- **Quote**: numbered per deal (`version` 1, 2, 3…). A quote with
  `valid_until` sets the deal's **offer validity** (`offer_valid_until`). The
  latest quote is what the `{{quote_link}}` email variable links to. A quote
  satisfies the `offer_document` [required field](/concepts/pipelines#required-fields).
- **Contract** and **signature evidence**: count as evidence for
  `signature_status = signed`.
- **Payment evidence**: counts as evidence for `payment_status = partial/paid`.

See [Signature and payment need evidence](/concepts/deals#evidence).

### Storage and download

Files are stored on `sales-crm.documents.disk` (default `local`, which is
private) under `sales-crm/<workspace>/<uuid>.<ext>`: the original file name is
kept in the database, never used as a path.

Users download them through the CRM route `GET /crm/documents/{document}`
(name `sales-crm.documents.download`), which checks the `DocumentPolicy`: the
user must work in the workspace and see the deal. Responses carry
`X-Content-Type-Options: nosniff`.

```php
route('sales-crm.documents.download', $document);
```

### Signed quote links

An email written from a deal can contain `{{quote_link}}`: it becomes a
temporary **signed URL** to the latest quote of the deal, valid for
`sales-crm.documents.signed_link_days` (30 by default) and rate limited. The
customer can download that one quote without logging in; any other document
kind is refused.

### Deleting documents

```php
use Sellinnate\SalesCrm\Actions\ManageDocuments;

app(ManageDocuments::class)->delete($document, $user);
```

Allowed to the uploader or a manager. The **last** evidence of a signed or paid
deal can't be deleted until another evidence exists or the status changes.
Stored files are removed after the deletion commits; when a deal or company is
deleted, its files go too.

## On the timeline

Notes (pinned first) and documents appear on the timeline of their deal or
company, with the author and date. See [Timeline](/concepts/timeline).

## Events

| Event | When |
|---|---|
| `NoteAdded` | A note was written. |
| `UserMentioned` | A user was mentioned in a note. |
| `DocumentUploaded` | A document was uploaded. |

## Best practices

- **Use a private disk** (local or a private S3 bucket), never the `public`
  disk: documents must only be downloaded through the policy check.
- **Upload the quote with its validity**: the deal's offer date follows, and
  the board flags expiring offers.
- **Mention colleagues** instead of emailing them about a deal: the context
  stays on the timeline.

## Common pitfalls

- Omitting `kind` when uploading: it is required.
- Uploading a file type not in `sales-crm.documents.mimes`: validation error.
  Extend the list in the config if needed.
- Mentions that don't trigger: the name must match the user's full name exactly
  as stored in `name_attribute`, and the user must work in the workspace.
