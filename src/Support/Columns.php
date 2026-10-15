<?php

namespace Sellinnate\SalesCrm\Support;

use Illuminate\Database\Schema\Blueprint;

/**
 * The columns the CRM migrations share, following the key types configured in
 * `sales-crm.database`: the workspace morph and the references to the users of the host.
 *
 * @internal
 */
final class Columns
{
    /**
     * The nullable workspace morph (`workspace_type`, `workspace_id`) and, with `$key`, the
     * not-null `workspace_key` used by the unique indexes ("" for the shared CRM).
     */
    public static function workspace(Blueprint $table, bool $key = false): void
    {
        $table->string('workspace_type', 191)->nullable();

        match ((string) config('sales-crm.database.workspace_key_type', 'int')) {
            'uuid' => $table->uuid('workspace_id')->nullable(),
            'ulid' => $table->ulid('workspace_id')->nullable(),
            'string' => $table->string('workspace_id', 64)->nullable(),
            default => $table->unsignedBigInteger('workspace_id')->nullable(),
        };

        if ($key) {
            $table->string('workspace_key', 255)->default('');
        }

        $table->index(['workspace_type', 'workspace_id']);
    }

    /**
     * A nullable reference to a user of the host, set to null when the user is deleted.
     */
    public static function user(Blueprint $table, string $column, bool $nullable = true): void
    {
        $definition = match ((string) config('sales-crm.database.user_key_type', 'int')) {
            'uuid' => $table->foreignUuid($column),
            'ulid' => $table->foreignUlid($column),
            default => $table->foreignId($column),
        };

        if ($nullable) {
            $definition->nullable();
        }

        if ((bool) config('sales-crm.database.user_foreign_keys', true)) {
            $foreign = $definition->constrained(Crm::userTable(), Crm::userKeyName());
            $nullable ? $foreign->nullOnDelete() : $foreign->cascadeOnDelete();
        } else {
            $table->index($column);
        }
    }
}
