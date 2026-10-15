<?php

namespace Sellinnate\SalesCrm;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Livewire\Livewire;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Authorization\DefaultAuthorizer;
use Sellinnate\SalesCrm\Console\InstallCommand;
use Sellinnate\SalesCrm\Console\PollInboxCommand;
use Sellinnate\SalesCrm\Console\RemindActivitiesCommand;
use Sellinnate\SalesCrm\Contracts\Authorizer;
use Sellinnate\SalesCrm\Contracts\SenderResolver;
use Sellinnate\SalesCrm\Contracts\WorkspaceResolver;
use Sellinnate\SalesCrm\Inbound\InboundManager;
use Sellinnate\SalesCrm\Listeners\SendNotifications;
use Sellinnate\SalesCrm\Livewire\Components;
use Sellinnate\SalesCrm\Mail\ConfigSenderResolver;
use Sellinnate\SalesCrm\Mcp\McpRegistrar;
use Sellinnate\SalesCrm\Workspaces\SingleWorkspaceResolver;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SalesCrmServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('sales-crm')
            ->hasConfigFile()
            ->hasViews('sales-crm')
            ->hasTranslations()
            ->hasMigration('create_sales_crm_tables')
            ->hasCommands([InstallCommand::class, RemindActivitiesCommand::class, PollInboxCommand::class]);
    }

    public function packageRegistered(): void
    {
        $this->app->scoped(WorkspaceResolver::class, function (Application $app): WorkspaceResolver {
            $class = config('sales-crm.workspaces.resolver') ?: SingleWorkspaceResolver::class;
            $resolver = is_string($class) ? $app->make($class) : null;

            if (! $resolver instanceof WorkspaceResolver) {
                throw new InvalidArgumentException('sales-crm: `sales-crm.workspaces.resolver` must implement '.WorkspaceResolver::class.'.');
            }

            return $resolver;
        });

        $this->app->scoped(Authorizer::class, function (Application $app): Authorizer {
            $class = config('sales-crm.authorization.authorizer') ?: DefaultAuthorizer::class;
            $authorizer = is_string($class) ? $app->make($class) : null;

            if (! $authorizer instanceof Authorizer) {
                throw new InvalidArgumentException('sales-crm: `sales-crm.authorization.authorizer` must implement '.Authorizer::class.'.');
            }

            return $authorizer;
        });

        $this->app->scoped(Access::class);
        $this->app->bindIf(SenderResolver::class, ConfigSenderResolver::class);
        $this->app->singleton(InboundManager::class);
        $this->app->singleton(SalesCrm::class);
    }

    public function packageBooted(): void
    {
        $this->registerGates();
        $this->registerPolicies();

        Event::subscribe(SendNotifications::class);

        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'sales-crm');

        $this->registerRoutes();

        if (class_exists(Livewire::class) && (bool) config('sales-crm.ui.enabled', true)) {
            Components::register();
        }

        if ((bool) config('sales-crm.mcp.enabled', false) && class_exists(\Laravel\Mcp\Server::class)) {
            McpRegistrar::register();
        }
    }

    private function registerGates(): void
    {
        foreach (['access_ability' => 'sales-crm.access', 'manage_ability' => 'sales-crm.manage'] as $key => $default) {
            $ability = (string) config('sales-crm.authorization.'.$key, $default);

            // Every authenticated user until the application defines the gate.
            if (! Gate::has($ability)) {
                Gate::define($ability, fn (?Authenticatable $user): bool => $user !== null);
            }
        }
    }

    private function registerPolicies(): void
    {
        $policies = config('sales-crm.authorization.policies', []);

        foreach (is_array($policies) ? $policies : [] as $model => $policy) {
            if (is_string($model) && is_string($policy) && class_exists($policy)) {
                Gate::policy($model, $policy);
            }
        }
    }

    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::group([], __DIR__.'/../routes/web.php');

        if ((bool) config('sales-crm.api.enabled', false)) {
            Route::group([], __DIR__.'/../routes/api.php');
        }
    }
}
