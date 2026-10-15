<?php

namespace Sellinnate\SalesCrm\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Enums\ApprovalStatus;
use Sellinnate\SalesCrm\Events\ApprovalDecided;
use Sellinnate\SalesCrm\Events\ApprovalRequested;
use Sellinnate\SalesCrm\Events\DealUpdated;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Services\DealFields;
use Sellinnate\SalesCrm\Services\DealHistory;
use Sellinnate\SalesCrm\Services\DealPositions;
use Sellinnate\SalesCrm\Services\Matcher;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * Change the fields of a deal: the side panel, the inline edit of the list, the bulk actions, the
 * JSON API and the MCP tools. Only the keys given change; the stage changes with {@see MoveDeal}.
 * Signature, payment and approval follow their rules; every tracked change goes to the history.
 */
class UpdateDeal
{
    public function __construct(private DealFields $fields, private DealHistory $history, private DealPositions $positions, private Matcher $matcher) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Deal $deal, array $data, Authenticatable $by): Deal
    {
        Gate::forUser($by)->authorize('update', $deal);

        $data = array_diff_key($data, array_flip(['pipeline_id', 'stage_id', 'workspace_type', 'workspace_id', 'origin_type', 'origin_id']));
        $validated = $this->fields->validate($deal->workspaceRef(), $data, $deal);

        if ($validated === []) {
            return $deal;
        }

        $update = fn (): Deal => Crm::db()->transaction(function () use ($deal, $validated, $by): Deal {
            /** @var Deal $locked */
            $locked = Deal::query()->lockForUpdate()->findOrFail($deal->getKey());
            $before = $locked->getOriginal();

            $this->fields->fill($locked, $validated, $by);
            $this->fields->check($locked, $by);

            if (! $locked->isDirty()) {
                return $locked;
            }

            $locked->updated_by = Crm::userKey($by);
            $askApproval = self::asksApproval($locked, $by);
            $decided = $locked->isDirty('approval_status') && $locked->approval_status?->isDecided() === true;
            $locked->save();

            $this->history->updated($locked, $before, $by);
            $this->positions->touch($locked->pipeline_id);

            if ($askApproval) {
                ApprovalRequested::dispatch($locked, $by);
            }

            if ($decided) {
                ApprovalDecided::dispatch($locked, $by);
            }

            DealUpdated::dispatch($locked, array_values(array_diff(array_keys($locked->getChanges()), ['updated_at', 'updated_by', 'first_year_value_cents'])), $by);

            return $locked;
        });

        // A company typed by name: the lock of the deduplication until the commit.
        return filled($validated['company_name'] ?? null) ? $this->matcher->locked($deal->workspaceRef(), $update) : $update();
    }

    /**
     * Whether the change asks the approval to an approver other than the author.
     */
    public static function asksApproval(Deal $deal, ?Authenticatable $by): bool
    {
        return $deal->approval_status === ApprovalStatus::Pending && $deal->approver_id !== null
            && ! Crm::sameUser($deal->approver_id, Crm::userKey($by))
            && ($deal->isDirty('approver_id') || $deal->isDirty('approval_required') || $deal->isDirty('approval_status'));
    }
}
