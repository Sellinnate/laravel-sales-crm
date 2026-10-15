<?php

namespace Sellinnate\SalesCrm\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Sellinnate\SalesCrm\Actions\SaveCompany;
use Sellinnate\SalesCrm\Livewire\Concerns\InteractsWithCrm;
use Sellinnate\SalesCrm\Models\Company;

/**
 * A company: its details, its deals and people, its notes, activities, documents and timeline.
 */
class CompanyPage extends Component
{
    use InteractsWithCrm;

    #[Locked]
    public int $companyId;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(Company $company): void
    {
        Gate::forUser($this->viewer())->authorize('view', $company);
        $this->companyId = $company->id;
        $this->fillForm($company);
    }

    public function render(): View
    {
        $company = $this->company()->load(['deals.stage', 'people']);

        return $this->page('sales-crm::livewire.company-page', $company->name, ['company' => $company]);
    }

    public function company(): Company
    {
        return Company::query()->findOrFail($this->companyId);
    }

    private function fillForm(Company $company): void
    {
        $this->form = array_map(fn (mixed $value): string => (string) $value, $company->only(array_keys(SaveCompany::rules())));
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $company = $this->company();

        try {
            $company = app(SaveCompany::class)->handle($company->workspaceRef(), $this->form, $this->viewer(), $company);
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'form.');

            return;
        }

        $this->fillForm($company);
        $this->notify((string) __('sales-crm::ui.saved'));
    }

    public function delete(): void
    {
        try {
            app(SaveCompany::class)->delete($this->company(), $this->viewer());
        } catch (ValidationException $exception) {
            $this->notify((string) collect($exception->errors())->flatten()->first(), 'danger');

            return;
        }

        $this->redirect(route('sales-crm.companies'));
    }

    public function canDelete(): bool
    {
        return Gate::forUser($this->viewer())->allows('delete', $this->company());
    }
}
