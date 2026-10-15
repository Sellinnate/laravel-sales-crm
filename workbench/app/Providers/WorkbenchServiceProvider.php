<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\User;

/**
 * The workbench app of the package: a plain Laravel app with users, used by the tests, the demo and
 * the Playwright check of the UI. Admins manage the CRM; everybody else works it.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('sales-crm.manage', fn (User $user, mixed $workspace = null): bool => $user->is_admin);
    }
}
