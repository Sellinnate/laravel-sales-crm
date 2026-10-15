<?php

namespace Sellinnate\SalesCrm\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Stage;

/**
 * The bulk actions of the list: move to a stage, change the owner or the priority, delete. Every deal
 * goes through its own action, so its rules hold one by one: the deals that cannot take the change
 * (a stage requiring fields they lack, a deal the user may not delete) stay as they are and are
 * reported with the reason.
 */
class BulkUpdateDeals
{
    public const ACTIONS = ['stage', 'owner', 'priority', 'delete'];

    public function __construct(private MoveDeal $move, private UpdateDeal $update, private DeleteDeal $delete) {}

    /**
     * @param  list<int>  $dealIds
     * @return array{done: int, skipped: list<array{id: int, title: string, reason: string}>}
     *
     * @throws ValidationException
     */
    public function handle(array $dealIds, string $action, mixed $value, Authenticatable $by): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw ValidationException::withMessages(['action' => __('sales-crm::validation.bulk_action')]);
        }

        $deals = Deal::query()->visibleTo($by)->whereIn('id', array_map(intval(...), $dealIds))->with('stage')->get();
        $done = 0;
        $skipped = [];

        foreach ($deals as $deal) {
            try {
                match ($action) {
                    'stage' => $this->moveTo($deal, $value, $by),
                    'owner' => $this->update->handle($deal, ['owner_id' => $value === '' ? null : $value], $by),
                    'priority' => $this->update->handle($deal, ['priority' => $value === '' ? null : $value], $by),
                    'delete' => $this->delete->handle($deal, $by),
                };
                $done++;
            } catch (ValidationException $exception) {
                $skipped[] = ['id' => $deal->id, 'title' => $deal->title, 'reason' => (string) collect($exception->errors())->flatten()->first()];
            } catch (AuthorizationException) {
                $skipped[] = ['id' => $deal->id, 'title' => $deal->title, 'reason' => (string) __('sales-crm::validation.not_allowed')];
            }
        }

        return ['done' => $done, 'skipped' => $skipped];
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function moveTo(Deal $deal, mixed $stageId, Authenticatable $by): void
    {
        $stage = Stage::query()->where('pipeline_id', $deal->pipeline_id)->find(is_numeric($stageId) ? (int) $stageId : 0);

        if ($stage === null) {
            throw ValidationException::withMessages(['stage_id' => __('sales-crm::validation.stage_pipeline')]);
        }

        Gate::forUser($by)->authorize('update', $deal);
        $this->move->handle($deal, $stage, $by);
    }
}
