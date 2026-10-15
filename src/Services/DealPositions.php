<?php

namespace Sellinnate\SalesCrm\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Person;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Support\Locks;

/**
 * The order of the deals in a column of the board: whole positions per stage, renumbered when a deal
 * is placed, under one lock per pipeline so two people moving cards at once never lose the order.
 */
class DealPositions
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function locked(int $pipelineId, Closure $callback): mixed
    {
        return Locks::pipeline($pipelineId, $callback);
    }

    /**
     * Put a new deal at the bottom of its column: one write, the others untouched (imports).
     */
    public function append(Deal $deal): void
    {
        $position = (int) Deal::query()->where('stage_id', $deal->stage_id)->whereKeyNot($deal->getKey())->max('position') + 1;

        Deal::query()->whereKey($deal->getKey())->update(['position' => $position]);
        $deal->setAttribute('position', $position);
        $deal->syncOriginalAttribute('position');
    }

    /**
     * Put the saved deal in the column of its stage right after another deal of the column, or at the
     * top when there is none (or it left the column meanwhile), and renumber the column.
     */
    public function place(Deal $deal, ?int $afterId = null): void
    {
        $positions = Deal::query()
            ->where('stage_id', $deal->stage_id)
            ->whereKeyNot($deal->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('position', 'id')
            ->map(fn (mixed $position): int => (int) $position)
            ->all();

        $ids = array_map(intval(...), array_keys($positions));
        $index = $afterId === null ? false : array_search($afterId, $ids, true);
        array_splice($ids, $index === false ? 0 : $index + 1, 0, [(int) $deal->getKey()]);
        $positions[(int) $deal->getKey()] = -1;

        $changed = [];

        foreach ($ids as $position => $id) {
            if ($positions[$id] !== $position) {
                $changed[$id] = $position;
            }
        }

        $table = Crm::table('deals');

        foreach (array_chunk($changed, 200, true) as $chunk) {
            // Integer literals, not bindings: PostgreSQL reads bound values as text, and the cast back
            // to the column type is spelled differently on every driver.
            $cases = implode(' ', array_map(fn (int $id, int $position): string => 'WHEN '.$id.' THEN '.$position, array_keys($chunk), $chunk));

            Crm::db()->update(
                'UPDATE '.Crm::db()->getQueryGrammar()->wrapTable($table).' SET position = CASE id '.$cases.' END WHERE id IN ('.implode(',', array_fill(0, count($chunk), '?')).')',
                array_keys($chunk),
            );
        }

        $deal->setAttribute('position', (int) array_search((int) $deal->getKey(), $ids, true));
        $deal->syncOriginalAttribute('position');
    }

    /**
     * Mark the board of the pipeline as changed, so the people looking at it elsewhere redraw it.
     */
    public function touch(int $pipelineId): void
    {
        Pipeline::query()->whereKey($pipelineId)->update(['revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
    }

    /**
     * The boards showing the deals of a company or a person redraw when it changes: the cards show
     * its name and contacts.
     */
    public function touchDealsOf(Company|Person $record): void
    {
        $deals = $record instanceof Company
            ? Deal::query()->where('company_id', $record->id)
            : Deal::query()->whereIn('id', Crm::db()->table(Crm::table('deal_person'))->select('deal_id')->where('person_id', $record->id));

        foreach ($deals->distinct()->pluck('pipeline_id') as $pipelineId) {
            $this->touch((int) $pipelineId);
        }
    }
}
