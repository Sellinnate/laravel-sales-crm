<?php

namespace Sellinnate\SalesCrm\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Sellinnate\SalesCrm\Actions\SavePerson;
use Sellinnate\SalesCrm\Livewire\Concerns\InteractsWithCrm;
use Sellinnate\SalesCrm\Livewire\Concerns\ListsRecords;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Person;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Support\Search;

/**
 * The people of the workspaces of the user, with search and the creation of a new one (the possible
 * duplicate is shown instead of creating it, unless confirmed).
 */
class People extends Component
{
    use InteractsWithCrm;
    use ListsRecords;
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $workspace = '';

    public bool $showCreate = false;

    /** @var array<string, mixed> */
    public array $draft = [];

    public ?string $duplicate = null;

    public function mount(): void
    {
        $this->viewer();
    }

    public function render(): View
    {
        return $this->page('sales-crm::livewire.people', (string) __('sales-crm::ui.nav.people'));
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'workspace'], true)) {
            $this->resetPage();
        }
    }

    public function startCreate(): void
    {
        $this->resetErrorBag();
        $this->duplicate = null;
        $this->draft = ['workspace' => $this->draftWorkspace(), 'first_name' => '', 'last_name' => '', 'email' => '', 'mobile' => '', 'job_title' => '', 'company_id' => ''];
        $this->showCreate = true;
    }

    public function create(bool $force = false): void
    {
        $this->resetErrorBag();
        $data = array_diff_key($this->draft, ['workspace' => true]);

        try {
            $person = app(SavePerson::class)->handle($this->targetWorkspace(), $data, $this->viewer(), null, $force);
        } catch (ValidationException $exception) {
            $this->duplicate = $exception->errors()['duplicate'][0] ?? null;
            $this->showErrors($exception, 'draft.');

            return;
        }

        $this->redirect(route('sales-crm.people.show', $person));
    }

    /**
     * @return LengthAwarePaginator<int, Person>
     */
    #[Computed]
    public function people(): LengthAwarePaginator
    {
        $table = Crm::table('people');
        $wrap = fn (string $column): string => Crm::db()->getQueryGrammar()->wrap($table.'.'.$column);
        $pattern = Search::contains($this->search);
        $digits = (string) preg_replace('/\D+/', '', $this->search);

        return Person::query()
            ->visibleTo($this->viewer())
            ->when($this->workspace !== '', fn (Builder $query) => $query->inWorkspace($this->workspaceFromKey($this->workspace)))
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($wrap, $pattern, $digits, $table): void {
                $inner->whereRaw(Search::like('LOWER('.Search::concat("COALESCE({$wrap('first_name')}, '')", "COALESCE({$wrap('last_name')}, '')").')'), [$pattern])
                    ->whereRaw(Search::like("LOWER(COALESCE({$wrap('email')}, ''))"), [$pattern], 'or');

                if (strlen($digits) >= 4) {
                    $inner->orWhere($table.'.mobile', 'like', '%'.$digits.'%')->orWhere($table.'.phone', 'like', '%'.$digits.'%');
                }
            }))
            ->with('company:id,name')
            ->withCount(['deals' => fn (Builder $deals) => $deals->whereIn(Crm::table('deals').'.id', Deal::query()->visibleTo($this->viewer())->select(Crm::table('deals').'.id'))])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(30);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function workspaces(): array
    {
        return $this->workspaceOptions();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function companies(): array
    {
        return Company::query()->inWorkspace($this->targetWorkspace())->orderBy('name')->limit(500)->pluck('name', 'id')->all();
    }
}
