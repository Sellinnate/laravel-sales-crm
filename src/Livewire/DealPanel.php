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
use Sellinnate\SalesCrm\Actions\DeleteDeal;
use Sellinnate\SalesCrm\Actions\ManageActivities;
use Sellinnate\SalesCrm\Actions\ManageDealPeople;
use Sellinnate\SalesCrm\Actions\ManageDocuments;
use Sellinnate\SalesCrm\Actions\ManageNotes;
use Sellinnate\SalesCrm\Actions\MoveDeal;
use Sellinnate\SalesCrm\Actions\SendEmail;
use Sellinnate\SalesCrm\Actions\UpdateDeal;
use Sellinnate\SalesCrm\Data\TimelineEntry;
use Sellinnate\SalesCrm\Enums\PersonRole;
use Sellinnate\SalesCrm\Enums\RequiredField;
use Sellinnate\SalesCrm\Exceptions\MissingRequirements;
use Sellinnate\SalesCrm\Livewire\Concerns\InteractsWithCrm;
use Sellinnate\SalesCrm\Models\Activity;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Document;
use Sellinnate\SalesCrm\Models\Email;
use Sellinnate\SalesCrm\Models\EmailTemplate;
use Sellinnate\SalesCrm\Models\Note;
use Sellinnate\SalesCrm\Models\Person;
use Sellinnate\SalesCrm\Models\Stage;
use Sellinnate\SalesCrm\Services\CustomFields;
use Sellinnate\SalesCrm\Services\DealFields;
use Sellinnate\SalesCrm\Services\DealRequirements;
use Sellinnate\SalesCrm\Services\DealTimeline;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * Everything about a deal without leaving the board: the next step and the next activity on top,
 * the fields (stage, amounts, people, operational state, signature and payment, approval,
 * commission, custom fields), the quick actions (note, activity, email, document, call) and the
 * timeline with a counter per kind. Also the body of the full page of a deal.
 *
 * @property-read Deal $model
 * @property-read list<TimelineEntry> $timeline
 * @property-read array<string, int> $counts
 * @property-read Collection<int, Activity> $openActivities
 * @property-read Collection<int, Document> $documents
 * @property-read array<string, string> $members
 * @property-read Collection<int, EmailTemplate> $templates
 * @property-read Collection<int, Person> $workspacePeople
 */
class DealPanel extends Component
{
    use InteractsWithCrm;
    use WithFileUploads;

    /** The editable fields of the form, as {@see DealFields} takes them. */
    public const FIELDS = [
        'title', 'company_name', 'owner_id', 'priority', 'source', 'tags', 'external_ref', 'amount_once', 'deposit', 'recurring',
        'recurring_period', 'recurring_options', 'payment_terms', 'offer_details', 'offer_valid_until', 'expected_close_date',
        'sub_status', 'next_step', 'blocker', 'missing_data', 'call_context', 'closing_tip', 'approval_required', 'approver_id',
        'approval_note', 'approval_status', 'signature_status', 'signature_evidence', 'payment_status', 'amount_collected',
        'payment_evidence', 'commission_type', 'commission_value', 'commission_note', 'last_contacted_at', 'data_verified_at',
        'lost_reason', 'lost_note',
    ];

    #[Locked]
    public int $dealId;

    public bool $page = false;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var array<string, mixed> */
    public array $original = [];

    /** @var array<string, mixed> */
    public array $custom = [];

    /**
     * The custom fields as loaded, to send only those changed.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $originalCustom = [];

    public string $action = '';

    public string $noteBody = '';

    /** @var array<string, mixed> */
    public array $activity = [];

    public ?int $editingActivity = null;

    /** @var array<string, string> */
    public array $outcomes = [];

    /** @var array<string, mixed> */
    public array $email = [];

    /** @var array<string, mixed> */
    public array $person = [];

    /** @var TemporaryUploadedFile|null */
    public $upload = null;

    /** @var array<string, mixed> */
    public array $document = [];

    /** @var array<string, mixed> */
    public array $call = [];

    public string $kind = '';

    // Set by the server only (a move refused for its requirements): never by the browser.
    #[Locked]
    public ?int $pendingStage = null;

    /** @var array<string, mixed> */
    public array $fill = [];

    public bool $showRequirements = false;

    public bool $deleted = false;

    public function mount(int $dealId, bool $page = false): void
    {
        $this->dealId = $dealId;
        $this->page = $page;
        // Refused (403) rather than not found when the deal exists but isn't the user's to see.
        Gate::forUser($this->viewer())->authorize('view', Deal::query()->findOrFail($dealId));
        $deal = $this->deal();

        $this->loadForm($deal);
        $this->resetActivity();
    }

    public function render(): View
    {
        return view($this->deleted ? 'sales-crm::livewire.deal-deleted' : 'sales-crm::livewire.deal-panel');
    }

    public function deal(): Deal
    {
        // Every request, not only the first: the deal may have been hidden while the panel was open.
        return Deal::query()->visibleTo($this->viewer())->with(['stage', 'pipeline.stages', 'company', 'owner', 'approver', 'people'])->findOrFail($this->dealId);
    }

    #[Computed]
    public function model(): Deal
    {
        return $this->deal();
    }

    private function loadForm(Deal $deal): void
    {
        $cents = fn (?int $value): string => $value === null ? '' : rtrim(rtrim(number_format($value / 100, 2, '.', ''), '0'), '.');

        $this->form = [
            'title' => $deal->title,
            'company_name' => (string) $deal->company?->name,
            'owner_id' => (string) ($deal->owner_id ?? ''),
            'priority' => (string) $deal->priority?->value,
            'source' => $deal->source->value,
            'tags' => implode(', ', $deal->tags ?? []),
            'external_ref' => (string) $deal->external_ref,
            'amount_once' => $cents($deal->amount_once_cents),
            'deposit' => $cents($deal->deposit_cents),
            'recurring' => $cents($deal->recurring_cents),
            'recurring_period' => (string) ($deal->recurring_period->value ?? 'monthly'),
            'recurring_options' => (string) $deal->recurring_options,
            'payment_terms' => (string) $deal->payment_terms,
            'offer_details' => (string) $deal->offer_details,
            'offer_valid_until' => (string) $deal->offer_valid_until?->toDateString(),
            'expected_close_date' => (string) $deal->expected_close_date?->toDateString(),
            'sub_status' => (string) $deal->sub_status,
            'next_step' => (string) $deal->next_step,
            'blocker' => (string) $deal->blocker,
            'missing_data' => (string) $deal->missing_data,
            'call_context' => (string) $deal->call_context,
            'closing_tip' => (string) $deal->closing_tip,
            'approval_required' => $deal->approval_required,
            'approver_id' => (string) ($deal->approver_id ?? ''),
            'approval_note' => (string) $deal->approval_note,
            'approval_status' => (string) $deal->approval_status?->value,
            'signature_status' => $deal->signature_status->value,
            'signature_evidence' => (string) $deal->signature_evidence,
            'payment_status' => $deal->payment_status->value,
            'amount_collected' => $cents($deal->amount_collected_cents),
            'payment_evidence' => (string) $deal->payment_evidence,
            'commission_type' => (string) $deal->commission_type?->value,
            'commission_value' => $cents($deal->commission_value),
            'commission_note' => (string) $deal->commission_note,
            'last_contacted_at' => (string) $deal->last_contacted_at?->toDateString(),
            'data_verified_at' => (string) $deal->data_verified_at?->toDateString(),
            'lost_reason' => (string) $deal->lost_reason?->value,
            'lost_note' => (string) $deal->lost_note,
        ];
        $this->original = $this->form;
        $this->custom = [];

        foreach (CustomFields::of($deal->workspaceRef()) as $field) {
            $value = $deal->custom_values[$field['key']] ?? null;
            // An unset yes/no stays unknown (null) until the user ticks it: never saved as "no".
            $this->custom[$field['key']] = $field['type'] === 'boolean' ? ($value === null ? null : (bool) $value) : (is_scalar($value) ? (string) $value : '');
        }

        $this->originalCustom = $this->custom;
    }

    /**
     * Save the fields changed in the form (only those: an untouched approval never runs its rules).
     */
    public function save(): void
    {
        $this->resetErrorBag();
        $changes = [];

        foreach (self::FIELDS as $field) {
            if (($this->form[$field] ?? null) !== ($this->original[$field] ?? null)) {
                $changes[$field] = $this->form[$field];
            }
        }

        if (array_key_exists('tags', $changes)) {
            $changes['tags'] = array_values(array_filter(array_map('trim', explode(',', (string) $changes['tags']))));
        }

        if (array_key_exists('approval_required', $changes)) {
            $changes['approval_required'] = (bool) $changes['approval_required'];
        }

        foreach (['owner_id', 'approver_id'] as $field) {
            if (array_key_exists($field, $changes) && $changes[$field] === '') {
                $changes[$field] = null;
            }
        }

        // Only the custom fields changed here: the others may have been changed meanwhile elsewhere.
        $custom = array_filter($this->custom, fn (mixed $value, string $key): bool => $value !== ($this->originalCustom[$key] ?? null), ARRAY_FILTER_USE_BOTH);

        if ($custom !== []) {
            $changes['custom_values'] = $custom;
        }

        try {
            $deal = app(UpdateDeal::class)->handle($this->deal(), $changes, $this->viewer());
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'form.');

            return;
        }

        $this->loadForm($deal->fresh() ?? $deal);
        $this->changed((string) __('sales-crm::ui.saved'));
    }

    /**
     * Move the deal from the panel; the fields the stage requires are asked here.
     */
    public function moveTo(int $stageId): void
    {
        $deal = $this->deal();
        $stage = Stage::query()->where('pipeline_id', $deal->pipeline_id)->find($stageId);

        if ($stage === null || $stage->id === $deal->stage_id) {
            return;
        }

        try {
            app(MoveDeal::class)->handle($deal, $stage, $this->viewer());
        } catch (ValidationException $exception) {
            $missing = MissingRequirements::fieldsOf($exception);

            if ($missing !== [] || array_key_exists('lost_reason', $exception->errors())) {
                $this->pendingStage = $stage->id;
                $this->fill = DealRequirements::fillDefaults($deal, $missing);
                $this->showRequirements = true;

                return;
            }

            $this->notify((string) collect($exception->errors())->flatten()->first(), 'danger');

            return;
        }

        $this->afterMove();
    }

    public function completeMove(): void
    {
        $deal = $this->deal();
        $stage = $this->pendingStage === null ? null : Stage::query()->where('pipeline_id', $this->deal()->pipeline_id)->find($this->pendingStage);

        if ($stage === null) {
            $this->showRequirements = false;

            return;
        }

        $fill = array_intersect_key($this->fill, array_flip(DealRequirements::fillKeys($this->pendingMissing(), $this->pendingNeedsReason())));
        $this->resetErrorBag();

        try {
            app(MoveDeal::class)->handle($deal, $stage, $this->viewer(), $fill);
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'fill.');

            return;
        }

        $this->reset('showRequirements', 'pendingStage', 'fill');
        $this->afterMove();
    }

    /**
     * @return list<RequiredField>
     */
    public function pendingMissing(): array
    {
        $stage = $this->pendingStage === null ? null : Stage::query()->where('pipeline_id', $this->deal()->pipeline_id)->find($this->pendingStage);

        return $stage === null ? [] : app(DealRequirements::class)->missing($this->deal(), $stage);
    }

    public function pendingNeedsReason(): bool
    {
        $stage = $this->pendingStage === null ? null : Stage::query()->where('pipeline_id', $this->deal()->pipeline_id)->find($this->pendingStage);

        return $stage !== null && app(DealRequirements::class)->needsLostReason($this->deal(), $stage);
    }

    private function afterMove(): void
    {
        $this->loadForm($this->deal());
        $this->changed((string) __('sales-crm::ui.moved'));
    }

    // ---- People -----------------------------------------------------------------------------

    public function addPerson(): void
    {
        $this->resetErrorBag();

        try {
            app(ManageDealPeople::class)->attach($this->deal(), $this->person, $this->viewer());
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'person.');

            return;
        }

        $this->person = [];
        $this->action = '';
        $this->changed((string) __('sales-crm::ui.person_added'));
    }

    public function makePrimary(int $personId): void
    {
        $person = $this->deal()->people->firstWhere('id', $personId);

        if ($person instanceof Person) {
            $role = PersonRole::tryFrom((string) ($person->getRelationValue('pivot')?->getAttribute('role') ?? ''));
            app(ManageDealPeople::class)->update($this->deal(), $person, $role, true, $this->viewer());
            $this->changed();
        }
    }

    public function setRole(int $personId, string $role): void
    {
        $person = $this->deal()->people->firstWhere('id', $personId);

        if ($person instanceof Person) {
            app(ManageDealPeople::class)->update($this->deal(), $person, PersonRole::tryFrom($role), false, $this->viewer());
            $this->changed();
        }
    }

    public function removePerson(int $personId): void
    {
        $person = $this->deal()->people->firstWhere('id', $personId);

        if ($person instanceof Person) {
            app(ManageDealPeople::class)->detach($this->deal(), $person, $this->viewer());
            $this->changed();
        }
    }

    // ---- Notes ------------------------------------------------------------------------------

    public function addNote(): void
    {
        $this->resetErrorBag();

        try {
            app(ManageNotes::class)->add($this->deal(), $this->noteBody, $this->viewer());
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'note.');

            return;
        }

        $this->noteBody = '';
        $this->changed((string) __('sales-crm::ui.note_added'));
    }

    public function pinNote(int $noteId, bool $pinned): void
    {
        $note = Note::query()->where('deal_id', $this->dealId)->find($noteId);

        if ($note !== null) {
            app(ManageNotes::class)->pin($note, $pinned, $this->viewer());
            $this->changed();
        }
    }

    public function deleteNote(int $noteId): void
    {
        $note = Note::query()->where('deal_id', $this->dealId)->find($noteId);

        if ($note !== null && Gate::forUser($this->viewer())->allows('delete', $note)) {
            app(ManageNotes::class)->delete($note, $this->viewer());
            $this->changed();
        }
    }

    // ---- Activities -------------------------------------------------------------------------

    private function resetActivity(): void
    {
        $this->editingActivity = null;
        $this->activity = [
            'type' => 'call',
            'title' => '',
            'due_date' => CarbonImmutable::tomorrow()->toDateString(),
            'due_time' => '',
            'remind_minutes' => '',
            'assigned_to' => (string) (Crm::userKey(auth()->user()) ?? ''),
            'notes' => '',
        ];
    }

    public function editActivity(int $activityId): void
    {
        $activity = Activity::query()->where('deal_id', $this->dealId)->find($activityId);

        if ($activity === null) {
            return;
        }

        $this->editingActivity = $activity->id;
        $this->activity = [
            'type' => $activity->type->value,
            'title' => $activity->title,
            'due_date' => $activity->due_at->toDateString(),
            'due_time' => $activity->has_time ? $activity->due_at->format('H:i') : '',
            'remind_minutes' => (string) ($activity->remind_minutes ?? ''),
            'assigned_to' => (string) ($activity->assigned_to ?? ''),
            'notes' => (string) $activity->notes,
        ];
        $this->action = 'activity';
    }

    public function saveActivity(): void
    {
        $this->resetErrorBag();
        $activity = $this->editingActivity === null ? null : Activity::query()->where('deal_id', $this->dealId)->find($this->editingActivity);

        try {
            app(ManageActivities::class)->save($this->deal(), $this->activity, $this->viewer(), $activity);
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'activity.');

            return;
        }

        $this->resetActivity();
        $this->action = '';
        $this->changed((string) __('sales-crm::ui.activity_saved'));
    }

    public function completeActivity(int $activityId): void
    {
        $activity = Activity::query()->where('deal_id', $this->dealId)->find($activityId);

        if ($activity !== null) {
            $key = (string) $activityId;
            $outcome = array_key_exists($key, $this->outcomes) ? $this->outcomes[$key] : null;
            app(ManageActivities::class)->complete($activity, $outcome, $this->viewer());
            $this->outcomes = array_diff_key($this->outcomes, [$key => true]);
            $this->changed((string) __('sales-crm::ui.activity_done'));
        }
    }

    public function reopenActivity(int $activityId): void
    {
        $activity = Activity::query()->where('deal_id', $this->dealId)->find($activityId);

        if ($activity !== null) {
            app(ManageActivities::class)->reopen($activity, $this->viewer());
            $this->changed();
        }
    }

    public function deleteActivity(int $activityId): void
    {
        $activity = Activity::query()->where('deal_id', $this->dealId)->find($activityId);

        if ($activity !== null) {
            app(ManageActivities::class)->delete($activity, $this->viewer());
            $this->changed();
        }
    }

    public function logCall(): void
    {
        $this->resetErrorBag();

        try {
            app(ManageActivities::class)->logCall($this->deal(), $this->call, $this->viewer());
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'call.');

            return;
        }

        $this->call = [];
        $this->action = '';
        $this->changed((string) __('sales-crm::ui.call_logged'));
    }

    // ---- Email ------------------------------------------------------------------------------

    public function startEmail(?int $replyTo = null): void
    {
        $deal = $this->deal();
        $parent = $replyTo === null ? null : Email::query()->where('deal_id', $deal->id)->find($replyTo);
        $primary = $deal->people->first();

        $this->email = [
            'to' => $parent !== null ? ($parent->direction->value === 'in' ? $parent->from_email : implode(', ', $parent->to)) : (string) $primary?->email,
            'cc' => '',
            'subject' => $parent !== null ? (str_starts_with(mb_strtolower($parent->subject), 're:') ? $parent->subject : 'Re: '.$parent->subject) : '',
            'body' => '',
            'reply_to_id' => $parent?->id,
            'template' => '',
        ];
        $this->action = 'email';
    }

    public function applyTemplate(): void
    {
        $template = EmailTemplate::query()->inWorkspace($this->deal()->workspaceRef())->find((int) ($this->email['template'] ?? 0));

        if ($template !== null) {
            $this->email['subject'] = $template->subject;
            $this->email['body'] = $template->body;
        }
    }

    public function sendEmail(): void
    {
        $this->resetErrorBag();

        try {
            app(SendEmail::class)->handle($this->deal(), $this->email, $this->viewer());
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'email.');

            return;
        }

        $this->email = [];
        $this->action = '';
        $this->changed((string) __('sales-crm::ui.email_queued'));
    }

    // ---- Documents --------------------------------------------------------------------------

    public function uploadDocument(): void
    {
        $this->resetErrorBag();

        if (! $this->upload instanceof TemporaryUploadedFile) {
            $this->addError('document.file', (string) __('sales-crm::ui.choose_file'));

            return;
        }

        try {
            app(ManageDocuments::class)->upload($this->deal(), $this->upload, [
                'kind' => $this->document['kind'] ?? 'quote',
                'document_date' => $this->document['document_date'] ?? null,
                'valid_until' => $this->document['valid_until'] ?? null,
            ], $this->viewer());
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'document.');

            return;
        }

        $this->upload = null;
        $this->document = [];
        $this->action = '';
        $this->loadForm($this->deal());
        $this->changed((string) __('sales-crm::ui.document_uploaded'));
    }

    public function deleteDocument(int $documentId): void
    {
        $document = Document::query()->where('deal_id', $this->dealId)->find($documentId);

        if ($document === null) {
            return;
        }

        try {
            app(ManageDocuments::class)->delete($document, $this->viewer());
        } catch (ValidationException $exception) {
            $this->notify((string) collect($exception->errors())->flatten()->first(), 'danger');

            return;
        }

        $this->changed();
    }

    public function deleteDeal(): void
    {
        $deal = $this->deal();
        app(DeleteDeal::class)->handle($deal, $this->viewer());
        $this->deleted = true;
        $this->dispatch('crm-deal-updated');
        $this->notify((string) __('sales-crm::ui.deal_deleted'));

        if ($this->page) {
            $this->redirect(route('sales-crm.deals'));

            return;
        }

        $this->dispatch('crm-close-panel');
    }

    public function open(string $action): void
    {
        $this->resetErrorBag();
        $this->action = $this->action === $action ? '' : $action;

        if ($this->action === 'email' && $this->email === []) {
            $this->startEmail();
        }

        if ($this->action === 'activity' && $this->editingActivity === null) {
            $this->resetActivity();
        }
    }

    private function changed(?string $message = null): void
    {
        unset($this->model, $this->timeline, $this->counts, $this->openActivities, $this->documents);
        $this->dispatch('crm-deal-updated');

        if ($message !== null) {
            $this->notify($message);
        }
    }

    // ---- Reads ------------------------------------------------------------------------------

    /**
     * @return list<TimelineEntry>
     */
    #[Computed]
    public function timeline(): array
    {
        return app(DealTimeline::class)->for($this->deal(), $this->viewer(), $this->kind === '' ? null : $this->kind);
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return app(DealTimeline::class)->counts($this->deal(), $this->viewer());
    }

    /**
     * @return array<string, string>
     */
    public function kinds(): array
    {
        return app(DealTimeline::class)->kinds($this->deal());
    }

    /**
     * @return Collection<int, Activity>
     */
    #[Computed]
    public function openActivities(): Collection
    {
        return Activity::query()->where('deal_id', $this->dealId)->whereNull('done_at')->with('assignee')->orderBy('due_at')->get();
    }

    /**
     * @return Collection<int, Document>
     */
    #[Computed]
    public function documents(): Collection
    {
        return Document::query()->where('deal_id', $this->dealId)->latest('id')->get();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function members(): array
    {
        return $this->access()->members($this->deal()->workspaceRef())->limit(500)->get()
            ->mapWithKeys(fn ($user): array => [(string) Crm::userKey($user) => Crm::userName($user)])
            ->all();
    }

    /**
     * @return Collection<int, EmailTemplate>
     */
    #[Computed]
    public function templates(): Collection
    {
        return EmailTemplate::query()->inWorkspace($this->deal()->workspaceRef())->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Person>
     */
    #[Computed]
    public function workspacePeople(): Collection
    {
        return Person::query()->inWorkspace($this->deal()->workspaceRef())->orderBy('last_name')->orderBy('first_name')->limit(300)->get();
    }

    /**
     * @return array<string, array{key: string, label: string, type: string, options?: list<string>}>
     */
    public function customFields(): array
    {
        return collect(CustomFields::of($this->deal()->workspaceRef()))->keyBy('key')->all();
    }

    public function canDelete(): bool
    {
        return Gate::forUser($this->viewer())->allows('delete', $this->deal());
    }
}
