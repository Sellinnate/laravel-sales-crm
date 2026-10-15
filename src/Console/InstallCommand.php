<?php

namespace Sellinnate\SalesCrm\Console;

use Illuminate\Console\Command;
use Sellinnate\SalesCrm\Database\Seeders\DemoSeeder;

/**
 * Publishes the configuration and the migrations of the CRM, runs the migrations when asked and
 * fills the CRM with demo data with --demo.
 */
class InstallCommand extends Command
{
    protected $signature = 'sales-crm:install
        {--migrate : Run the migrations after publishing them}
        {--demo : Seed demo pipelines, deals, companies and people (never in production)}
        {--force : Overwrite the files already published}';

    protected $description = 'Install the Sales CRM: publish config and migrations, migrate and seed demo data';

    public function handle(): int
    {
        $this->components->info('Installing sellinnate/sales-crm');

        $this->callSilently('vendor:publish', ['--tag' => 'sales-crm-config', '--force' => (bool) $this->option('force')]);
        $this->components->task('Configuration published to config/sales-crm.php');

        $this->callSilently('vendor:publish', ['--tag' => 'sales-crm-migrations', '--force' => (bool) $this->option('force')]);
        $this->components->task('Migrations published to database/migrations');

        if ($this->option('migrate') || ($this->input->isInteractive() && $this->confirm('Run the migrations now?', true))) {
            $this->call('migrate', ['--force' => true]);
        }

        if ($this->option('demo')) {
            if ($this->laravel->environment('production') && ! $this->option('force')) {
                $this->components->error('Demo data is never seeded in production (use --force if you really mean it).');

                return self::FAILURE;
            }

            $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
        }

        $this->newLine();
        $this->components->info('Done. Next steps:');
        $this->components->bulletList([
            'Open /'.trim((string) config('sales-crm.ui.prefix', 'crm'), '/').' (the UI needs livewire/livewire)',
            'Schedule `sales-crm:remind-activities` every minute and, with an inbound driver, `sales-crm:poll-inbox`',
            'Define the `sales-crm.access` and `sales-crm.manage` gates to restrict who works and configures the CRM',
            'Docs: https://laravel-sales-crm.selli.io',
        ]);

        return self::SUCCESS;
    }
}
