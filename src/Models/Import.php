<?php

namespace Sellinnate\SalesCrm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Sellinnate\SalesCrm\Enums\ImportStatus;
use Sellinnate\SalesCrm\Models\Concerns\BelongsToWorkspace;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * An import of deals from a CSV or XLSX file: the file, the sheet, the mapping of its columns and
 * stage values, and the report once run.
 *
 * @property int $id
 * @property int|string|null $user_id
 * @property int|null $pipeline_id
 * @property string $original_filename
 * @property string $disk
 * @property string $stored_path
 * @property string|null $sheet
 * @property ImportStatus $status
 * @property array{columns?: array<int|string, string>, stages?: array<string, int|string|null>, default_stage_id?: int|string|null, month_first?: bool}|null $mapping
 * @property int $total_rows
 * @property int $created_count
 * @property int $updated_count
 * @property int $rejected_count
 * @property list<array{row: int, reason: string}>|null $rejected
 * @property list<array{row: int, message: string}>|null $warnings
 * @property string|null $error
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Model|null $user
 * @property-read Pipeline|null $pipeline
 */
class Import extends CrmModel
{
    use BelongsToWorkspace;

    protected string $baseTable = 'imports';

    protected $fillable = [
        'workspace_type', 'workspace_id', 'user_id', 'pipeline_id', 'original_filename', 'disk', 'stored_path', 'sheet', 'status',
        'mapping', 'total_rows', 'created_count', 'updated_count', 'rejected_count', 'rejected', 'warnings', 'error',
        'started_at', 'finished_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'total_rows' => 0,
        'created_count' => 0,
        'updated_count' => 0,
        'rejected_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pipeline_id' => 'integer',
            'status' => ImportStatus::class,
            'mapping' => 'array',
            'total_rows' => 'integer',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'rejected_count' => 'integer',
            'rejected' => 'array',
            'warnings' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Crm::userModel(), 'user_id');
    }

    /**
     * @return BelongsTo<Pipeline, $this>
     */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id');
    }
}
