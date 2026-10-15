<?php

namespace Sellinnate\SalesCrm\Livewire;

use Livewire\Livewire;

/**
 * Registers the Livewire components of the CRM UI (the pages are routed by class in routes/web.php).
 */
final class Components
{
    public const COMPONENTS = [
        'sales-crm.deals-board' => DealsBoard::class,
        'sales-crm.deal-panel' => DealPanel::class,
        'sales-crm.deal-page' => DealPage::class,
        'sales-crm.agenda' => Agenda::class,
        'sales-crm.companies' => Companies::class,
        'sales-crm.company-page' => CompanyPage::class,
        'sales-crm.people' => People::class,
        'sales-crm.person-page' => PersonPage::class,
        'sales-crm.record-feed' => RecordFeed::class,
        'sales-crm.reports' => Reports::class,
        'sales-crm.import' => Import::class,
        'sales-crm.settings' => Settings::class,
    ];

    public static function register(): void
    {
        foreach (self::COMPONENTS as $name => $class) {
            Livewire::component($name, $class);
        }
    }
}
