---
title: "Companies & people"
description: "Companies and people in the CRM, how they are de-duplicated by VAT number, domain, name, email and phone, and how to create and query them."
---

# Companies & people

**Companies** are the organisations you sell to; **people** are the humans you
talk to. A person can belong to a company and can be on many deals. Both are
shared by everyone who works in the same [workspace](/concepts/workspaces).

::: callout info "In plain words"
Other CRMs call them *accounts* and *contacts*. The important thing to know
here is that the CRM tries hard **not to create duplicates**: typing "Acme
S.r.l." when "ACME srl" already exists gives you the existing company.
:::

## Find-or-create from code

The two methods of a CRM scope look for a match first and create a new record
only when nothing matches:

```php
use Sellinnate\SalesCrm\Facades\SalesCrm;

$crm = SalesCrm::for($team);

$company = $crm->company([
    'name'       => 'Acme Ltd',
    'vat_number' => 'GB123456789',
    'website'    => 'https://www.acme.example',
    'city'       => 'London',
    'country'    => 'GB',
], by: $user);

$person = $crm->person([
    'first_name' => 'Jane',
    'last_name'  => 'Doe',
    'email'      => 'Jane.Doe@acme.example',
    'mobile'     => '+44 7700 900123',
    'job_title'  => 'Head of Marketing',
], company: $company, by: $user);
```

A match is **completed** with the details it lacks (an empty phone gets the new
phone) but **never overwritten**: existing values win.

### Company keys

`name` (required to create), `legal_name`, `vat_number`, `website`, `email`,
`phone`, `address`, `city`, `region`, `postal_code`, `country` (2-letter ISO
code, stored uppercase), `industry`.

### Person keys

`first_name`, `last_name`, `job_title`, `email`, `mobile`, `phone`,
`phone_source` (where the number came from). Emails are stored lowercase;
phone numbers in E.164 (`+447700900123`).

## De-duplication {#de-duplication}

Matching happens **within the workspace**, under a lock so two requests can't
create the same company at the same time.

**Companies**, in this order:

1. **VAT number**: letters and digits only, uppercase; an Italian `IT` prefix
   is ignored (`IT01234567890` = `01234567890`).
2. **Domain**: the host of the website without `www.`, unless it's a platform
   shared by many companies (`sales-crm.companies.shared_site_hosts`: facebook,
   linkedin, google sites…); otherwise the domain of the company email, unless
   it's a free mail provider (`sales-crm.companies.free_mail_domains`: gmail,
   outlook…).
3. **Normalised name**: lowercase, no accents, no punctuation and without the
   legal form, so *Acme S.r.l.*, *ACME srl* and *Acme* are the same.
   Recognised legal forms include `srl`, `spa`, `ltd`, `limited`, `gmbh`,
   `sarl`, `llc`, `inc`, `corp`, `bv`, `plc`, `ag` and more.

**People**, in this order:

1. **Email** (case-insensitive).
2. **Phone**: the mobile or phone number, normalised to E.164, against both
   numbers of existing people.
3. **First and last name within the same company** (only when a company is
   given).

::: callout tip "Phone numbers"
With `giggsey/libphonenumber-for-php` installed, numbers are validated with the
full libphonenumber rules and national numbers are read in
`sales-crm.phone.default_region`. Without it, numbers are cleaned up, `00`
becomes `+`, and a national number gets `sales-crm.phone.default_calling_code`.
:::

## Where de-duplication applies

The same matcher is used by the quick add of the board, `createDeal()` with
`company_name` or `person`, `createDealFrom()`, `addPerson()`, the import, the
JSON API and the MCP tools.

In the **Companies** and **People** pages of the UI, creating a record that
looks like an existing one shows the possible duplicate instead of creating it;
the user can confirm it is really a different one.

## Reading

```php
$crm->companies()->where('city', 'London')->get();
$crm->people()->whereNotNull('email')->orderBy('last_name')->get();

$company->people;      // people of the company
$company->deals;       // deals of the company
$company->activities;  // activities planned on the company
$company->crmNotes;    // notes on the company
$company->documents;   // documents on the company

$person->name;               // "Jane Doe" (else the email, else the number)
$person->company;
$person->deals;              // with pivot role and is_primary
$person->emails;             // emails to and from the person
$person->callableNumber();   // mobile first, else phone
```

Show users only what they may see with the `visibleTo` scope:

```php
use Sellinnate\SalesCrm\Models\Company;

Company::query()->visibleTo($user)->get();
```

::: callout warning "Notes are `crmNotes`, not `notes`"
`notes` is a text column of companies and people (free notes in the record
itself). The relation to the timeline notes is called `crmNotes()`.
:::

## Notes, activities and documents on contacts

Companies and people have their own timeline. You can write notes and plan
activities on them, and upload documents on companies:

```php
SalesCrm::addNote($company, 'Met at the trade fair.', $user);
SalesCrm::scheduleActivity($person, ['type' => 'email', 'title' => 'Send the brochure', 'due_date' => '2026-11-05'], $user);
SalesCrm::uploadDocument($company, $request->file('nda'), ['kind' => 'contract'], $user);
```

A company's timeline also includes the emails of its deals and people.

## Permissions

- Everyone who works in the workspace can view, create and edit companies and
  people.
- Deleting is for **managers**. A company can be deleted only when it has **no
  deals**; its documents go with it and its people stay (without a company).
- Deleting a person removes the activities planned on them; on deals where they
  were the primary contact, the next person becomes primary.

## Events

`CompanyCreated` and `PersonCreated` fire whenever a company or a person is
created: from the Companies and People pages of the UI, from
`SalesCrm::for(...)->company()`/`person()`, and when a deal creation or an import
creates one through the matcher. Matching an existing record
(and completing its empty fields) doesn't fire them.

## Best practices

- **Pass a VAT number or a website when you have it**: it's the strongest match.
- **Prefer company emails** to free-mail addresses for companies: free-mail
  domains are never used to match.
- **Keep the free-mail and shared-host lists** in line with your market (add
  local providers to `free_mail_domains`).

## Common pitfalls

- Two companies with the same generic name ("Studio Rossi") in one workspace
  are merged by name when no VAT or domain distinguishes them. Pass a VAT
  number or website to keep them apart.
- A person is matched by phone across companies: two people sharing a
  switchboard number will match. Use the mobile for personal numbers.
