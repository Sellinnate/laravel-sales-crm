<?php

namespace Sellinnate\SalesCrm\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use Sellinnate\SalesCrm\SalesCrmServiceProvider;
use Workbench\App\Models\User;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;
    use WithWorkbench;

    protected function getPackageProviders($app): array
    {
        $providers = [SalesCrmServiceProvider::class];

        if (class_exists(\Livewire\LivewireServiceProvider::class)) {
            array_unshift($providers, \Livewire\LivewireServiceProvider::class);
        }

        return $providers;
    }

    protected function defineEnvironment($app): void
    {
        tap($app['config'], function (Repository $config): void {
            $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
            $config->set('auth.providers.users.model', User::class);
            $config->set('mail.default', 'array');
            $config->set('mail.from.address', 'crm@example.test');
            $config->set('mail.from.name', 'Example CRM');
            $config->set('queue.default', 'sync');
            $config->set('cache.default', 'array');
            $config->set('sales-crm.ui.enabled', (bool) env('SALES_CRM_UI', true));

            if (env('DB_CONNECTION') === null) {
                $config->set('database.default', 'testing');
            }
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom([__DIR__.'/../workbench/database/migrations', __DIR__.'/database/migrations']);
    }
}
