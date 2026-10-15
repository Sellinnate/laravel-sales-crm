<?php

namespace Sellinnate\SalesCrm\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Enums\DealEventType;
use Sellinnate\SalesCrm\Enums\RequiredField;
use Sellinnate\SalesCrm\Enums\StageKind;
use Sellinnate\SalesCrm\Events\ApprovalRequested;
use Sellinnate\SalesCrm\Events\DealLost;
use Sellinnate\SalesCrm\Events\DealReopened;
use Sellinnate\SalesCrm\Events\DealStageChanged;
use Sellinnate\SalesCrm\Events\DealWon;
use Sellinnate\SalesCrm\Exceptions\MissingRequirements;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Stage;
use Sellinnate\SalesCrm\Services\DealCompletions;
use Sellinnate\SalesCrm\Services\DealFields;
use Sellinnate\SalesCrm\Services\DealHistory;
use Sellinnate\SalesCrm\Services\DealPositions;
use Sellinnate\SalesCrm\Services\DealRequirements;
use Sellinnate\SalesCrm\Services\Matcher;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * Move a deal to a stage of its pipeline, or within its column: drag and drop, keyboard, bulk actions,
 * JSON API and MCP. The fields the stage requires are checked here too and may arrive with the move
 * (`$fill`, the mini form); a lost stage needs the reason. Under the lock of the pipeline the deal is
 * placed after `$afterId` (at the top when null) and its column renumbered; a new stage writes the
 * visits, the history, the dates of won and lost and runs the automation of the stage.
 */
class MoveDeal
{
    public function __construct(
        private DealFields $fields,
        private DealRequirements $requirements,
        private DealHistory $history,
        private DealPositions $positions,
        private DealCompletions $completions,
        private Matcher $matcher,
    ) {}

    /**
     * @param  array<string, mixed>  $fill  the missing fields completed with the move (deal fields, `person`, `next_activity`, `offer_document`)
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Deal $deal, Stage $stage, Authenticatable $by, array $fill = [], ?int $afterId = null): Deal
    {
        Gate::forUser($by)->authorize('update', $deal);

        if ($stage->pipeline_id !== $deal->pipeline_id) {
            throw ValidationException::withMessages(['stage_id' => __('sales-crm::validation.stage_pipeline')]);
        }

        $completions = DealCompletions::extract($fill);
        // Only what a stage may ask for (and the reason of a loss): any other change goes through
        // UpdateDeal, with its rules and events (an approval decided, for one).
        $fill = array_intersect_key($fill, array_flip(DealRequirements::fillKeys(RequiredField::cases(), true)));
        $validated = $this->fields->validate($deal->workspaceRef(), $fill, $deal);

        $move = fn (): Deal => $this->positions->locked($deal->pipeline_id, fn (): Deal => Crm::db()->transaction(function () use ($deal, $stage, $by, $validated, $completions, $afterId): Deal {
            /** @var Deal $locked */
            $locked = Deal::query()->with('stage')->lockForUpdate()->findOrFail($deal->getKey());
            // The stage as it is now, under the lock of the board: its kind and requirements may have changed.
            $stage = Stage::query()->whereKey($stage->id)->where('pipeline_id', $locked->pipeline_id)->firstOrFail();
            $from = $locked->stage;
            $before = $locked->getOriginal();
            $changesStage = $from->id !== $stage->id;

            $this->fields->fill($locked, $validated, $by);
            $this->fields->check($locked, $by);

            if ($changesStage) {
                // The person, the activity and the quote completed with the move are written once the deal is saved.
                $satisfied = DealCompletions::satisfies($completions);
                $missing = array_values(array_filter(
                    $this->requirements->missing($locked, $stage),
                    fn (RequiredField $field): bool => ! in_array($field, $satisfied, true),
                ));
                $lostReason = $this->requirements->needsLostReason($locked, $stage);

                if ($missing !== [] || $lostReason) {
                    throw MissingRequirements::exception($stage, $missing, $lostReason);
                }
            }

            $now = CarbonImmutable::now();

            if ($changesStage) {
                $locked->forceFill([
                    'stage_id' => $stage->id,
                    'stage_entered_at' => $now,
                    'last_activity_at' => $now,
                    'won_at' => $stage->kind === StageKind::Won ? ($from->kind === StageKind::Won ? $locked->won_at : $now) : null,
                    'lost_at' => $stage->kind === StageKind::Lost ? ($from->kind === StageKind::Lost ? $locked->lost_at : $now) : null,
                ]);

                if ($stage->kind !== StageKind::Lost) {
                    $locked->forceFill(['lost_reason' => null, 'lost_note' => null]);
                }
            }

            if ($locked->isDirty()) {
                $askApproval = UpdateDeal::asksApproval($locked, $by);
                $locked->updated_by = Crm::userKey($by);
                $locked->save();
                $this->history->updated($locked, $before, $by);

                if ($askApproval) {
                    ApprovalRequested::dispatch($locked, $by);
                }
            }

            // The cards of the column first, the row of the pipeline last (touch(), also in the
            // completions): in the order of the other changes of a card, so they never deadlock.
            $locked->setRelation('stage', $stage);
            $this->positions->place($locked, $afterId);

            $this->completions->write($locked, $completions, $by, $validated['offer_valid_until'] ?? $locked->offer_valid_until?->toDateString());
            $this->positions->touch($locked->pipeline_id);

            if ($changesStage) {
                $outcome = $this->history->moved($locked, $from, $stage, $by, $now);
                $this->completions->onEnter($locked, $stage);
            }

            // The next activity and the last contact were written on another instance (the activities).
            $locked->refresh();

            if ($changesStage) {
                DealStageChanged::dispatch($locked, $from, $stage, $by);

                match ($outcome) {
                    DealEventType::Won => DealWon::dispatch($locked, $by),
                    DealEventType::Lost => DealLost::dispatch($locked, $by),
                    DealEventType::Reopened => DealReopened::dispatch($locked, $by),
                    default => null,
                };
            }

            return $locked;
        }));

        // A company or a person created with the move: the lock of the deduplication first, until the commit.
        return $completions['person'] !== [] || filled($validated['company_name'] ?? null) ? $this->matcher->locked($deal->workspaceRef(), $move) : $move();
    }
}
