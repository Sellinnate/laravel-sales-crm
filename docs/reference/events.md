---
title: "Events"
description: "Every event of the CRM with its public properties and when it fires. All are dispatched after the database commit."
---

# Events

All 22 events live in the `Sellinnate\SalesCrm\Events` namespace, use
`Dispatchable` and `SerializesModels`, and implement
`ShouldDispatchAfterCommit`: they are delivered **after the database
transaction commits**. Properties are public.

`$by` is the user who acted (`Illuminate\Contracts\Auth\Authenticatable`), or
`null` when the system acted (stage automation, import job, reminders command,
`createDealFrom()` without a user).

See [Events & notifications](/concepts/events) for how to listen.

## Deals

### DealCreated

A deal was created: by hand, by an integration (`createDealFrom()`), by an
import, through the API or by an AI agent.

| Property | Type |
|---|---|
| `deal` | `Models\Deal` |
| `by` | `?Authenticatable` |

### DealUpdated

Fields of a deal changed (`updateDeal()`, the panel, the list's inline edit,
bulk owner/priority changes, the API, MCP, a quote setting the offer validity).
Not fired by moves.

| Property | Type |
|---|---|
| `deal` | `Models\Deal` |
| `changes` | `list<string>`: the **names** of the changed attributes (never their values), for example `['amount_once_cents', 'next_step']` |
| `by` | `?Authenticatable` |

### DealStageChanged

A deal moved to another stage of its pipeline.

| Property | Type |
|---|---|
| `deal` | `Models\Deal` |
| `from` | `Models\Stage` |
| `to` | `Models\Stage` |
| `by` | `?Authenticatable` |

### DealWon

A deal entered a won stage (fired after `DealStageChanged`).

| Property | Type |
|---|---|
| `deal` | `Models\Deal` |
| `by` | `?Authenticatable` |

### DealLost

A deal entered a lost stage; `$deal->lost_reason` and `lost_note` are set.

| Property | Type |
|---|---|
| `deal` | `Models\Deal` |
| `by` | `?Authenticatable` |

### DealReopened

A won or lost deal went back to an open stage.

| Property | Type |
|---|---|
| `deal` | `Models\Deal` |
| `by` | `?Authenticatable` |

### DealDeleted

A deal was deleted (with its activities, notes, emails, documents and history).

| Property | Type |
|---|---|
| `dealId` | `int` |
| `attributes` | `array<string, mixed>`: the deal's attributes as they were |
| `by` | `?Authenticatable` |

## Approval

### ApprovalRequested

The approval of a deal was asked to its approver (on creation or update, when
`approval_required` is on, an approver other than the author is set and the
status is pending). Notifies the approver by default.

| Property | Type |
|---|---|
| `deal` | `Models\Deal` |
| `by` | `?Authenticatable` |

### ApprovalDecided

The approver (or a manager) approved or rejected a deal.

| Property | Type |
|---|---|
| `deal` | `Models\Deal` (`approval_status` is `approved` or `rejected`) |
| `by` | `?Authenticatable` |

## Contacts

### CompanyCreated

A company was created: from the Companies page (the `SaveCompany` action) or
by the matcher (`SalesCrm::for(...)->company()`, deal creation, import). A
match with an existing company doesn't fire it.

| Property | Type |
|---|---|
| `company` | `Models\Company` |
| `by` | `?Authenticatable` |

### PersonCreated

A person was created: from the People page (the `SavePerson` action) or by the
matcher (`SalesCrm::for(...)->person()`, deal creation, import).
A match with an existing person doesn't fire it.

| Property | Type |
|---|---|
| `person` | `Models\Person` |
| `by` | `?Authenticatable` |

## Activities

### ActivityCreated

An activity was planned. `$by` is `null` when a stage automation created it.

| Property | Type |
|---|---|
| `activity` | `Models\Activity` |
| `by` | `?Authenticatable` |

### ActivityCompleted

An activity was marked as done, with its outcome (`$activity->outcome`).

| Property | Type |
|---|---|
| `activity` | `Models\Activity` |
| `by` | `?Authenticatable` |

### ActivityDue

The reminder of an activity is due. Fired by `sales-crm:remind-activities`.
Notifies the assignee by default.

| Property | Type |
|---|---|
| `activity` | `Models\Activity` |

## Notes and documents

### NoteAdded

A note was written on a deal, a company or a person.

| Property | Type |
|---|---|
| `note` | `Models\Note` |
| `by` | `?Authenticatable` |

### UserMentioned

A user was `@mentioned` in a note (on creation, or newly mentioned on edit).
Notes written by imports mention nobody. Notifies the user by default.

| Property | Type |
|---|---|
| `note` | `Models\Note` |
| `user` | `Illuminate\Database\Eloquent\Model`: the mentioned user |
| `by` | `?Authenticatable` |

### DocumentUploaded

A document was uploaded on a deal or a company.

| Property | Type |
|---|---|
| `document` | `Models\Document` |
| `by` | `?Authenticatable` |

## Email

### EmailQueued

An email was written from a deal and queued for delivery.

| Property | Type |
|---|---|
| `email` | `Models\Email` |
| `by` | `?Authenticatable` |

### EmailSent

An email of a deal left the mailer (status `sent`).

| Property | Type |
|---|---|
| `email` | `Models\Email` |

### EmailFailed

An email could not be delivered after the retries (status `failed`, a safe
message in `$email->error`).

| Property | Type |
|---|---|
| `email` | `Models\Email` |

### EmailReceived

An inbound email was matched to a deal or a person and stored (also for emails
recorded through the BCC dropbox).

| Property | Type |
|---|---|
| `email` | `Models\Email` |

## Import

### ImportFinished

An import of deals finished, `done` or `failed`. Counts, rejected rows and
warnings are on the import.

| Property | Type |
|---|---|
| `import` | `Models\Import` |

## Summary

| Event | Properties |
|---|---|
| `ActivityCompleted` | `activity`, `by` |
| `ActivityCreated` | `activity`, `by` |
| `ActivityDue` | `activity` |
| `ApprovalDecided` | `deal`, `by` |
| `ApprovalRequested` | `deal`, `by` |
| `CompanyCreated` | `company`, `by` |
| `DealCreated` | `deal`, `by` |
| `DealDeleted` | `dealId`, `attributes`, `by` |
| `DealLost` | `deal`, `by` |
| `DealReopened` | `deal`, `by` |
| `DealStageChanged` | `deal`, `from`, `to`, `by` |
| `DealUpdated` | `deal`, `changes`, `by` |
| `DealWon` | `deal`, `by` |
| `DocumentUploaded` | `document`, `by` |
| `EmailFailed` | `email` |
| `EmailQueued` | `email`, `by` |
| `EmailReceived` | `email` |
| `EmailSent` | `email` |
| `ImportFinished` | `import` |
| `NoteAdded` | `note`, `by` |
| `PersonCreated` | `person`, `by` |
| `UserMentioned` | `note`, `user`, `by` |
