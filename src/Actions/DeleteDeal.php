<?php

namespace Sellinnate\SalesCrm\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Sellinnate\SalesCrm\Events\DealDeleted;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Services\DealPositions;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * Delete a deal with its history, activities, notes, emails and documents (managers only). Company,
 * people and the records of the host stay.
 */
class DeleteDeal
{
    public function __construct(private DealPositions $positions, private ManageDocuments $documents) {}

    /**
     * @throws AuthorizationException
     */
    public function handle(Deal $deal, Authenticatable $by): void
    {
        Gate::forUser($by)->authorize('delete', $deal);

        Crm::db()->transaction(function () use ($deal, $by): void {
            $attributes = $deal->attributesToArray();

            // Locked as an upload locks it: a document uploaded meanwhile is purged too, or waits. Gone
            // already (a double click, two tabs): nothing to delete, nothing to announce again.
            if (Deal::query()->whereKey($deal->id)->lockForUpdate()->first() === null) {
                return;
            }

            $this->documents->purgeWith($deal);
            $deal->delete();
            $this->positions->touch($deal->pipeline_id);

            DealDeleted::dispatch((int) $deal->getKey(), $attributes, $by);
        });
    }
}
