<?php

use Sellinnate\SalesCrm\Facades\SalesCrm;

it('creates the default pipeline and a deal', function () {
    $user = crmUser();
    $deal = SalesCrm::shared()->createDeal(['title' => 'Website redesign', 'amount_once' => 1500], $user);

    expect($deal->title)->toBe('Website redesign')
        ->and($deal->amount_once_cents)->toBe(150000)
        ->and($deal->stage->name)->toBe('New lead')
        ->and($deal->owner_id)->toBe($user->id);
});
