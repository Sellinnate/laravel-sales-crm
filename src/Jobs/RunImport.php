<?php

namespace Sellinnate\SalesCrm\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Sellinnate\SalesCrm\Enums\ImportStatus;
use Sellinnate\SalesCrm\Events\ImportFinished;
use Sellinnate\SalesCrm\Models\Import;
use Sellinnate\SalesCrm\Services\Import\Importer;
use Sellinnate\SalesCrm\Support\Crm;
use Throwable;

/**
 * Runs a mapped import in the queue, once, as the user who started it.
 */
class RunImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $importId, public int|string|null $userId)
    {
        $queue = config('sales-crm.import.queue');

        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }

        $this->afterCommit();
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('sales-crm-import-'.$this->importId))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(Importer $importer): void
    {
        $import = Import::query()->find($this->importId);

        if ($import === null || $import->status !== ImportStatus::Mapped) {
            return;
        }

        $user = $this->userId === null ? null : Crm::userModel()::query()->find($this->userId);

        if (! $user instanceof Authenticatable) {
            $import->forceFill(['status' => ImportStatus::Failed, 'error' => (string) __('sales-crm::import.user_gone')])->save();
            ImportFinished::dispatch($import);

            return;
        }

        $importer->run($import, $user);
    }

    public function failed(?Throwable $exception): void
    {
        $import = Import::query()->whereKey($this->importId)->whereIn('status', [ImportStatus::Mapped->value, ImportStatus::Running->value])->first();

        if ($import !== null) {
            $import->forceFill(['status' => ImportStatus::Failed, 'error' => (string) __('sales-crm::import.stopped')])->save();
            ImportFinished::dispatch($import);
        }
    }
}
