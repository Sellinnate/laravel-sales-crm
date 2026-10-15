<?php

namespace Sellinnate\SalesCrm\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Sellinnate\SalesCrm\Actions\ManageActivities;
use Sellinnate\SalesCrm\Actions\ManageDocuments;
use Sellinnate\SalesCrm\Actions\ManageNotes;
use Sellinnate\SalesCrm\Data\TimelineEntry;
use Sellinnate\SalesCrm\Livewire\Concerns\InteractsWithCrm;
use Sellinnate\SalesCrm\Models\Activity;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Note;
use Sellinnate\SalesCrm\Models\Person;
use Sellinnate\SalesCrm\Services\DealTimeline;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * The notes, activities, documents and timeline of a company or a person.
 */
class RecordFeed extends Component
{
    use InteractsWithCrm;
    use WithFileUploads;

    #[Locked]
    public string $type = 'company';

    #[Locked]
    public int $recordId;

    public string $noteBody = '';

    /** @var array<string, mixed> */
    public array $activity = [];

    /** @var TemporaryUploadedFile|null */
    public $upload = null;

    public string $documentKind = 'other';

    public string $kind = '';

    public function mount(string $type, int $recordId): void
    {
        $this->type = $type === 'person' ? 'person' : 'company';
        $this->recordId = $recordId;
        Gate::forUser($this->viewer())->authorize('view', $this->record());
        $this->resetActivity();
    }

    public function render(): View
    {
        return view('sales-crm::livewire.record-feed');
    }

    public function record(): Company|Person
    {
        return $this->type === 'person' ? Person::query()->findOrFail($this->recordId) : Company::query()->findOrFail($this->recordId);
    }

    private function resetActivity(): void
    {
        $this->activity = ['type' => 'call', 'title' => '', 'due_date' => CarbonImmutable::tomorrow()->toDateString(), 'due_time' => '', 'assigned_to' => (string) (Crm::userKey(auth()->user()) ?? '')];
    }

    public function addNote(): void
    {
        $this->resetErrorBag();

        try {
            app(ManageNotes::class)->add($this->record(), $this->noteBody, $this->viewer());
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'note.');

            return;
        }

        $this->noteBody = '';
        $this->refreshFeed((string) __('sales-crm::ui.note_added'));
    }

    public function deleteNote(int $noteId): void
    {
        $note = Note::query()->where($this->type === 'person' ? 'person_id' : 'company_id', $this->recordId)->find($noteId);

        if ($note !== null && Gate::forUser($this->viewer())->allows('delete', $note)) {
            app(ManageNotes::class)->delete($note, $this->viewer());
            $this->refreshFeed();
        }
    }

    public function saveActivity(): void
    {
        $this->resetErrorBag();

        try {
            app(ManageActivities::class)->save($this->record(), $this->activity, $this->viewer());
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'activity.');

            return;
        }

        $this->resetActivity();
        $this->refreshFeed((string) __('sales-crm::ui.activity_saved'));
    }

    public function completeActivity(int $activityId): void
    {
        $activity = Activity::query()->where($this->type === 'person' ? 'person_id' : 'company_id', $this->recordId)->find($activityId);

        if ($activity !== null) {
            app(ManageActivities::class)->complete($activity, null, $this->viewer());
            $this->refreshFeed((string) __('sales-crm::ui.activity_done'));
        }
    }

    public function uploadDocument(): void
    {
        $record = $this->record();

        if (! $record instanceof Company || ! $this->upload instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            app(ManageDocuments::class)->upload($record, $this->upload, ['kind' => $this->documentKind], $this->viewer());
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'document.');

            return;
        }

        $this->upload = null;
        $this->refreshFeed((string) __('sales-crm::ui.document_uploaded'));
    }

    private function refreshFeed(?string $message = null): void
    {
        unset($this->timeline, $this->counts, $this->openActivities);

        if ($message !== null) {
            $this->notify($message);
        }
    }

    /**
     * @return list<TimelineEntry>
     */
    #[Computed]
    public function timeline(): array
    {
        return app(DealTimeline::class)->for($this->record(), $this->viewer(), $this->kind === '' ? null : $this->kind);
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return app(DealTimeline::class)->counts($this->record(), $this->viewer());
    }

    /**
     * @return array<string, string>
     */
    public function kinds(): array
    {
        return app(DealTimeline::class)->kinds($this->record());
    }

    /**
     * @return Collection<int, Activity>
     */
    #[Computed]
    public function openActivities(): Collection
    {
        return Activity::query()->where($this->type === 'person' ? 'person_id' : 'company_id', $this->recordId)->whereNull('deal_id')->whereNull('done_at')->orderBy('due_at')->get();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function members(): array
    {
        return $this->access()->members($this->record()->workspaceRef())->limit(500)->get()
            ->mapWithKeys(fn ($user): array => [(string) Crm::userKey($user) => Crm::userName($user)])
            ->all();
    }
}
