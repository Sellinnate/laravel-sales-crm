<?php

namespace Sellinnate\SalesCrm\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Events\ActivityDue;
use Sellinnate\SalesCrm\Models\Activity;
use Throwable;

/**
 * The reminders of the activities: every open activity with a reminder not sent yet whose moment has
 * come fires ActivityDue once (and, by default, notifies its assignee). A reminder older than
 * `sales-crm.activities.stale_minutes` is not sent any more: the agenda shows the activity.
 * Schedule it every minute.
 */
class RemindActivitiesCommand extends Command
{
    protected $signature = 'sales-crm:remind-activities';

    protected $description = 'Send the reminders of the CRM activities that are due';

    public function handle(Access $access): int
    {
        $now = CarbonImmutable::now();
        $stale = $now->subMinutes(max(1, (int) config('sales-crm.activities.stale_minutes', 60)));
        $sent = 0;

        Activity::query()
            ->whereNull('done_at')
            ->whereNull('reminded_at')
            ->whereNotNull('remind_minutes')
            ->whereNotNull('assigned_to')
            ->where('due_at', '<=', $now->addDays(2))
            ->with(['assignee', 'deal'])
            ->lazyById()
            ->each(function (Activity $activity) use ($now, $stale, $access, &$sent): void {
                $at = $activity->remindAt();

                if ($at === null || $at->gt($now)) {
                    return;
                }

                // Claimed by this run only: a run that crosses it finds the reminder taken.
                if (Activity::query()->whereKey($activity->id)->whereNull('reminded_at')->update(['reminded_at' => $now]) === 0) {
                    return;
                }

                $assignee = $activity->assignee;

                if ($at->lt($stale) || ! $assignee instanceof Authenticatable || ! $access->worksOn($assignee, $activity)) {
                    return;
                }

                try {
                    ActivityDue::dispatch($activity);
                    $sent++;
                } catch (Throwable $exception) {
                    // Not delivered: the next run tries again.
                    Activity::query()->whereKey($activity->id)->update(['reminded_at' => null]);
                    report($exception);
                }
            });

        $this->components->info($sent.' reminder(s) sent.');

        return self::SUCCESS;
    }
}
