<?php

namespace Sellinnate\SalesCrm\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Sellinnate\SalesCrm\Actions\BulkUpdateDeals;
use Sellinnate\SalesCrm\Actions\CreateDeal;
use Sellinnate\SalesCrm\Actions\MoveDeal;
use Sellinnate\SalesCrm\Actions\SaveView;
use Sellinnate\SalesCrm\Actions\UpdateDeal;
use Sellinnate\SalesCrm\Contracts\WorkspaceResolver;
use Sellinnate\SalesCrm\Enums\ActivityType;
use Sellinnate\SalesCrm\Enums\RequiredField;
use Sellinnate\SalesCrm\Enums\StageKind;
use Sellinnate\SalesCrm\Exceptions\MissingRequirements;
use Sellinnate\SalesCrm\Livewire\Concerns\InteractsWithCrm;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Models\SavedView;
use Sellinnate\SalesCrm\Models\Stage;
use Sellinnate\SalesCrm\Services\CustomFields;
use Sellinnate\SalesCrm\Services\DealBoard;
use Sellinnate\SalesCrm\Services\DealColumns;
use Sellinnate\SalesCrm\Services\DealRequirements;
use Sellinnate\SalesCrm\Services\DefaultPipeline;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The deals: the kanban board of a pipeline with drag and drop between and within the columns, the
 * mini form of the fields a stage requires (and of the reason of a loss), the side panel of a deal,
 * the quick add, the quick filters, the saved views and the list with inline edit, bulk actions and
 * export. The board redraws itself when someone else changes the pipeline.
 *
 * @property-read Pipeline|null $currentPipeline
 * @property-read Collection<int, Pipeline> $pipelines
 * @property-read list<RequiredField> $pendingMissing
 * @property-read bool $draftNeedsReason
 * @property-read bool $pendingNeedsReason
 * @property-read string $pendingStageName
 * @property-read list<array{stage: Stage, deals: Collection<int, Deal>, count: int, value: int, weighted: int, more: int}> $columns
 * @property-read LengthAwarePaginator<int, Deal> $deals
 * @property-read array<string, string> $owners
 * @property-read array<string, string> $workspaces
 * @property-read array<string, string> $tags
 * @property-read Collection<int, SavedView> $savedViews
 * @property-read bool $isEmpty
 * @property-read list<string> $companyNames
 * @property-read list<RequiredField> $draftRequirements
 */
class DealsBoard extends Component
{
    use InteractsWithCrm;
    use WithFileUploads;
    use WithPagination;

    public const VIEWS = ['kanban', 'list'];

    /** The workspace filter: "" for all the workspaces of the user. */
    #[Url(except: '')]
    public string $workspace = '';

    #[Url(except: '')]
    public string $pipeline = '';

    #[Url(except: 'kanban')]
    public string $view = 'kanban';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** @var list<string> */
    #[Url(except: [])]
    public array $quick = [];

    #[Url(except: '')]
    public string $owner = '';

    #[Url(except: '')]
    public string $priority = '';

    #[Url(except: '')]
    public string $source = '';

    #[Url(except: '')]
    public string $tag = '';

    #[Url(except: 'recent')]
    public string $closed = 'recent';

    #[Url(except: 'updated')]
    public string $sort = 'updated';

    #[Url(as: 'dir', except: 'desc')]
    public string $direction = 'desc';

    /** The columns of the list, comma separated (empty: the defaults). */
    #[Url(as: 'columns', except: '')]
    public string $listColumns = '';

    /** The fields of the cards, comma separated (empty: all). */
    #[Url(as: 'card', except: '')]
    public string $cardFields = '';

    #[Url(as: 'deal', except: null)]
    public ?int $openDealId = null;

    public bool $showPanel = false;

    /**
     * Cards shown per column beyond the default, by stage id ("Show more").
     *
     * @var array<int, int>
     */
    public array $limits = [];

    public string $version = '';

    public bool $showCreate = false;

    /** @var array<string, mixed> */
    public array $draft = [];

    public bool $showRequirements = false;

    // Set by the server only (a move refused for its requirements): never by the browser.
    #[Locked]
    public ?int $pendingDeal = null;

    #[Locked]
    public ?int $pendingStage = null;

    #[Locked]
    public ?int $pendingAfter = null;

    /** @var array<string, mixed> */
    public array $fill = [];

    /** @var list<int|string> */
    public array $selected = [];

    public string $bulkAction = '';

    public string $bulkValue = '';

    public bool $showColumns = false;

    /** @var list<string> */
    public array $columnChoice = [];

    public bool $showCardFields = false;

    /** @var list<string> */
    public array $cardChoice = [];

    public bool $showSaveView = false;

    public string $viewName = '';

    public bool $viewShared = false;

    public bool $showShortcuts = false;

    public function mount(): void
    {
        $user = $this->viewer();
        abort_unless(Gate::forUser($user)->allows('viewAny', Deal::class), 403);

        if (! in_array($this->view, self::VIEWS, true)) {
            $this->view = 'kanban';
        }

        if ($this->workspace !== '' && $this->selectedWorkspace() === null) {
            $this->workspace = '';
        }

        if ($this->openDealId !== null) {
            $deal = Deal::query()->find($this->openDealId);
            $this->showPanel = $deal !== null && Gate::forUser($user)->allows('view', $deal);
            $this->openDealId = $this->showPanel ? $this->openDealId : null;
        }

        $this->version = $this->currentVersion();
    }

    public function render(): View
    {
        return $this->page('sales-crm::livewire.deals-board', (string) __('sales-crm::ui.nav.deals'));
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['workspace', 'pipeline', 'search', 'owner', 'priority', 'source', 'closed', 'tag', 'view'], true)) {
            $this->limits = [];
            $this->selected = [];
            $this->resetPage();
        }

        if ($property === 'workspace') {
            $this->pipeline = '';
            $this->owner = '';
            unset($this->currentPipeline, $this->pipelines);
        }

        if ($property === 'pipeline') {
            unset($this->currentPipeline);
        }
    }

    public function updatedShowPanel(bool $open): void
    {
        if (! $open) {
            $this->openDealId = null;
        }
    }

    public function setView(string $view): void
    {
        if (in_array($view, self::VIEWS, true) && $view !== $this->view) {
            $this->view = $view;
            $this->updated('view');
        }
    }

    public function toggleQuick(string $filter): void
    {
        if (! in_array($filter, DealBoard::QUICK_FILTERS, true)) {
            return;
        }

        $this->quick = in_array($filter, $this->quick, true)
            ? array_values(array_diff($this->quick, [$filter]))
            : [...$this->quick, $filter];
        $this->limits = [];
        // Other rows now: a selection of rows no longer shown never reaches a bulk action.
        $this->selected = [];
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'quick', 'owner', 'priority', 'source', 'tag', 'closed');
        $this->limits = [];
        // Other rows now: a selection of rows no longer shown never reaches a bulk action.
        $this->selected = [];
        $this->resetPage();
    }

    public function showMore(int $stageId): void
    {
        $this->limits[$stageId] = ($this->limits[$stageId] ?? DealBoard::perColumn()) + DealBoard::perColumn();
    }

    public function openDeal(int $dealId): void
    {
        $deal = Deal::query()->find($dealId);

        if ($deal === null || ! Gate::forUser($this->viewer())->allows('view', $deal)) {
            return;
        }

        $this->openDealId = $dealId;
        $this->showPanel = true;
    }

    /**
     * The board polls: the columns redraw only when the pipeline changed elsewhere.
     */
    public function refreshIfChanged(): void
    {
        $version = $this->currentVersion();

        if ($version === $this->version) {
            $this->skipRender();

            return;
        }

        $this->version = $version;
        unset($this->columns);
    }

    #[On('crm-close-panel')]
    public function closePanel(): void
    {
        $this->showPanel = false;
        $this->openDealId = null;
    }

    #[On('crm-deal-updated')]
    public function dealUpdated(): void
    {
        unset($this->columns, $this->deals);
        $this->version = $this->currentVersion();
    }

    /**
     * The drop of a card: in another column, or in a new place of its own.
     */
    public function moveDeal(int|string $dealId, int|string $stageId, int|string|null $afterId = null): void
    {
        $deal = Deal::query()->with('stage')->find((int) $dealId);
        $stage = Stage::query()->find((int) $stageId);

        if ($deal === null || $stage === null || $stage->pipeline_id !== $deal->pipeline_id) {
            unset($this->columns);

            return;
        }

        $this->move($deal, $stage, [], $afterId === null || $afterId === '' ? null : (int) $afterId);
    }

    /**
     * The keyboard: `[` and `]` move the card to the previous or next stage.
     */
    public function shiftDeal(int $dealId, int $direction): void
    {
        $deal = Deal::query()->with('pipeline.stages')->find($dealId);

        if ($deal === null) {
            return;
        }

        $stages = $deal->pipeline->stages->values();
        $index = $stages->search(fn (Stage $stage): bool => $stage->id === $deal->stage_id);
        $target = $index === false ? null : $stages->get($index + ($direction < 0 ? -1 : 1));

        if ($target instanceof Stage) {
            $this->move($deal, $target, []);
        }
    }

    /**
     * The "Move to…" menu of a card.
     */
    public function moveTo(int $dealId, int $stageId): void
    {
        $this->moveDeal($dealId, $stageId);
    }

    public function completeMove(): void
    {
        $deal = $this->pendingDeal === null ? null : Deal::query()->visibleTo($this->viewer())->with('stage')->find($this->pendingDeal);
        $stage = $this->pendingStage === null ? null : Stage::query()->find($this->pendingStage);

        if ($deal === null || $stage === null) {
            $this->cancelMove();

            return;
        }

        $keys = DealRequirements::fillKeys($this->pendingMissing, $this->pendingNeedsReason);
        $fill = array_intersect_key($this->fill, array_flip($keys));
        $this->resetErrorBag();

        if ($this->move($deal, $stage, $fill, $this->pendingAfter, fromForm: true)) {
            $this->cancelMove();
        }
    }

    public function cancelMove(): void
    {
        $this->reset('showRequirements', 'pendingDeal', 'pendingStage', 'pendingAfter', 'fill');
        unset($this->columns, $this->pendingMissing, $this->pendingNeedsReason);
    }

    /**
     * @param  array<string, mixed>  $fill
     */
    private function move(Deal $deal, Stage $stage, array $fill, ?int $afterId = null, bool $fromForm = false): bool
    {
        try {
            app(MoveDeal::class)->handle($deal, $stage, $this->viewer(), $fill, $afterId);
        } catch (ValidationException $exception) {
            $missing = MissingRequirements::fieldsOf($exception);
            $needsReason = array_key_exists('lost_reason', $exception->errors());

            if (! $fromForm && ($missing !== [] || $needsReason)) {
                $this->pendingDeal = $deal->id;
                $this->pendingStage = $stage->id;
                $this->pendingAfter = $afterId;
                $this->fill = DealRequirements::fillDefaults($deal, $missing);
                $this->showRequirements = true;
                unset($this->pendingMissing, $this->pendingNeedsReason);
            } elseif ($fromForm) {
                $this->showErrors($exception, 'fill.');
            } else {
                $this->notify((string) collect($exception->errors())->flatten()->first(), 'danger');
            }

            unset($this->columns);

            return false;
        }

        unset($this->columns, $this->deals);
        $this->version = $this->currentVersion();

        return true;
    }

    /**
     * The fields the pending move needs.
     *
     * @return list<RequiredField>
     */
    #[Computed]
    public function pendingMissing(): array
    {
        $deal = $this->pendingDeal === null ? null : Deal::query()->visibleTo($this->viewer())->find($this->pendingDeal);
        $stage = $deal === null || $this->pendingStage === null ? null : Stage::query()->where('pipeline_id', $deal->pipeline_id)->find($this->pendingStage);

        return $deal === null || $stage === null ? [] : app(DealRequirements::class)->missing($deal, $stage);
    }

    #[Computed]
    public function pendingNeedsReason(): bool
    {
        $deal = $this->pendingDeal === null ? null : Deal::query()->visibleTo($this->viewer())->find($this->pendingDeal);
        $stage = $deal === null || $this->pendingStage === null ? null : Stage::query()->where('pipeline_id', $deal->pipeline_id)->find($this->pendingStage);

        return $deal !== null && $stage !== null && app(DealRequirements::class)->needsLostReason($deal, $stage);
    }

    #[Computed]
    public function pendingStageName(): string
    {
        return $this->pendingStage === null ? '' : (string) Stage::query()->whereKey($this->pendingStage)
            ->whereIn('pipeline_id', Pipeline::query()->visibleTo($this->viewer())->select('id'))->value('name');
    }

    public function startCreate(?int $stageId = null): void
    {
        $pipeline = $this->currentPipeline;

        if ($pipeline === null) {
            return;
        }

        $this->resetErrorBag();
        $this->draft = [
            'title' => '',
            'company_name' => '',
            'stage_id' => (string) ($stageId ?? $pipeline->firstOpenStage()->id ?? ''),
            'person' => ['first_name' => '', 'last_name' => '', 'email' => '', 'mobile' => ''],
            'amount_once' => '',
            'recurring' => '',
            'recurring_period' => 'monthly',
            'expected_close_date' => '',
            'owner_id' => (string) (Crm::userKey($this->viewer()) ?? ''),
            'priority' => '',
            'source' => 'other',
            // Shown when the stage asks for it, with what the select displays: written only with a title.
            'next_activity' => ['type' => ActivityType::Call->value, 'title' => '', 'due_date' => CarbonImmutable::tomorrow()->toDateString(), 'due_time' => ''],
        ];
        $this->showCreate = true;
    }

    public function createDeal(): void
    {
        $pipeline = $this->currentPipeline;

        if ($pipeline === null) {
            return;
        }

        $this->resetErrorBag();
        $data = $this->draft;
        $data['pipeline_id'] = $pipeline->id;

        // A reason chosen for a lost stage, then another stage picked: not carried along.
        if (! $this->draftNeedsReason) {
            unset($data['lost_reason'], $data['lost_note']);
        }

        if (! $this->access()->isMember($data['owner_id'] ?? null, $pipeline->workspaceRef())) {
            $data['owner_id'] = null;
        }

        try {
            $deal = app(CreateDeal::class)->handle($pipeline->workspaceRef(), $data, $this->viewer());
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'draft.');

            return;
        }

        $this->showCreate = false;
        $this->draft = [];
        unset($this->columns, $this->deals, $this->isEmpty);
        $this->version = $this->currentVersion();
        $this->notify((string) __('sales-crm::ui.deal_created', ['title' => $deal->title]));
    }

    /**
     * The required fields of the stage chosen in the quick add.
     *
     * @return list<RequiredField>
     */
    #[Computed]
    public function draftRequirements(): array
    {
        $stage = $this->currentPipeline?->stages->firstWhere('id', (int) ($this->draft['stage_id'] ?? 0));

        return $stage instanceof Stage ? $stage->requiredFields() : [];
    }

    /**
     * A deal created straight in a lost stage says why (the quick add shows the field).
     */
    #[Computed]
    public function draftNeedsReason(): bool
    {
        return $this->currentPipeline?->stages->firstWhere('id', (int) ($this->draft['stage_id'] ?? 0))?->kind === StageKind::Lost;
    }

    // ---- The list -------------------------------------------------------------------------

    public function sortBy(string $sort): void
    {
        if (! array_key_exists($sort, DealBoard::SORTS)) {
            return;
        }

        $this->direction = $this->sort === $sort && $this->direction === 'desc' ? 'asc' : 'desc';
        $this->sort = $sort;
        $this->resetPage();
    }

    /**
     * The inline edit of a cell of the list.
     */
    public function updateField(int $dealId, string $field, mixed $value): void
    {
        $deal = Deal::query()->find($dealId);

        if ($deal === null || ! in_array($field, ['owner_id', 'priority', 'next_step', 'expected_close_date', 'amount_once', 'stage_id'], true)) {
            return;
        }

        if ($field === 'stage_id') {
            $this->moveDeal($dealId, is_numeric($value) ? (int) $value : 0);

            return;
        }

        try {
            app(UpdateDeal::class)->handle($deal, [$field => $value === '' ? null : $value], $this->viewer());
            $this->notify((string) __('sales-crm::ui.saved'));
        } catch (ValidationException $exception) {
            $this->notify((string) collect($exception->errors())->flatten()->first(), 'danger');
        }

        unset($this->deals);
    }

    public function applyBulk(): void
    {
        if ($this->selected === [] || $this->bulkAction === '') {
            return;
        }

        try {
            $result = app(BulkUpdateDeals::class)->handle(array_map(intval(...), $this->selected), $this->bulkAction, $this->bulkValue, $this->viewer());
        } catch (ValidationException $exception) {
            $this->notify((string) collect($exception->errors())->flatten()->first(), 'danger');

            return;
        }

        $message = (string) __('sales-crm::ui.bulk_done', ['count' => $result['done']]);

        if ($result['skipped'] !== []) {
            $message .= ' '.__('sales-crm::ui.bulk_skipped', ['count' => count($result['skipped'])]).' '.collect($result['skipped'])->take(3)->map(fn (array $skip): string => '«'.$skip['title'].'»: '.$skip['reason'])->implode(' · ');
        }

        $this->notify($message, $result['skipped'] === [] ? 'success' : 'danger');
        $this->reset('selected', 'bulkAction', 'bulkValue');
        unset($this->deals, $this->columns);
    }

    public function exportUrl(string $format): string
    {
        return route('sales-crm.deals.export', array_filter([
            'workspace' => $this->workspace ?: null,
            'pipeline' => $this->currentPipeline !== null && $this->view === 'kanban' ? $this->currentPipeline->id : null,
            'q' => $this->search ?: null,
            'quick' => $this->quick ?: null,
            'owner' => $this->owner ?: null,
            'priority' => $this->priority ?: null,
            'source' => $this->source ?: null,
            'tag' => $this->tag ?: null,
            'closed' => $this->closed !== 'recent' ? $this->closed : null,
            'format' => $format,
        ], fn (mixed $value): bool => $value !== null));
    }

    // ---- Columns, card fields and saved views ---------------------------------------------

    public function chooseColumns(): void
    {
        $this->columnChoice = array_keys($this->listColumnsChosen());
        $this->showColumns = true;
    }

    public function applyColumns(): void
    {
        $all = array_keys(DealColumns::all($this->scope()));
        $this->listColumns = implode(',', array_values(array_intersect($all, $this->columnChoice)));
        $this->showColumns = false;
    }

    public function chooseCardFields(): void
    {
        $this->cardChoice = $this->cardFieldList() ?? SaveView::CARD_FIELDS;
        $this->showCardFields = true;
    }

    public function applyCardFields(): void
    {
        $chosen = array_values(array_intersect(SaveView::CARD_FIELDS, $this->cardChoice));
        $this->cardFields = count($chosen) === count(SaveView::CARD_FIELDS) ? '' : ($chosen === [] ? SaveView::NO_CARD_FIELDS : implode(',', $chosen));
        $this->showCardFields = false;
    }

    /**
     * The fields the cards show, or null for all of them.
     *
     * @return list<string>|null
     */
    public function cardFieldList(): ?array
    {
        if ($this->cardFields === '') {
            return null;
        }

        return $this->cardFields === SaveView::NO_CARD_FIELDS ? [] : array_values(array_intersect(SaveView::CARD_FIELDS, explode(',', $this->cardFields)));
    }

    /**
     * @return array<string, string>
     */
    public function listColumnsChosen(): array
    {
        return DealColumns::chosen($this->listColumns === '' ? [] : explode(',', $this->listColumns), $this->scope());
    }

    public function saveView(): void
    {
        $this->resetErrorBag();

        try {
            app(SaveView::class)->handle($this->viewer(), $this->selectedWorkspace(), $this->viewName, $this->state(), $this->viewShared);
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'view.');

            return;
        }

        $this->showSaveView = false;
        unset($this->savedViews);
        $this->notify((string) __('sales-crm::ui.view_saved'));
    }

    public function applyView(int $viewId): void
    {
        $view = SavedView::query()->usableBy($this->viewer(), $this->selectedWorkspace())->find($viewId);

        if ($view === null) {
            return;
        }

        $state = SaveView::state($view->state, $this->selectedWorkspace());
        $this->view = (string) $state['view'];
        $this->pipeline = (string) $state['pipeline'];
        $this->search = (string) $state['search'];
        $this->quick = $state['quick'];
        $this->owner = (string) $state['owner'];
        $this->priority = (string) $state['priority'];
        $this->source = (string) $state['source'];
        $this->tag = (string) $state['tag'];
        $this->closed = (string) $state['closed'];
        $this->sort = (string) $state['sort'];
        $this->direction = (string) $state['direction'];
        $this->listColumns = implode(',', $state['columns']);
        $this->cardFields = implode(',', $state['card']);
        $this->limits = [];
        // Other rows now: a selection of rows no longer shown never reaches a bulk action.
        $this->selected = [];
        $this->resetPage();
        unset($this->currentPipeline, $this->columns, $this->deals);
    }

    public function deleteView(int $viewId): void
    {
        $view = SavedView::query()->find($viewId);

        if ($view !== null) {
            app(SaveView::class)->delete($view, $this->viewer());
            unset($this->savedViews);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        return [
            'view' => $this->view,
            'pipeline' => $this->pipeline,
            'search' => $this->search,
            'quick' => $this->quick,
            'owner' => $this->owner,
            'priority' => $this->priority,
            'source' => $this->source,
            'tag' => $this->tag,
            'closed' => $this->closed,
            'sort' => $this->sort,
            'direction' => $this->direction,
            'columns' => $this->listColumns === '' ? [] : explode(',', $this->listColumns),
            'card' => $this->cardFields === '' ? [] : explode(',', $this->cardFields),
        ];
    }

    // ---- Reads -------------------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function workspaces(): array
    {
        return $this->workspaceOptions();
    }

    public function selectedWorkspace(): ?Workspace
    {
        return $this->workspace === '' ? null : $this->workspaceFromKey($this->workspace);
    }

    /**
     * The workspace the filters of the page are about: the one chosen, or the one of the pipeline of
     * the board; null in the shared CRM (or across workspaces in the list).
     */
    public function scope(): ?Workspace
    {
        return $this->selectedWorkspace() ?? ($this->view === 'kanban' ? $this->currentPipeline?->workspaceRef() : null);
    }

    /**
     * The pipelines the user may open: of the workspace chosen, or of all their workspaces. A
     * workspace (or the shared CRM) opened the first time gets its default pipeline.
     *
     * @return Collection<int, Pipeline>
     */
    #[Computed]
    public function pipelines(): Collection
    {
        $user = $this->viewer();
        $resolver = $this->access()->resolver();
        $defaults = app(DefaultPipeline::class);

        if (! $resolver->enabled()) {
            $defaults->ensureFor(null, $user);
        } else {
            $workspaces = $this->selectedWorkspace() !== null ? [$this->selectedWorkspace()] : array_slice($this->access()->workspacesFor($user), 0, 50);

            foreach ($workspaces as $workspace) {
                $defaults->ensureFor($workspace, $user);
            }
        }

        return Pipeline::query()
            ->visibleTo($user)
            ->when($this->selectedWorkspace() !== null, fn (Builder $query) => $query->inWorkspace($this->selectedWorkspace()))
            ->ordered()
            ->get();
    }

    #[Computed]
    public function currentPipeline(): ?Pipeline
    {
        $pipelines = $this->pipelines;
        $pipeline = ($this->pipeline !== '' ? $pipelines->firstWhere('id', (int) $this->pipeline) : null)
            ?? $pipelines->first(fn (Pipeline $pipeline): bool => $pipeline->is_active)
            ?? $pipelines->first();

        return $pipeline?->load('stages');
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        return [
            'search' => $this->search,
            'quick' => $this->quick,
            'owner' => $this->owner,
            'priority' => $this->priority,
            'source' => $this->source,
            'tag' => $this->tag,
            'closed' => $this->closed,
        ];
    }

    /**
     * @return list<array{stage: Stage, deals: Collection<int, Deal>, count: int, value: int, weighted: int, more: int}>
     */
    #[Computed]
    public function columns(): array
    {
        $pipeline = $this->currentPipeline;

        if ($pipeline === null) {
            return [];
        }

        $board = app(DealBoard::class);

        return $board->columns($pipeline, $board->query($this->viewer(), $this->filters(), $pipeline), $this->limits);
    }

    /**
     * @return LengthAwarePaginator<int, Deal>
     */
    #[Computed]
    public function deals(): LengthAwarePaginator
    {
        $table = Crm::table('deals');
        $column = DealBoard::SORTS[$this->sort] ?? 'updated_at';

        return app(DealBoard::class)
            ->query($this->viewer(), $this->filters(), null, $this->resolver()->enabled() ? ($this->selectedWorkspace() ?? false) : null)
            ->with([...DealBoard::cardRelations(), 'stage', 'pipeline.stages'])
            ->orderBy($table.'.'.$column, $this->direction === 'asc' ? 'asc' : 'desc')
            ->orderBy($table.'.id', 'desc')
            ->paginate(50);
    }

    /**
     * The users of the workspace in scope, for the owner filter and the quick add.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function owners(): array
    {
        if ($this->resolver()->enabled() && $this->scope() === null) {
            return [];
        }

        return $this->access()->members($this->scope())->limit(500)->get()
            ->mapWithKeys(fn ($user): array => [(string) Crm::userKey($user) => Crm::userName($user)])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function tags(): array
    {
        $tags = Deal::query()->visibleTo($this->viewer())->whereNotNull('tags')->limit(500)->pluck('tags')
            ->flatten()->filter(fn (mixed $tag): bool => is_string($tag) && $tag !== '')->unique()->sort()->values();

        return $tags->mapWithKeys(fn (string $tag): array => [$tag => $tag])->all();
    }

    /**
     * @return Collection<int, SavedView>
     */
    #[Computed]
    public function savedViews(): Collection
    {
        return SavedView::query()->usableBy($this->viewer(), $this->selectedWorkspace())->with('user')->orderBy('name')->get();
    }

    /**
     * Whether the user has no deal at all yet: the getting-started guide shows.
     */
    #[Computed]
    public function isEmpty(): bool
    {
        return ! Deal::query()->visibleTo($this->viewer())->exists();
    }

    /**
     * The company names of the workspace, for the autocomplete of the quick add.
     *
     * @return list<string>
     */
    #[Computed]
    public function companyNames(): array
    {
        return array_values(array_map('strval', Company::query()->inWorkspace($this->currentPipeline?->workspaceRef())->orderBy('name')->limit(300)->pluck('name')->all()));
    }

    /**
     * The custom fields of the workspace in scope, by key.
     *
     * @return array<string, array{key: string, label: string, type: string, options?: list<string>}>
     */
    public function customFields(): array
    {
        return collect(CustomFields::of($this->scope()))->keyBy('key')->all();
    }

    public function canManageScope(): bool
    {
        return $this->canManage($this->scope());
    }

    private function resolver(): WorkspaceResolver
    {
        return $this->access()->resolver();
    }

    private function currentVersion(): string
    {
        $pipeline = $this->currentPipeline;

        return $pipeline === null ? '' : app(DealBoard::class)->version($pipeline);
    }
}
