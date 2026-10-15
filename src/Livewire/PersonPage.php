<?php

namespace Sellinnate\SalesCrm\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Sellinnate\SalesCrm\Actions\SavePerson;
use Sellinnate\SalesCrm\Livewire\Concerns\InteractsWithCrm;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Person;

/**
 * A person: their details, deals, notes, activities, emails and timeline.
 */
class PersonPage extends Component
{
    use InteractsWithCrm;

    public const FIELDS = ['first_name', 'last_name', 'job_title', 'email', 'mobile', 'phone', 'phone_source', 'company_id', 'notes'];

    #[Locked]
    public int $personId;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(Person $person): void
    {
        Gate::forUser($this->viewer())->authorize('view', $person);
        $this->personId = $person->id;
        $this->fillForm($person);
    }

    public function render(): View
    {
        $person = $this->person()->load(['deals.stage', 'company']);

        return $this->page('sales-crm::livewire.person-page', $person->name, ['person' => $person]);
    }

    public function person(): Person
    {
        return Person::query()->findOrFail($this->personId);
    }

    private function fillForm(Person $person): void
    {
        $this->form = array_map(fn (mixed $value): string => (string) $value, $person->only(self::FIELDS));
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $person = $this->person();

        try {
            $person = app(SavePerson::class)->handle($person->workspaceRef(), $this->form, $this->viewer(), $person);
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'form.');

            return;
        }

        $this->fillForm($person);
        $this->notify((string) __('sales-crm::ui.saved'));
    }

    public function delete(): void
    {
        app(SavePerson::class)->delete($this->person(), $this->viewer());
        $this->redirect(route('sales-crm.people'));
    }

    public function canDelete(): bool
    {
        return Gate::forUser($this->viewer())->allows('delete', $this->person());
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function companies(): array
    {
        return Company::query()->inWorkspace($this->person()->workspaceRef())->orderBy('name')->limit(500)->pluck('name', 'id')->all();
    }
}
