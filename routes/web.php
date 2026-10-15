<?php

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Sellinnate\SalesCrm\Http\Controllers\AssetController;
use Sellinnate\SalesCrm\Http\Controllers\DocumentController;
use Sellinnate\SalesCrm\Http\Controllers\ExportDealsController;
use Sellinnate\SalesCrm\Http\Controllers\ImportRejectedController;
use Sellinnate\SalesCrm\Http\Controllers\InboundWebhookController;
use Sellinnate\SalesCrm\Livewire;

/*
| The routes of sellinnate/sales-crm: the pages of the optional Livewire UI and the downloads, under
| `sales-crm.ui.prefix` with `sales-crm.ui.middleware`; the signed links of the quotes; the CSS of
| the UI; and the inbound webhook (closed until a secret is set).
*/

$prefix = trim((string) config('sales-crm.ui.prefix', 'crm'), '/');
$domain = config('sales-crm.ui.domain');
$middleware = (array) config('sales-crm.ui.middleware', ['web', 'auth']);

Route::get($prefix.'/assets/sales-crm.css', AssetController::class)->name('sales-crm.assets.css');

if ((bool) config('sales-crm.ui.enabled', true)) {
    $attributes = ['prefix' => $prefix, 'middleware' => $middleware, 'as' => 'sales-crm.'];

    if (is_string($domain) && $domain !== '') {
        $attributes['domain'] = $domain;
    }

    Route::group($attributes, function (): void {
        if (class_exists(Component::class)) {
            Route::get('/', Livewire\DealsBoard::class)->name('deals');
            Route::get('deals/{deal}', Livewire\DealPage::class)->whereNumber('deal')->name('deals.show');
            Route::get('agenda', Livewire\Agenda::class)->name('agenda');
            Route::get('companies', Livewire\Companies::class)->name('companies');
            Route::get('companies/{company}', Livewire\CompanyPage::class)->whereNumber('company')->name('companies.show');
            Route::get('people', Livewire\People::class)->name('people');
            Route::get('people/{person}', Livewire\PersonPage::class)->whereNumber('person')->name('people.show');
            Route::get('reports', Livewire\Reports::class)->name('reports');
            Route::get('import', Livewire\Import::class)->name('import');
            Route::get('settings', Livewire\Settings::class)->name('settings');
        }

        Route::get('documents/{document}', [DocumentController::class, 'download'])->whereNumber('document')->name('documents.download');
        Route::get('export/deals', ExportDealsController::class)->name('deals.export');
        Route::get('imports/{import}/rejected', ImportRejectedController::class)->whereNumber('import')->name('imports.rejected');
    });
}

// A quote sent by email with {{quote_link}}: a temporary signed link, rate limited.
Route::get($prefix.'/shared/quotes/{document}', [DocumentController::class, 'signed'])
    ->middleware([SubstituteBindings::class, 'signed', 'throttle:30,1'])
    ->whereNumber('document')
    ->name('sales-crm.documents.signed');

Route::post(trim((string) config('sales-crm.email.inbound.webhook_path', 'sales-crm/inbound'), '/'), InboundWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('sales-crm.inbound');
