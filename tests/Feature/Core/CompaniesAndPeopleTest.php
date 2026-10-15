<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Actions\ManageDealPeople;
use Sellinnate\SalesCrm\Actions\SaveCompany;
use Sellinnate\SalesCrm\Actions\SavePerson;
use Sellinnate\SalesCrm\Enums\PersonRole;
use Sellinnate\SalesCrm\Events\CompanyCreated;
use Sellinnate\SalesCrm\Events\PersonCreated;
use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Person;

beforeEach(function () {
    $this->seller = crmUser();
    $this->admin = crmUser(['is_admin' => true]);
});

it('finds the duplicate of a company by VAT number, domain or name', function () {
    Event::fake([CompanyCreated::class]);
    $acme = app(SaveCompany::class)->handle(null, ['name' => 'Acme S.r.l.', 'vat_number' => 'IT01234567890', 'website' => 'https://www.acme.example'], $this->seller);
    Event::assertDispatched(CompanyCreated::class);

    foreach ([['name' => 'Totally different', 'vat_number' => '01234567890'], ['name' => 'Other', 'email' => 'sales@acme.example'], ['name' => 'ACME srl']] as $data) {
        try {
            app(SaveCompany::class)->handle(null, $data, $this->seller);
            $this->fail('Duplicate not found for '.json_encode($data));
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('duplicate')
                ->and($exception->errors()['duplicate'][0])->toContain('#'.$acme->id);
        }
    }

    $forced = app(SaveCompany::class)->handle(null, ['name' => 'ACME srl'], $this->seller, null, force: true);
    expect($forced->id)->not->toBe($acme->id)->and(Company::query()->count())->toBe(2);
});

it('never deduplicates by a free mail domain or a shared site', function () {
    app(SaveCompany::class)->handle(null, ['name' => 'Rossi', 'email' => 'rossi@gmail.com', 'website' => 'https://facebook.com/rossi'], $this->seller);
    $other = app(SaveCompany::class)->handle(null, ['name' => 'Bianchi', 'email' => 'bianchi@gmail.com', 'website' => 'https://facebook.com/bianchi'], $this->seller);

    expect($other->domain)->toBeNull();
});

it('finds the duplicate of a person by email, phone or name in the company', function () {
    Event::fake([PersonCreated::class]);
    $company = SalesCrm::shared()->company(['name' => 'Acme']);
    app(SavePerson::class)->handle(null, ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@acme.example', 'mobile' => '333 123 4567', 'company_id' => $company->id], $this->seller);

    foreach ([['email' => 'JANE@acme.example'], ['first_name' => 'X', 'mobile' => '+39 333 1234567'], ['first_name' => 'jane', 'last_name' => 'DOE', 'company_id' => $company->id]] as $data) {
        expect(fn () => app(SavePerson::class)->handle(null, $data, $this->seller))->toThrow(ValidationException::class);
    }

    expect(fn () => app(SavePerson::class)->handle(null, ['job_title' => 'Nobody'], $this->seller))->toThrow(ValidationException::class)
        ->and(fn () => app(SavePerson::class)->handle(null, ['first_name' => 'Bad', 'mobile' => 'not a phone'], $this->seller))->toThrow(ValidationException::class);
});

it('completes a matched company with the details it lacks, never overwriting them', function () {
    $acme = SalesCrm::shared()->company(['name' => 'Acme', 'city' => 'Milan']);
    $same = SalesCrm::shared()->company(['name' => 'ACME', 'city' => 'Rome', 'industry' => 'Software']);

    expect($same->id)->toBe($acme->id)->and($same->city)->toBe('Milan')->and($same->industry)->toBe('Software');
});

it('announces the companies and people it creates while matching, not the matched ones', function () {
    Event::fake([CompanyCreated::class, PersonCreated::class]);

    $acme = SalesCrm::shared()->company(['name' => 'Acme'], $this->seller);
    SalesCrm::shared()->company(['name' => 'ACME']);
    SalesCrm::shared()->person(['first_name' => 'Jane', 'email' => 'jane@acme.example'], $acme, $this->seller);
    SalesCrm::shared()->person(['email' => 'JANE@acme.example'], $acme);

    Event::assertDispatchedTimes(CompanyCreated::class, 1);
    Event::assertDispatchedTimes(PersonCreated::class, 1);
    Event::assertDispatched(CompanyCreated::class, fn (CompanyCreated $event): bool => $event->company->is($acme) && $event->by?->getAuthIdentifier() === $this->seller->getAuthIdentifier());
});

it('deletes a company only without deals, and only for managers', function () {
    $deal = SalesCrm::shared()->createDeal(['title' => 'X', 'company_name' => 'Busy Ltd'], $this->seller);
    $busy = $deal->company;
    $idle = SalesCrm::shared()->company(['name' => 'Idle']);

    expect(fn () => app(SaveCompany::class)->delete($idle, $this->seller))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveCompany::class)->delete($busy, $this->admin))->toThrow(ValidationException::class);

    app(SaveCompany::class)->delete($idle, $this->admin);
    expect(Company::query()->find($idle->id))->toBeNull();
});

it('manages the people of a deal and their primary contact', function () {
    $deal = SalesCrm::shared()->createDeal(['title' => 'X', 'company_name' => 'Acme', 'person' => ['first_name' => 'Ann']], $this->seller);
    $people = app(ManageDealPeople::class);
    $bob = $people->attach($deal, ['first_name' => 'Bob', 'role' => 'decision_maker'], $this->seller);
    $ann = $deal->people()->where('first_name', 'Ann')->first();

    expect($bob->company_id)->toBe($deal->company_id)
        ->and($deal->fresh()->people->first()->id)->toBe($ann->id);

    $people->update($deal, $bob, PersonRole::Influencer, true, $this->seller);
    expect($deal->fresh()->people->first()->id)->toBe($bob->id)
        ->and($deal->fresh()->people->first()->pivot->role)->toBe('influencer');

    $people->detach($deal, $bob, $this->seller);
    expect($deal->fresh()->people->first()->pivot->is_primary)->toBeTruthy()
        ->and(Person::query()->find($bob->id))->not->toBeNull();
});

it('gives the deals of a deleted person their next primary contact', function () {
    $deal = SalesCrm::shared()->createDeal(['title' => 'X', 'person' => ['first_name' => 'Ann']], $this->seller);
    $bob = app(ManageDealPeople::class)->attach($deal, ['first_name' => 'Bob'], $this->seller);
    $ann = $deal->people()->where('first_name', 'Ann')->first();

    app(SavePerson::class)->delete($ann, $this->admin);

    expect($deal->fresh()->people)->toHaveCount(1)
        ->and($deal->fresh()->people->first()->id)->toBe($bob->id)
        ->and($deal->fresh()->people->first()->pivot->is_primary)->toBeTruthy();
});

it('matches people by name in their company whatever the case of accented letters', function () {
    $company = SalesCrm::shared()->company(['name' => 'Acme']);
    $nicolo = SalesCrm::shared()->person(['first_name' => 'NICOLÒ', 'last_name' => 'D\'ANGELO'], $company);

    expect(SalesCrm::shared()->person(['first_name' => 'Nicolò', 'last_name' => 'd\'Angelo'], $company)->is($nicolo))->toBeTrue()
        ->and(Person::query()->count())->toBe(1);
});
