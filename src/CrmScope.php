<?php

namespace Sellinnate\SalesCrm;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Sellinnate\SalesCrm\Actions\CreateDeal;
use Sellinnate\SalesCrm\Models\Activity;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Person;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Models\WorkspaceSetting;
use Sellinnate\SalesCrm\Services\DefaultPipeline;
use Sellinnate\SalesCrm\Services\Matcher;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The CRM of one workspace (or of the shared CRM): its records and the ways to create them.
 *
 *     SalesCrm::for($team)->deals()->open()->get();
 *     SalesCrm::for($team)->createDeal(['title' => 'Website redesign', 'amount_once' => 12000], by: $user);
 *     SalesCrm::for($team)->createDealFrom($order, ['title' => 'Order #'.$order->id]);
 */
class CrmScope
{
    public function __construct(public readonly ?Workspace $workspace) {}

    /**
     * @return Builder<Deal>
     */
    public function deals(): Builder
    {
        return Deal::query()->inWorkspace($this->workspace);
    }

    /**
     * @return Builder<Pipeline>
     */
    public function pipelines(): Builder
    {
        return Pipeline::query()->inWorkspace($this->workspace)->ordered();
    }

    /**
     * @return Builder<Company>
     */
    public function companies(): Builder
    {
        return Company::query()->inWorkspace($this->workspace);
    }

    /**
     * @return Builder<Person>
     */
    public function people(): Builder
    {
        return Person::query()->inWorkspace($this->workspace);
    }

    /**
     * @return Builder<Activity>
     */
    public function activities(): Builder
    {
        return Activity::query()->inWorkspace($this->workspace);
    }

    /**
     * The first pipeline of the workspace, creating the default one when there is none.
     */
    public function defaultPipeline(?Authenticatable $by = null): Pipeline
    {
        return app(DefaultPipeline::class)->ensureFor($this->workspace, $by);
    }

    public function settings(): WorkspaceSetting
    {
        return WorkspaceSetting::for($this->workspace);
    }

    /**
     * A new deal (see {@see CreateDeal::handle()} for the keys of `$data`). Without `$by` the deal
     * comes from the system and skips the authorization of a user.
     *
     * @param  array<string, mixed>  $data
     */
    public function createDeal(array $data, ?Authenticatable $by = null): Deal
    {
        return app(CreateDeal::class)->handle($this->workspace, $data, $by);
    }

    /**
     * The deal born from a record of your application, once per record (idempotent).
     *
     * @param  array<string, mixed>  $data
     */
    public function createDealFrom(Model $origin, array $data, ?Authenticatable $by = null): Deal
    {
        return app(CreateDeal::class)->fromOrigin($origin, $this->workspace, $data, $by);
    }

    /**
     * The company matching the data (VAT number, domain, name), completed, or a new one.
     *
     * @param  array<string, mixed>  $data
     */
    public function company(array $data, ?Authenticatable $by = null): Company
    {
        return app(Matcher::class)->company($this->workspace, $data, $by);
    }

    /**
     * The person matching the data (email, phone, name in the company), completed, or a new one.
     *
     * @param  array<string, mixed>  $data
     */
    public function person(array $data, ?Company $company = null, ?Authenticatable $by = null): Person
    {
        return app(Matcher::class)->person($this->workspace, $data, $company, $by);
    }
}
