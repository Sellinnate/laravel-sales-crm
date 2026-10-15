<?php

namespace Sellinnate\SalesCrm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stay of a deal in a stage, from entering to leaving it: the time spent per stage of the reports.
 *
 * @property int $id
 * @property int $deal_id
 * @property int $pipeline_id
 * @property int|null $stage_id
 * @property string|null $stage_name
 * @property CarbonImmutable $entered_at
 * @property CarbonImmutable|null $left_at
 * @property int|string|null $entered_by
 * @property-read Deal $deal
 * @property-read Stage|null $stage
 */
class StageVisit extends CrmModel
{
    public $timestamps = false;

    protected string $baseTable = 'stage_visits';

    protected $fillable = ['deal_id', 'pipeline_id', 'stage_id', 'stage_name', 'entered_at', 'left_at', 'entered_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deal_id' => 'integer',
            'pipeline_id' => 'integer',
            'stage_id' => 'integer',
            'entered_at' => 'immutable_datetime',
            'left_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }

    /**
     * @return BelongsTo<Stage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'stage_id');
    }
}
