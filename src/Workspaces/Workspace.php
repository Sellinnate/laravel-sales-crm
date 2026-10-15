<?php

namespace Sellinnate\SalesCrm\Workspaces;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Stringable;

/**
 * The workspace a CRM record belongs to: the morph type and the key of a model of the host
 * application (a Team, a Company, a Client…). The shared CRM of an application without workspaces
 * is `null`, never a Workspace.
 */
final class Workspace implements Stringable
{
    private ?Model $model = null;

    private bool $resolved = false;

    public function __construct(public readonly string $type, public readonly int|string $id) {}

    /**
     * The workspace of a model of the host, or of the given workspace.
     */
    public static function of(Model|self|null $workspace): ?self
    {
        if ($workspace === null || $workspace instanceof self) {
            return $workspace;
        }

        $key = $workspace->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return null;
        }

        $instance = new self($workspace->getMorphClass(), $key);
        $instance->model = $workspace;
        $instance->resolved = true;

        return $instance;
    }

    /**
     * The workspace a CRM record belongs to, read from its `workspace_type` and `workspace_id`.
     */
    public static function fromRecord(Model $record): ?self
    {
        $type = $record->getAttribute('workspace_type');
        $id = $record->getAttribute('workspace_id');

        return is_string($type) && $type !== '' && (is_int($id) || (is_string($id) && $id !== '')) ? new self($type, $id) : null;
    }

    /**
     * A workspace from the key the UI uses ({@see self::key()}).
     */
    public static function fromKey(?string $key): ?self
    {
        if ($key === null || ! str_contains($key, '|')) {
            return null;
        }

        [$type, $id] = explode('|', $key, 2);

        if ($type === '' || $id === '') {
            return null;
        }

        return new self($type, ctype_digit($id) ? (int) $id : $id);
    }

    /**
     * The key of the workspace for forms and URLs: "type|id".
     */
    public function key(): string
    {
        return $this->type.'|'.$this->id;
    }

    /**
     * Whether both are the same workspace (or both the shared CRM).
     */
    public static function same(?self $a, ?self $b): bool
    {
        return $a === null || $b === null ? $a === $b : $a->type === $b->type && (string) $a->id === (string) $b->id;
    }

    /**
     * The model of the host, when it still exists.
     */
    public function model(): ?Model
    {
        if (! $this->resolved) {
            $this->resolved = true;
            $class = Relation::getMorphedModel($this->type) ?? $this->type;

            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                $this->model = $class::query()->find($this->id);
            }
        }

        return $this->model;
    }

    /**
     * The columns of a record of this workspace.
     *
     * @return array{workspace_type: string|null, workspace_id: int|string|null}
     */
    public static function columns(?self $workspace): array
    {
        return ['workspace_type' => $workspace?->type, 'workspace_id' => $workspace?->id];
    }

    public function __toString(): string
    {
        return $this->key();
    }
}
