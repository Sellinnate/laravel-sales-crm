<?php

use Illuminate\Support\Facades\File;

it('publishes the configuration and the migrations and seeds the demo data', function () {
    $config = config_path('sales-crm.php');
    $migrations = fn () => File::glob(database_path('migrations/*_create_sales_crm_tables.php'));
    File::delete([$config, ...$migrations()]);

    $this->artisan('sales-crm:install', ['--demo' => true])
        ->expectsConfirmation('Run the migrations now?', 'no')
        ->assertSuccessful();

    expect(File::exists($config))->toBeTrue()
        ->and($migrations())->toHaveCount(1)
        ->and(\Sellinnate\SalesCrm\Models\Deal::query()->count())->toBe(30);

    // A second run adds no duplicate demo data.
    $this->artisan('sales-crm:install', ['--demo' => true, '--force' => true])->expectsConfirmation('Run the migrations now?', 'no')->assertSuccessful();
    expect(\Sellinnate\SalesCrm\Models\Deal::query()->count())->toBe(30);

    File::delete([$config, ...$migrations()]);
});

it('never seeds the demo data in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('sales-crm:install', ['--demo' => true, '--no-migrate' => true])->assertFailed();
    expect(\Sellinnate\SalesCrm\Models\Deal::query()->count())->toBe(0);

    app()->detectEnvironment(fn () => 'testing');

    File::delete([config_path('sales-crm.php'), ...File::glob(database_path('migrations/*_create_sales_crm_tables.php'))]);
});
