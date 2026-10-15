<?php

use Illuminate\Support\Facades\Route;
use Sellinnate\SalesCrm\Http\Controllers\Api\DealController;
use Sellinnate\SalesCrm\Http\Controllers\Api\PipelineController;

/*
| The optional JSON API of the CRM (`sales-crm.api.enabled`), under `sales-crm.api.prefix` with
| `sales-crm.api.middleware` (auth:sanctum by default).
*/

Route::prefix(trim((string) config('sales-crm.api.prefix', 'api/crm'), '/'))
    ->middleware((array) config('sales-crm.api.middleware', ['api', 'auth:sanctum']))
    ->name('sales-crm.api.')
    ->group(function (): void {
        Route::get('pipelines', [PipelineController::class, 'index'])->name('pipelines.index');
        Route::get('deals', [DealController::class, 'index'])->name('deals.index');
        Route::post('deals', [DealController::class, 'store'])->name('deals.store');
        Route::get('deals/{deal}', [DealController::class, 'show'])->whereNumber('deal')->name('deals.show');
        Route::patch('deals/{deal}', [DealController::class, 'update'])->whereNumber('deal')->name('deals.update');
        Route::delete('deals/{deal}', [DealController::class, 'destroy'])->whereNumber('deal')->name('deals.destroy');
        Route::post('deals/{deal}/move', [DealController::class, 'move'])->whereNumber('deal')->name('deals.move');
        Route::post('deals/{deal}/notes', [DealController::class, 'note'])->whereNumber('deal')->name('deals.notes.store');
        Route::post('deals/{deal}/activities', [DealController::class, 'activity'])->whereNumber('deal')->name('deals.activities.store');
    });
