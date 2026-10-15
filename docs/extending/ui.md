---
title: "Customizing the UI"
description: "Render the CRM inside your own layout, change the brand and the back link, theme the colours, publish and override views, and translate the interface."
---

# Customizing the UI

The web UI works out of the box as a standalone app at `/crm`. This page shows
how to make it feel part of your product: your layout around it, your name and
colours, your wording, and if needed your own Blade views.

## Brand and back link

```dotenv
SALES_CRM_BRAND="Acme Sales"
```

```php
// config/sales-crm.php
'ui' => [
    // ...
    'home_url' => '/dashboard',   // the "back to the app" icon in the CRM header; null hides it
    'brand'    => env('SALES_CRM_BRAND', 'Sales CRM'),
],
```

The brand is shown in the CRM header and in the page title, and is the name of
the CRM in notifications when no workspace name applies.

## URL, domain and middleware

```php
'ui' => [
    'prefix'     => env('SALES_CRM_PREFIX', 'crm'),     // /crm → /sales
    'domain'     => 'crm.example.test',                 // serve the CRM on a subdomain (null = any)
    'middleware' => ['web', 'auth', 'verified'],        // add your own middleware
],
```

Every page, download and export goes through this middleware, and then through
the `sales-crm.access` gate.

## Your own layout

By default pages render in `sales-crm::layouts.app`, a minimal standalone HTML
page. To render the CRM inside your application's layout (your top bar, your
sidebar), point `ui.layout` to a Blade layout of yours:

```php
'ui' => [
    'layout' => 'layouts.crm',
],
```

The layout must:

1. render **`{{ $slot }}`** where the CRM goes;
2. include **`<x-sales-crm::styles />`** in its `<head>`;
3. load Livewire's assets (Livewire injects them automatically unless you
   disabled it; otherwise add `@livewireStyles` and `@livewireScripts`).

The page title is passed to the layout as `$title`.

```blade
{{-- resources/views/layouts/crm.blade.php --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'CRM' }} · Acme</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <x-sales-crm::styles />
    @livewireStyles
</head>
<body>
    @include('partials.app-topbar')

    {{ $slot }}

    @livewireScripts
</body>
</html>
```

::: callout info "The CRM's styles don't leak"
All CRM styles live under the `.sales-crm` root element: there is no global
reset, so your pages keep their look. Your own global CSS can still affect the
CRM if it targets bare elements (`button { … }`); scope such rules if you see
side effects.
:::

::: callout warning "Don't load Alpine twice"
Livewire 3 and 4 bundle Alpine.js. If your app also loads its own Alpine, follow
the Livewire documentation on manually bundling Alpine, or the board's drag
and drop may break.
:::

## Colours and theme

The stylesheet is **precompiled** (Tailwind CSS v4) and served by the package
at `/crm/assets/sales-crm.css`: your app needs no npm build for it. The colours
are CSS custom properties on `.sales-crm`, so you can theme the CRM from your
own CSS:

```css
/* resources/css/app.css — loaded on the CRM pages */
.sales-crm {
    --crm-brand: #4f46e5;       /* buttons, active navigation, accents */
    --crm-brand-ink: #ffffff;   /* text on brand-coloured backgrounds */
    --crm-brand-soft: #e0e7ff;  /* light brand backgrounds */
}

.dark .sales-crm {
    --crm-brand: #a5b4fc;
    --crm-brand-ink: #1e1b4b;
    --crm-brand-soft: #312e81;
}
```

| Variable | Used for |
|---|---|
| `--crm-surface`, `--crm-raised`, `--crm-sunken`, `--crm-canvas` | Backgrounds: cards, popovers, inset areas, page |
| `--crm-line`, `--crm-line-strong` | Borders |
| `--crm-ink`, `--crm-muted`, `--crm-faint` | Text: main, secondary, hints |
| `--crm-brand`, `--crm-brand-ink`, `--crm-brand-soft` | Brand colour |
| `--crm-danger`, `--crm-warning`, `--crm-success`, `--crm-info` (+ `-soft`) | Status colours |
| `--crm-accent` | Accent colour |

The package defines these variables inside a CSS `@layer`, so any plain rule of
yours wins without `!important`.

**Dark mode** follows the operating system (`prefers-color-scheme`) or a `dark`
class on `<html>`. Because an unlayered `.sales-crm` rule of yours applies in
both modes, set dark values explicitly (with `.dark .sales-crm` and/or a
`@media (prefers-color-scheme: dark)` block) when you override colours.

## Translations {#translations}

The UI ships in **English** (`en`) and **Italian** (`it`) and follows your
app's locale (`app()->getLocale()`). Numbers, money and dates use
`sales-crm.locale` (or the app locale) and `sales-crm.currency`.

To change wording or add a language, publish the translation files:

```bash
php artisan vendor:publish --tag=sales-crm-translations
```

They land in `lang/vendor/sales-crm/{en,it}/`, one file per area:

| File | Contains |
|---|---|
| `ui.php` | Labels, buttons, messages of the interface |
| `fields.php` | Field names (also used in validation messages) |
| `enums.php` | Labels of stage kinds, colours, priorities, sources, lost reasons, activity types, statuses, required fields |
| `validation.php` | Validation and business-rule messages |
| `import.php` | Import wizard, field names and warnings |
| `notifications.php` | Notification titles and lines |

Override only the keys you need (Laravel merges your files with the package's).
To add a language, create `lang/vendor/sales-crm/de/ui.php` and the other files
with the same keys.

```php
// lang/vendor/sales-crm/en/enums.php — call priority P1 "Hot"
return [
    'priority' => ['p1' => 'Hot', 'p2' => 'P2', 'p3' => 'P3', 'p4' => 'P4', 'excluded' => 'Excluded'],
];
```

## Overriding views

For deeper changes, publish the Blade views:

```bash
php artisan vendor:publish --tag=sales-crm-views
```

They are copied to `resources/views/vendor/sales-crm/` and take precedence over
the package's. The main ones:

| View | What it renders |
|---|---|
| `layouts/app.blade.php` | The default standalone layout |
| `components/shell.blade.php` | The CRM header (brand, navigation, back link) and page frame |
| `components/styles.blade.php` | The `<link>` to the CRM stylesheet |
| `livewire/deals-board.blade.php` | The deals page (board and list) |
| `livewire/partials/deal-card.blade.php` | A card of the board |
| `livewire/partials/deal-list.blade.php` | The list view |
| `livewire/deal-panel.blade.php` | The deal side panel / page |
| `livewire/agenda.blade.php`, `companies.blade.php`, `people.blade.php`, `company-page.blade.php`, `person-page.blade.php`, `reports.blade.php`, `import.blade.php`, `settings.blade.php` | The other pages |
| `mail/deal-email.blade.php` | The plain-text body of emails sent from deals |

::: callout warning "Publish only what you change"
A published view no longer receives the package's fixes and improvements.
After publishing, **delete the files you don't modify**, and compare your
copies with the package's after each upgrade.
:::

::: callout warning "Utility classes are precompiled"
The CSS contains only the classes the package's own views use. A Tailwind class
you add in a published view that the package never uses won't exist in the
CRM stylesheet: style it with your own CSS (loaded by your layout) or reuse the
`crm-*` component classes (`crm-btn`, `crm-btn-primary`, `crm-card`,
`crm-badge`…).
:::

## Linking into the CRM

Use the route names from your own views:

```blade
<a href="{{ route('sales-crm.deals') }}">Pipeline</a>
<a href="{{ route('sales-crm.agenda') }}">My agenda</a>
<a href="{{ route('sales-crm.deals.show', $deal) }}">{{ $deal->title }}</a>
<a href="{{ route('sales-crm.companies.show', $company) }}">{{ $company->name }}</a>
```

## Best practices

- **Prefer configuration, CSS variables and translations** over published views:
  they survive upgrades.
- **Use your own layout** to give the CRM your app's navigation; keep the CRM's
  own header for its pages.
- **Keep `home_url`** pointing to your app so users find their way back.

## Common pitfalls

- Your layout without `<x-sales-crm::styles />`: the CRM renders unstyled.
- Your layout without Livewire's scripts (when auto-injection is disabled):
  nothing is interactive.
- Expecting new Tailwind classes in published views to work: see the warning
  above.
