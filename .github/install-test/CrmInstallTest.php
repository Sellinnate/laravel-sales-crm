<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sellinnate\SalesCrm\Facades\SalesCrm;
use Tests\TestCase;

/**
 * Copied into a fresh Laravel application by the install workflow: the package installed with
 * Composer and `sales-crm:install` works without any other change to the application.
 */
class CrmInstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_crm_works_in_a_fresh_application(): void
    {
        $this->assertFileExists(config_path('sales-crm.php'));

        $user = User::factory()->create();
        $deal = SalesCrm::shared()->createDeal(['title' => 'Website redesign', 'company_name' => 'Acme', 'amount_once' => 1000], $user);

        $this->assertSame('New lead', $deal->stage?->name);
        $this->assertSame('Acme', $deal->company?->name);

        $this->actingAs($user)->get('/crm')->assertOk()->assertSee('Website redesign');
        $this->get('/crm/deals/'.$deal->id)->assertOk()->assertSee('Acme');
        $this->get('/crm/companies')->assertOk();
        $this->get('/crm/reports')->assertOk();
        $this->get('/crm/assets/sales-crm.css')->assertOk();
    }
}
