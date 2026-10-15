<?php

namespace Sellinnate\SalesCrm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Sellinnate\SalesCrm\Enums\DealEventType;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * An entry of the history of a deal: its creation, a change of a tracked field or of stage, won,
 * lost and reopened. Values are stored as shown, so the timeline reads them even after the owner or
 * the company is gone.
 *
 * @property int $id
 * @property int $deal_id
 * @property int|string|null $user_id
 * @property DealEventType $type
 * @property string|null $field
 * @property mixed $from
 * @property mixed $to
 * @property CarbonImmutable|null $created_at
 * @property-read Deal $deal
 * @property-read Model|null $user
 */
class DealEvent extends CrmModel
{
    public const UPDATED_AT = null;

    protected string $baseTable = 'deal_events';

    protected $fillable = ['deal_id', 'user_id', 'type', 'field', 'from', 'to', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deal_id' => 'integer',
            'type' => DealEventType::class,
            'from' => 'json',
            'to' => 'json',
            'created_at' => 'immutable_datetime',
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
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Crm::userModel(), 'user_id');
    }
}
