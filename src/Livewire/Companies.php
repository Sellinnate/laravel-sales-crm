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
use Sellinnate\SalesCrm\Actions\SaveCompany;
use Sellinnate\SalesCrm\Livewire\Concerns\InteractsWithCrm;
use Sellinnate\SalesCrm\Livewire\Concerns\ListsRecords;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Support\Search;

/**
 * The companies of the workspaces of the user, with search and the creation of a new one (the
 * possible duplicate is shown instead of creating it, unless confirmed).
 */
class Companies extends Component
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
        return $this->page('sales-crm::livewire.companies', (string) __('sales-crm::ui.nav.companies'));
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
        $this->draft = ['workspace' => $this->workspace, 'name' => '', 'vat_number' => '', 'website' => '', 'email' => '', 'phone' => '', 'city' => '', 'industry' => ''];
        $this->showCreate = true;
    }

    public function create(bool $force = false): void
    {
        $this->resetErrorBag();
        $data = array_diff_key($this->draft, ['workspace' => true]);

        try {
            $company = app(SaveCompany::class)->handle($this->targetWorkspace(), $data, $this->viewer(), null, $force);
        } catch (ValidationException $exception) {
            $this->duplicate = $exception->errors()['duplicate'][0] ?? null;
            $this->showErrors($exception, 'draft.');

            return;
        }

        $this->redirect(route('sales-crm.companies.show', $company));
    }

    /**
     * @return LengthAwarePaginator<int, Company>
     */
    #[Computed]
    public function companies(): LengthAwarePaginator
    {
        $table = Crm::table('companies');
        $pattern = Search::contains($this->search);

        return Company::query()
            ->visibleTo($this->viewer())
            ->when($this->workspace !== '', fn (Builder $query) => $query->inWorkspace($this->workspaceFromKey($this->workspace)))
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->whereRaw(Search::like('LOWER('.Crm::db()->getQueryGrammar()->wrap($table.'.name').')'), [$pattern])
                ->whereRaw(Search::like('LOWER(COALESCE('.Crm::db()->getQueryGrammar()->wrap($table.'.vat_number').", ''))"), [$pattern], 'or')
                ->whereRaw(Search::like('LOWER(COALESCE('.Crm::db()->getQueryGrammar()->wrap($table.'.domain').", ''))"), [$pattern], 'or')
                ->whereRaw(Search::like('LOWER(COALESCE('.Crm::db()->getQueryGrammar()->wrap($table.'.city').", ''))"), [$pattern], 'or')))
            ->withCount(['deals', 'people'])
            ->orderBy('name')
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
}
