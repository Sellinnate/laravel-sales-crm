<?php

namespace Sellinnate\SalesCrm\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\SavedView;
use Sellinnate\SalesCrm\Services\DealBoard;
use Sellinnate\SalesCrm\Services\DealColumns;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The saved views of the deals: the state of the page under a name, for a workspace (or for all the
 * workspaces of the user), personal or shared with whoever works in the workspace. A view with the
 * same name is replaced; only its owner deletes it.
 */
class SaveView
{
    /**
     * The fields of the card chosen as none at all (an empty choice is all of them).
     */
    public const NO_CARD_FIELDS = 'none';

    /**
     * The fields of a card a view may choose (labels: `sales-crm::ui.card_fields.<field>`).
     */
    public const CARD_FIELDS = ['amount', 'owner', 'priority', 'next_activity', 'stage_age', 'signature', 'payment', 'blocker', 'offer', 'contact', 'approval', 'close_date', 'tags'];

    public function __construct(private Access $access) {}

    /**
     * @param  array<string, mixed>  $state
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Authenticatable $by, Model|Workspace|null $workspace, string $name, array $state, bool $shared = false): SavedView
    {
        $workspace = Workspace::of($workspace);

        if (! $this->access->canAccess($by) || ($workspace !== null && ! $this->access->works($by, $workspace))) {
            throw new AuthorizationException;
        }

        $name = trim($name);
        Validator::make(['name' => $name], ['name' => ['required', 'string', 'max:80']], [], ['name' => mb_strtolower((string) __('sales-crm::fields.view.name'))])->validate();

        if ($shared && $workspace === null && $this->access->resolver()->enabled()) {
            throw ValidationException::withMessages(['shared' => __('sales-crm::validation.shared_view_workspace')]);
        }

        $view = SavedView::query()->where('user_id', Crm::userKey($by))->where('workspace_key', $workspace?->key() ?? '')->where('name', $name)->first()
            ?? (new SavedView(['user_id' => Crm::userKey($by), 'name' => $name]))->setWorkspace($workspace);

        $view->fill(['shared' => $shared, 'state' => self::state($state, $workspace)])->save();

        return $view;
    }

    /**
     * @throws AuthorizationException
     */
    public function delete(SavedView $view, Authenticatable $by): void
    {
        if (! Crm::sameUser($view->user_id, Crm::userKey($by))) {
            throw new AuthorizationException;
        }

        $view->delete();
    }

    /**
     * The state kept by a view: the known keys, with values the page understands.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function state(array $state, ?Workspace $workspace): array
    {
        $list = fn (mixed $value, array $allowed): array => array_values(array_intersect(is_array($value) ? array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value) : [], $allowed));
        $text = fn (mixed $value, int $max = 200): string => mb_substr(is_scalar($value) ? trim((string) $value) : '', 0, $max);
        $pipeline = $state['pipeline'] ?? '';

        return [
            'view' => in_array($state['view'] ?? null, ['kanban', 'list'], true) ? $state['view'] : 'kanban',
            'pipeline' => is_scalar($pipeline) && ctype_digit((string) $pipeline) ? (string) $pipeline : '',
            'search' => $text($state['search'] ?? ''),
            'quick' => $list($state['quick'] ?? [], DealBoard::QUICK_FILTERS),
            'owner' => $text($state['owner'] ?? '', 64),
            'priority' => $text($state['priority'] ?? '', 20),
            'source' => $text($state['source'] ?? '', 40),
            'tag' => $text($state['tag'] ?? '', 30),
            'closed' => ($state['closed'] ?? null) === 'all' ? 'all' : 'recent',
            'sort' => array_key_exists($text($state['sort'] ?? ''), DealBoard::SORTS) ? $text($state['sort'] ?? '') : 'updated',
            'direction' => ($state['direction'] ?? null) === 'asc' ? 'asc' : 'desc',
            'columns' => $list($state['columns'] ?? [], array_keys(DealColumns::all($workspace))),
            'card' => $list($state['card'] ?? [], [...self::CARD_FIELDS, self::NO_CARD_FIELDS]),
        ];
    }
}
