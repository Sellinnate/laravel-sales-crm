<?php

namespace Sellinnate\SalesCrm\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Reentrant cache locks: a writer that takes a lock around its whole transaction makes the code it
 * calls find the lock already held. A lock must be taken before opening the transaction: asked
 * inside one, a database cache store may abort it on PostgreSQL.
 */
final class Locks
{
    /**
     * The locks this process holds.
     *
     * @var array<string, true>
     */
    private static array $held = [];

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function run(string $name, Closure $callback, int $seconds = 15, int $wait = 10): mixed
    {
        $name = 'sales-crm:'.$name;

        if (isset(self::$held[$name])) {
            return $callback();
        }

        return Cache::lock($name, $seconds)->block($wait, function () use ($name, $callback): mixed {
            self::$held[$name] = true;

            try {
                return $callback();
            } finally {
                unset(self::$held[$name]);
            }
        });
    }

    /**
     * The lock of the board of a pipeline: moves, positions and changes of its stages.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function pipeline(int $pipelineId, Closure $callback): mixed
    {
        return self::run('pipeline:'.$pipelineId, $callback);
    }

    /**
     * The lock of the deduplication of the companies and people of a workspace.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function contacts(string $workspaceKey, Closure $callback): mixed
    {
        return self::run('contacts:'.($workspaceKey === '' ? 'shared' : md5($workspaceKey)), $callback, 30, 15);
    }
}
