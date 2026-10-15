<?php

use Carbon\CarbonImmutable;
use Sellinnate\SalesCrm\Facades\SalesCrm;

it('builds the dashboard of the deals the user sees', function () {
    $admin = crmUser(['is_admin' => true, 'name' => 'Alex']);
    $seller = crmUser(['name' => 'Sam']);
    $pipeline = crmPipeline();

    SalesCrm::shared()->createDeal(['title' => 'Open A', 'amount_once' => 1000, 'owner_id' => $seller->id, 'expected_close_date' => CarbonImmutable::now()->addMonth()->toDateString(), 'source' => 'referral'], $seller);
    SalesCrm::shared()->createDeal(['title' => 'Open B', 'amount_once' => 3000, 'stage_id' => crmStage($pipeline, 'Negotiation')->id, 'owner_id' => $admin->id], $admin);
    $won = SalesCrm::shared()->createDeal(['title' => 'Won', 'amount_once' => 5000, 'owner_id' => $seller->id, 'source' => 'referral'], $seller);
    SalesCrm::moveDeal($won, crmStage($pipeline, 'Won'), $seller);
    $lost = SalesCrm::shared()->createDeal(['title' => 'Lost', 'amount_once' => 2000], $seller);
    SalesCrm::moveDeal($lost, crmStage($pipeline, 'Lost'), $seller, ['lost_reason' => 'competitor']);
    SalesCrm::shared()->createDealFrom($admin, ['title' => 'From integration', 'amount_once' => 700]);

    $report = SalesCrm::reports()->build($admin, null, null, CarbonImmutable::now()->subMonth(), CarbonImmutable::now());

    expect($report['open_total']['count'])->toBe(3)
        ->and($report['open_total']['value'])->toBe(470000)
        ->and($report['won'])->toBe(['count' => 1, 'value' => 500000])
        ->and($report['lost'])->toBe(['count' => 1, 'value' => 200000])
        ->and($report['win_rate'])->toBe(50.0)
        ->and($report['lost_reasons'][0])->toBe(['reason' => 'Competitor', 'count' => 1])
        ->and(collect($report['open'])->pluck('stage')->all())->toBe(['New lead', 'Negotiation'])
        ->and(collect($report['forecast'])->sum('value'))->toBe(100000)
        ->and($report['attention']['no_activity'])->toBe(3)
        ->and(collect($report['owners'])->firstWhere('owner', 'Sam')['won_value'])->toBe(500000)
        ->and(collect($report['sources'])->firstWhere('source', 'Referral')['share'])->toBe(100.0)
        ->and($report['integrations']['count'])->toBe(1)
        ->and(collect($report['stage_days'])->pluck('stage')->all())->toContain('New lead');

    $mine = SalesCrm::reports()->build($admin, null, $admin->id, CarbonImmutable::now()->subMonth(), CarbonImmutable::now());
    expect($mine['open_total']['value'])->toBe(300000);
});

it('ignores an owner filter that cannot be a user key', function () {
    $admin = crmUser(['is_admin' => true]);
    SalesCrm::shared()->createDeal(['title' => 'Counted', 'amount_once' => 1000], $admin);
    $period = [CarbonImmutable::now()->subMonth(), CarbonImmutable::now()];

    expect(SalesCrm::reports()->build($admin, null, 'abc', ...$period)['open_total'])->toBe(SalesCrm::reports()->build($admin, null, null, ...$period)['open_total'])
        ->and(SalesCrm::reports()->build($admin, null, '5abc', ...$period)['open_total']['count'])->toBe(1);
});
