# Contributing

Thanks for your interest in improving **Sales CRM for Laravel**! This guide gets
you set up and explains the quality bar a PR needs to meet.

## Getting started

```bash
git clone https://github.com/Sellinnate/laravel-sales-crm
cd laravel-sales-crm
composer install
npm install          # only for the docs site and the CSS of the UI
```

The test suite runs on SQLite with no external service. To try the UI, start
the workbench app with demo data:

```bash
composer serve       # http://127.0.0.1:8000 — admin@example.test / password
```

## Quality gates (all required, all green)

Run these before opening a PR — CI enforces every one:

```bash
composer test            # Pest suite
composer analyse         # PHPStan, level 8 (no errors, no baseline)
composer format          # Laravel Pint (code style)
composer validate --strict
npm run docs:build       # if you touched docs/
npm run css:build        # if you touched views or resources/css (commit resources/dist)
```

To run the suite on MySQL or PostgreSQL as the CI does:

```bash
SALES_CRM_TEST_DB=mysql SALES_CRM_TEST_DB_DATABASE=sales_crm SALES_CRM_TEST_DB_USERNAME=root composer test
SALES_CRM_TEST_DB=pgsql SALES_CRM_TEST_DB_DATABASE=sales_crm SALES_CRM_TEST_DB_USERNAME=postgres composer test
```

Guidelines:

- **Tests are mandatory** for any behaviour change or new feature. Mirror the
  existing style (Pest, `Livewire::test()` for components, the helpers in
  `tests/helpers.php`).
- **Write through the actions.** The UI, the API and the MCP tools share
  `src/Actions/*`; put validation, authorization and events there.
- **Keep it agnostic**: anything specific to an application goes behind config
  or a contract.
- **Every UI string** goes in `resources/lang/en` and `resources/lang/it`.
- **SQL must run on MySQL, PostgreSQL and SQLite.**

## Pull requests

1. Branch from `main`.
2. Keep PRs focused; describe *what* and *why*, and update `CHANGELOG.md`
   under `Unreleased`.
3. Ensure all gates above are green and docs are updated.
4. Be kind in review.

## Reporting bugs / security

- Bugs and features: open a [GitHub issue](https://github.com/Sellinnate/laravel-sales-crm/issues).
- **Security vulnerabilities**: do **not** open a public issue — see
  [SECURITY.md](SECURITY.md).

## License

By contributing, you agree your contributions are licensed under the project's
[MIT license](LICENSE.md).
