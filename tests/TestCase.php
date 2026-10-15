<?php

namespace Sellinnate\SalesCrm\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\McpServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use Sellinnate\SalesCrm\SalesCrmServiceProvider;
use Workbench\App\Models\User;
use Workbench\App\Providers\WorkbenchServiceProvider;

use function Orchestra\Testbench\default_migration_path;
use function Orchestra\Testbench\load_migration_paths;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;
    use WithWorkbench;

    protected function getPackageProviders($app): array
    {
        $providers = [SalesCrmServiceProvider::class, WorkbenchServiceProvider::class];

        if (class_exists(LivewireServiceProvider::class)) {
            array_unshift($providers, LivewireServiceProvider::class);
        }

        if (class_exists(McpServiceProvider::class)) {
            array_unshift($providers, McpServiceProvider::class);
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

            $config->set('database.default', 'testing');
            $config->set('database.connections.testing', self::databaseConnection());
        });
    }

    /**
     * The database of the tests: SQLite on a file (migrated once per run, every test in a
     * transaction), or the MySQL/PostgreSQL server of SALES_CRM_TEST_DB (the CI matrix).
     *
     * @return array<string, mixed>
     */
    private static function databaseConnection(): array
    {
        return match (env('SALES_CRM_TEST_DB')) {
            'mysql' => [
                'driver' => 'mysql',
                'host' => env('SALES_CRM_TEST_DB_HOST', '127.0.0.1'),
                'port' => env('SALES_CRM_TEST_DB_PORT', '3306'),
                'database' => env('SALES_CRM_TEST_DB_DATABASE', 'sales_crm_test'),
                'username' => env('SALES_CRM_TEST_DB_USERNAME', 'root'),
                'password' => env('SALES_CRM_TEST_DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('SALES_CRM_TEST_DB_HOST', '127.0.0.1'),
                'port' => env('SALES_CRM_TEST_DB_PORT', '5432'),
                'database' => env('SALES_CRM_TEST_DB_DATABASE', 'sales_crm_test'),
                'username' => env('SALES_CRM_TEST_DB_USERNAME', 'postgres'),
                'password' => env('SALES_CRM_TEST_DB_PASSWORD', ''),
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            default => self::sqliteFile(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function sqliteFile(): array
    {
        // In the temporary directory (tmpfs on most CI runners): no disk I/O for the tests.
        $path = sys_get_temp_dir().'/sales-crm-tests-'.substr(md5(__DIR__), 0, 8).'.sqlite';

        if (! is_file($path)) {
            touch($path);
        }

        return ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true];
    }

    /**
     * Only the paths, for the single migrate:fresh of RefreshDatabase: the helpers that migrate per
     * test also roll back after each one, which MySQL and PostgreSQL can't undo with the transaction
     * of the test (DDL commits it on MySQL).
     */
    protected function defineDatabaseMigrations(): void
    {
        load_migration_paths($this->app, [default_migration_path(), __DIR__.'/../workbench/database/migrations', __DIR__.'/database/migrations']);
    }
}
