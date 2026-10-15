<?php

namespace Sellinnate\SalesCrm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Sellinnate\SalesCrm\Database\Factories\StageFactory;
use Sellinnate\SalesCrm\Enums\RequiredField;
use Sellinnate\SalesCrm\Enums\StageColor;
use Sellinnate\SalesCrm\Enums\StageKind;

/**
 * A stage of a pipeline: a column of the board, with its probability, its kind (open, won, lost),
 * the days after which an idle deal is stale, the fields required to enter it and the activity it
 * creates on entering.
 *
 * @property int $id
 * @property int $pipeline_id
 * @property string $name
 * @property int $position
 * @property StageColor $color
 * @property int $probability
 * @property StageKind $kind
 * @property int|null $rotting_days
 * @property list<string>|null $required_fields
 * @property array{type?: string, title?: string, due_in_days?: int}|null $on_enter_activity
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Pipeline $pipeline
 * @property-read Collection<int, Deal> $deals
 */
class Stage extends CrmModel
{
    /** @use HasFactory<StageFactory> */
    use HasFactory;

    protected string $baseTable = 'stages';

    protected $fillable = ['pipeline_id', 'name', 'position', 'color', 'probability', 'kind', 'rotting_days', 'required_fields', 'on_enter_activity'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'color' => 'neutral',
        'probability' => 0,
        'kind' => 'open',
        'position' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pipeline_id' => 'integer',
            'position' => 'integer',
            'color' => StageColor::class,
            'probability' => 'integer',
            'kind' => StageKind::class,
            'rotting_days' => 'integer',
            'required_fields' => 'array',
            'on_enter_activity' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): StageFactory
    {
        return StageFactory::new();
    }

    /**
     * @return BelongsTo<Pipeline, $this>
     */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id');
    }

    /**
     * @return HasMany<Deal, $this>
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'stage_id');
    }

    /**
     * The fields a deal needs to enter the stage, in the order of the catalogue.
     *
     * @return list<RequiredField>
     */
    public function requiredFields(): array
    {
        $values = $this->required_fields ?? [];

        return array_values(array_filter(RequiredField::cases(), fn (RequiredField $field): bool => in_array($field->value, $values, true)));
    }

    public function isOpen(): bool
    {
        return $this->kind === StageKind::Open;
    }
}
