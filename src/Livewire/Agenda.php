<?php

namespace Sellinnate\SalesCrm\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Sellinnate\SalesCrm\Actions\ManageActivities;
use Sellinnate\SalesCrm\Enums\ActivityType;
use Sellinnate\SalesCrm\Livewire\Concerns\InteractsWithCrm;
use Sellinnate\SalesCrm\Models\Activity;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * The agenda of the user: the activities overdue, of today and of the next seven days, completed in
 * one click, with links to their deal, company or person.
 */
class Agenda extends Component
{
    use InteractsWithCrm;

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $workspace = '';

    #[Url(except: 'mine')]
    public string $scope = 'mine';

    public function mount(): void
    {
        $this->viewer();

        if (! in_array($this->type, ActivityType::values(), true)) {
            $this->type = '';
        }
    }

    public function render(): View
    {
        return $this->page('sales-crm::livewire.agenda', (string) __('sales-crm::ui.nav.agenda'));
    }

    public function complete(int $activityId): void
    {
        $activity = Activity::query()->visibleTo($this->viewer())->find($activityId);

        if ($activity !== null) {
            app(ManageActivities::class)->complete($activity, null, $this->viewer());
            unset($this->groups);
            $this->notify((string) __('sales-crm::ui.activity_done'));
        }
    }

    /**
     * @return array<string, Collection<int, Activity>>
     */
    #[Computed]
    public function groups(): array
    {
        $now = CarbonImmutable::now();
        $query = fn (): Builder => Activity::query()
            ->visibleTo($this->viewer())
            ->whereNull('done_at')
            ->when($this->scope === 'mine', fn (Builder $query) => $query->where('assigned_to', Crm::userKey($this->viewer())))
            ->when($this->type !== '', fn (Builder $query) => $query->where('type', $this->type))
            ->when($this->workspace !== '' && ($workspace = $this->workspaceFromKey($this->workspace)) !== null, fn (Builder $query) => $query->inWorkspace($this->workspaceFromKey($this->workspace)))
            // The primary contact of the deal comes with it: no query per activity in the view.
            ->with(['deal.people', 'company', 'person', 'assignee']);

        // Each its own cap: many late activities never hide today and the next days; the latest late first.
        $late = $query()->where('due_at', '<', $now->startOfDay())->orderByDesc('due_at')->limit(200)->get();
        $coming = $query()->where('due_at', '>=', $now->startOfDay())->where('due_at', '<=', $now->addDays(7)->endOfDay())->orderBy('due_at')->limit(300)->get();
        $activities = $late->reverse()->concat($coming)->values();

        return [
            'overdue' => $activities->filter(fn (Activity $activity): bool => $activity->state($now) === 'overdue')->values(),
            'today' => $activities->filter(fn (Activity $activity): bool => $activity->state($now) === 'today')->values(),
            'upcoming' => $activities->filter(fn (Activity $activity): bool => $activity->state($now) === 'planned')->values(),
        ];
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
