<?php

namespace Sellinnate\SalesCrm\Services;

use Carbon\CarbonImmutable;
use Sellinnate\SalesCrm\Models\Activity;
use Sellinnate\SalesCrm\Models\Deal;

/**
 * The next and the last activity of a deal, kept on the deal so the board reads them without a query
 * per card: the next one is the first deadline of the open activities (the end of the day for a
 * whole-day one); the last one moves forward with every note, activity done, email and stage change
 * (the "stale" counter starts from it).
 */
class DealActivityState
{
    public function __construct(private DealPositions $positions) {}

    /**
     * Read the next activity again and, when given, move the last activity and the last contact forward.
     */
    public function refresh(int $dealId, ?CarbonImmutable $activityAt = null, ?CarbonImmutable $contactAt = null): void
    {
        // The row of the deal locked first: two changes of its activities read them one after the other.
        $deal = Deal::query()->lockForUpdate()->find($dealId);

        if ($deal === null) {
            return;
        }

        $next = Activity::query()->where('deal_id', $dealId)->whereNull('done_at')->get(['id', 'due_at', 'has_time'])
            ->map(fn (Activity $activity): CarbonImmutable => $activity->deadline()->startOfSecond())
            ->sortBy(fn (CarbonImmutable $deadline): int => $deadline->getTimestamp())
            ->first();

        $attributes = ['next_activity_at' => $next];

        if ($activityAt !== null && ($deal->last_activity_at === null || $activityAt->gt($deal->last_activity_at))) {
            $attributes['last_activity_at'] = $activityAt;
        }

        if ($contactAt !== null && ($deal->last_contacted_at === null || $contactAt->gt($deal->last_contacted_at))) {
            $attributes['last_contacted_at'] = $contactAt;
        }

        $deal->forceFill($attributes);

        if ($deal->isDirty()) {
            $deal->save();
            $this->positions->touch($deal->pipeline_id);
        }
    }
}
