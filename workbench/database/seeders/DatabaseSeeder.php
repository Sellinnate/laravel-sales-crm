<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Sellinnate\SalesCrm\Database\Seeders\DemoSeeder;
use Workbench\App\Models\User;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(['email' => 'admin@example.test'], ['name' => 'Alex Admin', 'password' => 'password', 'is_admin' => true]);
        User::query()->updateOrCreate(['email' => 'sales@example.test'], ['name' => 'Sam Seller', 'password' => 'password', 'is_admin' => false]);
        User::query()->updateOrCreate(['email' => 'robin@example.test'], ['name' => 'Robin Rep', 'password' => 'password', 'is_admin' => false]);

        $this->call(DemoSeeder::class);
    }
}
