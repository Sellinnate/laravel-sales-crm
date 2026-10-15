---
title: "Import & export"
description: "Import deals from CSV or XLSX with column mapping, stage mapping and preview; export the deal list to CSV or XLSX with formula-safe cells."
---

# Import & export

Moving to a new CRM usually starts with a spreadsheet. The **import** reads a
CSV or XLSX file of deals, lets a manager map its columns and stage values,
shows a preview, and creates or updates the deals in the background. The
**export** downloads the deal list, with the current filters, as CSV or XLSX.

::: callout info "CSV vs XLSX"
CSV works out of the box (delimiter, BOM and Windows-1252 encoding are
detected). XLSX needs one extra package:

```bash
composer require openspout/openspout
```
:::

## The import wizard

Open **Import** in the CRM navigation (visible to **managers** only). The
wizard has four steps:

1. **Upload**: choose the pipeline and the file (limit
   `sales-crm.import.max_kilobytes`, 10 MB by default). For an XLSX with several
   sheets, pick the sheet.
2. **Map the columns**: each column of the sheet is assigned to a CRM field.
   The CRM **suggests** a field from the header, in English and Italian
   ("Company", "Azienda", "Deal value", "Importo"…). Unmapped columns are
   ignored.
3. **Map the stages**: the distinct values of the column mapped to *Stage* (up to
   200) are each assigned to a stage of the pipeline, with suggestions by name.
   Choose a **default stage** for rows without a value, and whether dates are
   written **month first** (`10/13/2026`) or day first.
4. **Preview and run**: the first rows (`sales-crm.import.preview_rows`, 10 by
   default) show whether each row will create a deal, update one, or be
   rejected, and why. Then the import runs in the **queue**.

When it finishes, the page shows the counts (created, updated, rejected), the
warnings, and a link to download the **rejected rows** as CSV (the row number,
the reason and the original cells), ready to fix and import again.

## Fields you can map

| Group | Fields |
|---|---|
| Deal | external reference (key), title, stage, sub-status, priority, owner, source, tags, next step, blocker, approval note, missing data, call context, closing tip, expected close date, last contacted, data verified, note |
| Amounts | one-off amount, deposit, recurring and options, payment terms, offer details, offer valid until, commission, signature, payment |
| Company | company name, VAT number, website |
| Person | full name, email, mobile, phone, phone source, job title |
| Custom | every [custom field](/concepts/deals#custom-fields) of the workspace |

Several columns can be mapped to the same **text** field (next step, notes,
blocker…): their contents are joined, one per line.

## How rows are read

The importer reads cautiously: **a value it can't read for sure is never made
up**. It keeps the original text in a note on the deal instead, and adds a
warning.

- **Amounts**: `1500`, `1.500`, `1,500`, `1.500,50`, `€ 1500`, `$2,000`,
  `1500 EUR` are understood.
- **Recurring**: a "recurring and options" cell with exactly one amount and one
  period (`3,000 €/year`, `$49 per month`) fills the recurring amount.
- **Dates**: `2026-10-13`, `13/10/2026` (or month first), `13 October 2026`,
  `October 13, 2026`, and Italian month names.
- **Priority**: P1–P4 or "excluded". **Source**: matched to the source list.
- **Owner**: a user of the workspace by email, name, or first name (when only
  one user has it). An owner that can't be found leaves the deal **without
  owner** (never the person importing).
- **Tags**: separated by commas or semicolons.
- **Person name**: the first word is the first name, the rest the last name.
  Invalid emails or phone numbers go to the note.
- **Signature / payment**: "signed" is imported as *to sign* and "paid" as
  *expected*, with a warning: evidence can't come from a sheet.
- **Stage text** is kept in the deal's sub-status (or in the note).
- **Note** columns become a note on the deal, prefixed with the file name.

## Create or update: the external reference

A column mapped to **external reference** is the row's key:

- If a deal of the workspace already has that `external_ref`, the row
  **updates** it (only the mapped fields), adds the person, and moves it to the
  mapped stage when allowed. A verified signature or payment is never
  downgraded by a sheet.
- Otherwise a **new** deal is created, at the bottom of its stage column, with
  source `import`.

This lets you import a second sheet that completes the first, and re-run the
same file safely (notes aren't duplicated).

A row is **rejected** when it has no title, company or key, when the key is
longer than 64 characters, or when its data fails validation. One bad row never
stops the others.

::: callout tip "Required fields don't block imports"
Deals are imported as they are: if the target stage requires fields the row
doesn't have, the deal is created anyway and the report warns about it. A lost
stage without reason gets `other`. If an update can't move a deal to its new
stage, the deal stays where it is with a warning.
:::

## Running imports from code

The wizard uses the `ManageImports` action, which you can call too:

```php
use Sellinnate\SalesCrm\Actions\ManageImports;

$imports = app(ManageImports::class);

$import = $imports->upload($team, $pipeline, $uploadedFile, $manager);

$imports->map($import, [
    'columns' => [0 => 'title', 1 => 'company', 2 => 'amount_once', 3 => 'stage', 4 => 'person_email'],
    'stages'  => ['Proposal' => $proposalStage->id, 'Signed' => $wonStage->id],
    'default_stage_id' => $firstStage->id,
    'month_first' => false,
], $manager);

$imports->run($import, $manager);   // dispatches the queued job
```

Column indexes start at 0. Field names are those of the table above in
snake_case (`external_ref`, `title`, `stage`, `amount_once`, `company`,
`company_vat`, `company_website`, `person_name`, `person_email`,
`person_mobile`, `person_phone`, `note`…), and `custom:<key>` for custom
fields. All three steps require the user to **manage** the workspace.

The job (`RunImport`) runs once per import, as the user who started it, with a
30-minute timeout, on `sales-crm.import.queue`. When it ends (done or failed)
`ImportFinished` fires; the counts, rejected rows and warnings are on the
`Import` model:

```php
$import->status;          // ImportStatus: uploaded, mapped, running, done, failed
$import->created_count;
$import->updated_count;
$import->rejected_count;
$import->rejected;        // [['row' => 7, 'reason' => '…'], …]
$import->warnings;
```

## Export

In the **list view** of the deals, the **Export** button downloads every deal
matching the current filters, one row per deal, with all the columns of the
list (custom fields included). CSV always; XLSX when `openspout/openspout` is
installed.

The download route is `GET /crm/export/deals` (name `sales-crm.deals.export`)
with the query parameters `workspace`, `pipeline`, `q`, `quick[]`, `owner`,
`priority`, `source`, `tag`, `closed` (`recent` or `all`) and `format` (`csv` or
`xlsx`). It only exports deals the user can see.

::: callout info "Formula-safe exports"
A cell starting with `=`, `+`, `-`, `@`, a tab or a carriage return would be
run as a formula by Excel or LibreOffice (a "CSV injection"). Every such cell is
prefixed with an apostrophe, so it's shown as text.
:::

## Best practices

- **Always map an external reference** if your sheet has one (an id from the old
  CRM): imports become repeatable.
- **Import into a test workspace first** if you have workspaces, check the
  report, then import for real.
- **Fix rejected rows** in the downloaded CSV and import that file again.

## Common pitfalls

- Uploading an XLSX without `openspout/openspout`: the file type is refused.
- Dates like `03/04/2026` read the wrong way: set "month first" in step 3.
- Owners not found: the owner column must contain the user's email or their
  exact name as stored in the CRM.
- No queue worker: the import stays "mapped" and never starts (with the `sync`
  queue it runs inline, which can time out on big files).
