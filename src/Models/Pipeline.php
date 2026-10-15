<?php

namespace Sellinnate\SalesCrm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Sellinnate\SalesCrm\Database\Factories\PipelineFactory;
use Sellinnate\SalesCrm\Models\Concerns\BelongsToWorkspace;

/**
 * A sales pipeline of a workspace: its stages in order, from the first open one to won and lost.
 *
 * @property int $id
 * @property string $name
 * @property int $position
 * @property bool $is_active
 * @property int $revision
 * @property int|string|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Stage> $stages
 * @property-read Collection<int, Deal> $deals
 */
class Pipeline extends CrmModel
{
    use BelongsToWorkspace;

    /** @use HasFactory<PipelineFactory> */
    use HasFactory;

    protected string $baseTable = 'pipelines';

    protected $fillable = ['workspace_type', 'workspace_id', 'name', 'position', 'is_active', 'created_by'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'position' => 0,
        'revision' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
            'revision' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): PipelineFactory
    {
        return PipelineFactory::new();
    }

    /**
     * The stages, in the order of the board.
     *
     * @return HasMany<Stage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class, 'pipeline_id')->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<Deal, $this>
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'pipeline_id');
    }

    /**
     * The first open stage: where a new deal lands unless told otherwise.
     */
    public function firstOpenStage(): ?Stage
    {
        return $this->stages->first(fn (Stage $stage): bool => ! $stage->kind->isClosed());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_active'), true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc($this->qualifyColumn('is_active'))->orderBy($this->qualifyColumn('position'))->orderBy($this->qualifyColumn('id'));
    }
}
