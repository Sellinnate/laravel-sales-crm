<?php

namespace Sellinnate\SalesCrm\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Internal helpers that read the package configuration: table names, the user model and how users
 * are shown. Not part of the public API.
 *
 * @internal
 */
final class Crm
{
    /**
     * The full name of a CRM table, with the configured prefix.
     */
    public static function table(string $name): string
    {
        return self::prefix().$name;
    }

    public static function prefix(): string
    {
        $prefix = config('sales-crm.database.table_prefix', 'crm_');

        return is_string($prefix) ? $prefix : 'crm_';
    }

    public static function connection(): ?string
    {
        $connection = config('sales-crm.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /**
     * The database connection of the CRM tables.
     */
    public static function db(): Connection
    {
        /** @var Connection $connection */
        $connection = DB::connection(self::connection());

        return $connection;
    }

    /**
     * A raw SQL fragment built from the configured table names (never from user input).
     */
    public static function raw(string $sql): ExpressionContract
    {
        // The SQL is assembled from configured table and column names, wrapped by the grammar.
        return new Expression($sql); // @phpstan-ignore argument.type
    }

    /**
     * Run the callback if the current transaction rolls back (Laravel 12+; a no-op on Laravel 11).
     */
    public static function afterRollBack(callable $callback): void
    {
        $connection = self::db();

        // Laravel 11 has no afterRollBack() (and is_callable() is fooled by the macros of the connection).
        if (method_exists($connection, 'afterRollBack')) { // @phpstan-ignore function.alreadyNarrowedType
            $connection->afterRollBack($callback);
        }
    }

    /**
     * The Eloquent model of the users who work the CRM.
     *
     * @return class-string<Model>
     */
    public static function userModel(): string
    {
        $model = config('sales-crm.user_model') ?: config('auth.providers.'.config('auth.guards.'.config('auth.defaults.guard', 'web').'.provider', 'users').'.model')
            ?: config('auth.providers.users.model');

        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            throw new InvalidArgumentException('sales-crm: set `sales-crm.user_model` to the Eloquent model of your users.');
        }

        return $model;
    }

    public static function userTable(): string
    {
        $model = self::userModel();

        return (new $model)->getTable();
    }

    public static function userKeyName(): string
    {
        $model = self::userModel();

        return (new $model)->getKeyName();
    }

    /**
     * The name shown for a user.
     */
    public static function userName(Authenticatable|Model|null $user): string
    {
        if ($user === null) {
            return '';
        }

        $attribute = (string) config('sales-crm.users.name_attribute', 'name');
        $name = $user instanceof Model ? $user->getAttribute($attribute) : ($user->{$attribute} ?? null);

        return is_scalar($name) && (string) $name !== '' ? (string) $name : (string) self::userEmail($user);
    }

    public static function userEmail(Authenticatable|Model|null $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $attribute = (string) config('sales-crm.users.email_attribute', 'email');
        $email = $user instanceof Model ? $user->getAttribute($attribute) : ($user->{$attribute} ?? null);

        return is_string($email) && $email !== '' ? $email : null;
    }

    public static function nameColumn(): string
    {
        return (string) config('sales-crm.users.name_attribute', 'name');
    }

    public static function emailColumn(): string
    {
        return (string) config('sales-crm.users.email_attribute', 'email');
    }

    /**
     * The key of a user as stored in the CRM columns.
     */
    public static function userKey(Authenticatable|Model|null $user): int|string|null
    {
        if ($user === null) {
            return null;
        }

        $key = $user instanceof Model ? $user->getKey() : $user->getAuthIdentifier();

        return is_int($key) || is_string($key) ? $key : null;
    }

    /**
     * Whether two user keys are the same user, whatever their PHP type («5» from a form, 5 from the database).
     */
    public static function sameUser(mixed $a, mixed $b): bool
    {
        return $a !== null && $b !== null && (string) $a === (string) $b;
    }

    /**
     * The first word of a name.
     */
    public static function firstName(string $name): string
    {
        return explode(' ', trim($name))[0];
    }
}
