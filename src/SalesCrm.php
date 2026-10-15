<?php

namespace Sellinnate\SalesCrm;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Sellinnate\SalesCrm\Actions\DeleteDeal;
use Sellinnate\SalesCrm\Actions\ManageActivities;
use Sellinnate\SalesCrm\Actions\ManageDealPeople;
use Sellinnate\SalesCrm\Actions\ManageDocuments;
use Sellinnate\SalesCrm\Actions\ManageNotes;
use Sellinnate\SalesCrm\Actions\MoveDeal;
use Sellinnate\SalesCrm\Actions\SendEmail;
use Sellinnate\SalesCrm\Actions\UpdateDeal;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Inbound\Inbound;
use Sellinnate\SalesCrm\Models\Activity;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Document;
use Sellinnate\SalesCrm\Models\Email;
use Sellinnate\SalesCrm\Models\Note;
use Sellinnate\SalesCrm\Models\Person;
use Sellinnate\SalesCrm\Models\Stage;
use Sellinnate\SalesCrm\Services\DealBoard;
use Sellinnate\SalesCrm\Services\DealTimeline;
use Sellinnate\SalesCrm\Services\Reports;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The fluent PHP API of the CRM, behind the `SalesCrm` facade. Every write goes through the same
 * actions as the UI, the JSON API and the MCP tools: validation, authorization (when a user is
 * given), history, locks and events included.
 */
class SalesCrm
{
    public function __construct(private Container $container) {}

    /**
     * The CRM of a workspace (a model of your application), or of the shared CRM with null.
     */
    public function for(Model|Workspace|null $workspace): CrmScope
    {
        return new CrmScope(Workspace::of($workspace));
    }

    /**
     * The shared CRM of an application without workspaces.
     */
    public function shared(): CrmScope
    {
        return new CrmScope(null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDeal(Deal $deal, array $data, Authenticatable $by): Deal
    {
        return $this->container->make(UpdateDeal::class)->handle($deal, $data, $by);
    }

    /**
     * Move a deal to a stage of its pipeline; `$fill` completes the fields the stage requires.
     *
     * @param  array<string, mixed>  $fill
     */
    public function moveDeal(Deal $deal, Stage|int $stage, Authenticatable $by, array $fill = [], ?int $afterId = null): Deal
    {
        $stage = $stage instanceof Stage ? $stage : Stage::query()->findOrFail($stage);

        return $this->container->make(MoveDeal::class)->handle($deal, $stage, $by, $fill, $afterId);
    }

    public function deleteDeal(Deal $deal, Authenticatable $by): void
    {
        $this->container->make(DeleteDeal::class)->handle($deal, $by);
    }

    /**
     * Add a person (an existing `person_id` or the details of one to match or create) to a deal.
     *
     * @param  array<string, mixed>  $data
     */
    public function addPerson(Deal $deal, array $data, ?Authenticatable $by = null): Person
    {
        return $this->container->make(ManageDealPeople::class)->attach($deal, $data, $by);
    }

    public function addNote(Deal|Company|Person $target, string $body, Authenticatable $by, bool $pinned = false): Note
    {
        return $this->container->make(ManageNotes::class)->add($target, $body, $by, $pinned);
    }

    /**
     * Plan an activity: type, title, due_date (Y-m-d), due_time (H:i), remind_minutes, assigned_to, notes.
     *
     * @param  array<string, mixed>  $data
     */
    public function scheduleActivity(Deal|Company|Person $target, array $data, ?Authenticatable $by = null): Activity
    {
        return $this->container->make(ManageActivities::class)->save($target, $data, $by);
    }

    public function completeActivity(Activity $activity, ?string $outcome, Authenticatable $by): Activity
    {
        return $this->container->make(ManageActivities::class)->complete($activity, $outcome, $by);
    }

    /**
     * Send an email from a deal: to, cc, subject, body (template variables allowed), reply_to_id.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendEmail(Deal $deal, array $data, Authenticatable $by): Email
    {
        return $this->container->make(SendEmail::class)->handle($deal, $data, $by);
    }

    /**
     * @param  array{kind?: string|null, document_date?: string|null, valid_until?: string|null}  $data
     */
    public function uploadDocument(Deal|Company $target, UploadedFile $file, array $data, Authenticatable $by): Document
    {
        return $this->container->make(ManageDocuments::class)->upload($target, $file, $data, $by);
    }

    public function access(): Access
    {
        return $this->container->make(Access::class);
    }

    public function board(): DealBoard
    {
        return $this->container->make(DealBoard::class);
    }

    public function reports(): Reports
    {
        return $this->container->make(Reports::class);
    }

    public function timeline(): DealTimeline
    {
        return $this->container->make(DealTimeline::class);
    }

    public function inbound(): Inbound
    {
        return $this->container->make(Inbound::class);
    }
}
