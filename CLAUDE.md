# CLAUDE.md

Operating guide for working on **Sales CRM for Laravel** (`sellinnate/sales-crm`).
Follow this methodology for every task in this repository. It encodes how the
project is built, tested, documented and released — keep new work consistent with it.

## What this project is

A Laravel package providing a sales CRM: pipelines and stages, deals, companies
and people, activities and reminders, notes, documents, outbound and inbound
email, import/export, reports and a timeline. A **headless core** (actions,
events, policies, fluent API) with three optional interfaces on top: a
**Livewire 3/4 UI**, **MCP tools** (laravel/mcp) and a **JSON API**.

- **Package name:** `sellinnate/sales-crm` · **Namespace:** `Sellinnate\SalesCrm` · **Facade:** `SalesCrm`
- **Provider:** `Sellinnate\SalesCrm\SalesCrmServiceProvider` · **Config:** `config/sales-crm.php` · **Tables:** prefix `crm_` (configurable)
- **Repo:** https://github.com/Sellinnate/laravel-sales-crm (remote `origin`)
- **Docs site:** https://laravel-sales-crm.selli.io (docmd, Cloudflare Worker `laravel-sales-crm`, rebuilt from `main`)
- **Requirements:** PHP `^8.2`, Laravel 11/12/13, MySQL 8+/PostgreSQL/SQLite, Livewire 3 or 4 for the UI

## Golden rules

1. **Agnostic.** The package knows nothing about the host app: no product
   names, no fixed user/team model, no fixed roles. Everything host-specific
   goes through config or a contract in `src/Contracts`.
2. **Everything we claim must exist.** Never document a feature, config key,
   event or command that isn't implemented and tested.
3. **All quality gates green before any commit** (tests, PHPStan L8, Pint,
   `composer validate`, `npm run docs:build` when docs change, `npm run css:build`
   when views or CSS change).
4. **Docs and code change together.** A behaviour/config change updates
   `docs/`, `README.md`, `config/sales-crm.php` comments and `CHANGELOG.md`.
5. **One write path.** The UI, the MCP tools, the JSON API and the facade call the
   same `src/Actions/*`: validation, authorization, locks and events live there,
   never in a Livewire component or a controller.

## Architecture & conventions

- **Workspaces:** every record has an optional polymorphic workspace
  (`workspace_type`/`workspace_id` + `workspace_key` for unique indexes). The
  `WorkspaceResolver` contract decides which workspaces a user works in; queries
  are constrained with `scopeVisibleTo($user)`. Never query CRM models for a
  user without it.
- **Authorization:** the `Authorizer` contract (default: gates
  `sales-crm.access` / `sales-crm.manage`, defined only if the app hasn't) plus
  regular policies mapped in config. Actions authorize themselves.
- **Tables and connection:** always go through `Crm::table()`, `Crm::db()` and the
  `CrmModel` base class, so prefix and connection stay configurable. Raw SQL
  goes through `Crm::raw()` and must be valid on MySQL, PostgreSQL and SQLite
  (see `Support\Search` for driver-aware expressions).
- **Events** implement `ShouldDispatchAfterCommit`; notifications are sent by
  `Listeners\SendNotifications` only when channels are configured.
- **Concurrency:** board moves and contact de-duplication run under
  `Support\Locks` (cache locks, reentrant).
- **Optional dependencies** (`livewire/livewire`, `openspout/openspout`,
  `laravel/mcp`, `directorytree/imapengine`, `giggsey/libphonenumber-for-php`)
  are `suggest`ed and guarded with `class_exists()`; the core must work without
  any of them.
- **UI:** Livewire class components in `src/Livewire`, Blade views in
  `resources/views`, own Blade components (`x-sales-crm::*`), Tailwind v4 compiled
  to `resources/dist/sales-crm.css` (committed, served by the package) and scoped
  under `.sales-crm`. Buttons live inside the components, not in layout slots.
  Drag & drop is native HTML5 + Alpine (works on Livewire 3 and 4).
- **Translations:** every UI string is in `resources/lang/en` and `resources/lang/it`;
  `tests/Unit/TranslationsTest.php` fails on a missing key.
- **Migrations:** only new, additive migrations after 1.0. Never edit the
  published stub of a released version.

## Testing methodology

- **Framework:** Pest, Orchestra Testbench with the `workbench/` app (User with
  teams and an `is_admin` flag). Tests live in `tests/Unit` and `tests/Feature`
  (`Core`, `Ui`, MCP, API, install).
- **Database:** SQLite file in the system temp dir by default; set
  `SALES_CRM_TEST_DB=mysql|pgsql` (+ `SALES_CRM_TEST_DB_HOST/PORT/DATABASE/USERNAME/PASSWORD`)
  to run on a server, as the CI does.
- **Cover real behaviour:** authorization and workspace isolation, required
  fields, events and their payloads, idempotency, edge cases of imports.
  Livewire components are tested with `Livewire::test()`; the main UI flows are
  also verified with Playwright against `composer serve`.

## Quality gates (run before every commit)

```bash
composer test            # Pest suite
composer analyse         # PHPStan level 8 — zero errors
composer format          # Laravel Pint — zero style changes
composer validate --strict
npm run docs:build       # when docs/ changes
npm run css:build        # when views or resources/css change (commit the dist file)
```

PHPStan rules (level 8): fix the underlying type, never silence. The only
accepted `@phpstan-ignore`s are the two documented in `Support\Crm` (raw SQL
expressions and the Laravel 11 `afterRollBack` guard).

## Documentation alignment

The docs site is **docmd** (`@mgks/docmd`), sources in `docs/`, built to `site/`
(gitignored), served by the Cloudflare Worker in `wrangler.jsonc`.

- Callouts use three colons: `::: callout tip "Title"` … `:::`.
- Cross-page anchors need an explicit `{#id}` on the target heading.
- New pages go in the `navigation` of `docmd.config.json`.
- Docs are in English and product-agnostic (fictitious companies, `example.test`).

## Release process

1. `main` green in CI (test matrix, PHPStan, Pint, install test, docs build).
2. Move the `Unreleased` notes of `CHANGELOG.md` under the new version; update `UPGRADE.md` for breaking changes.
3. Annotated tag `vX.Y.Z` (semver) and a GitHub Release.
4. Packagist: submit the repository once, then "Update" (or the GitHub webhook) for new tags.

## Commit messages

Imperative English sentence describing the change (no prefixes), e.g.
`Detect afterRollBack() without the macros of the connection (Laravel 11)`.
