<?php

namespace Sellinnate\SalesCrm\Workspaces;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use Sellinnate\SalesCrm\Contracts\WorkspaceResolver;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * Workspaces read from the relations of the host models, configured in `sales-crm.workspaces`:
 *
 *     'user_relation' => 'teams',      // $user->teams: the workspaces of a user
 *     'members_relation' => 'users',   // $team->users(): the users of a workspace
 *
 * The user relation may be any relation, a method returning a collection or a single model
 * (a `belongsTo` such as `$user->company`).
 */
class RelationWorkspaceResolver implements WorkspaceResolver
{
    use QueriesMembers;

    /**
     * The workspaces already read in this request, by user key.
     *
     * @var array<string, list<Workspace>>
     */
    private array $memo = [];

    public function enabled(): bool
    {
        return true;
    }

    public function workspacesFor(Authenticatable $user): array
    {
        $key = (string) Crm::userKey($user);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $relation = (string) config('sales-crm.workspaces.user_relation');

        if ($relation === '' || ! $user instanceof Model) {
            throw new InvalidArgumentException('sales-crm: set `sales-crm.workspaces.user_relation` to the relation of your user model that returns their workspaces.');
        }

        $value = method_exists($user, $relation) ? $user->{$relation}() : $user->getAttribute($relation);
        $value = $value instanceof Relation || $value instanceof Builder ? $value->get() : $value;
        $models = match (true) {
            $value instanceof Model => [$value],
            $value instanceof EloquentCollection, is_iterable($value) => collect($value)->filter(fn (mixed $item): bool => $item instanceof Model)->values()->all(),
            default => [],
        };

        return $this->memo[$key] = array_values(array_filter(array_map(fn (Model $model): ?Workspace => Workspace::of($model), $models)));
    }

    public function allows(Authenticatable $user, ?Workspace $workspace): bool
    {
        if ($workspace === null) {
            return false;
        }

        foreach ($this->workspacesFor($user) as $mine) {
            if (Workspace::same($mine, $workspace)) {
                return true;
            }
        }

        return false;
    }

    public function constrain(Builder|QueryBuilder $query, Authenticatable $user, string $typeColumn, string $idColumn): void
    {
        $workspaces = $this->workspacesFor($user);

        if ($workspaces === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $byType = [];

        foreach ($workspaces as $workspace) {
            $byType[$workspace->type][] = $workspace->id;
        }

        $query->where(function ($inner) use ($byType, $typeColumn, $idColumn): void {
            foreach ($byType as $type => $ids) {
                $inner->orWhere(fn ($one) => $one->where($typeColumn, $type)->whereIn($idColumn, $ids));
            }
        });
    }

    public function members(?Workspace $workspace): Builder
    {
        $query = $this->usersQuery();
        $model = $workspace?->model();
        $relation = (string) config('sales-crm.workspaces.members_relation');

        if ($model === null || $relation === '' || ! method_exists($model, $relation)) {
            return $query->whereRaw('1 = 0');
        }

        $members = $model->{$relation}();

        if (! $members instanceof Relation) {
            return $query->whereRaw('1 = 0');
        }

        $userModel = $query->getModel();

        return $query->whereIn($userModel->getQualifiedKeyName(), $members->getQuery()->toBase()->select($userModel->getQualifiedKeyName()));
    }

    public function label(?Workspace $workspace): string
    {
        $model = $workspace?->model();
        $attribute = (string) config('sales-crm.workspaces.label_attribute', 'name');
        $label = $model?->getAttribute($attribute);

        return is_scalar($label) && (string) $label !== '' ? (string) $label : ($workspace === null ? '' : '#'.$workspace->id);
    }
}
