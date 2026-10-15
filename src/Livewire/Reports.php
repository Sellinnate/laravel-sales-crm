<?php

namespace Sellinnate\SalesCrm\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Sellinnate\SalesCrm\Livewire\Concerns\InteractsWithCrm;
use Sellinnate\SalesCrm\Services\Reports as ReportService;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * The dashboard: open pipeline by stage, forecast, won and lost with win rate and reasons, time per
 * stage, what needs attention, activities, owners and the value by source.
 *
 * @phpstan-import-type Report from ReportService
 */
class Reports extends Component
{
    use InteractsWithCrm;

    #[Url(except: '')]
    public string $workspace = '';

    #[Url(except: '')]
    public string $owner = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function mount(): void
    {
        $this->viewer();
        [$from, $to] = $this->period();
        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    /**
     * The owners of another workspace: a filter left behind would stay on without its select.
     */
    public function updatedWorkspace(): void
    {
        $this->owner = '';
    }

    public function render(): View
    {
        return $this->page('sales-crm::livewire.reports', (string) __('sales-crm::ui.nav.reports'));
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function period(): array
    {
        $from = $this->date($this->from) ?? CarbonImmutable::now()->subDays(89)->startOfDay();
        $to = $this->date($this->to) ?? CarbonImmutable::now()->endOfDay();

        return $from->gt($to) ? [$to, $from] : [$from, $to];
    }

    private function date(string $value): ?CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: null : null;
    }

    /**
     * @return Report
     */
    #[Computed]
    public function report(): array
    {
        [$from, $to] = $this->period();
        $workspace = $this->access()->resolver()->enabled() ? ($this->workspace === '' ? false : $this->workspaceFromKey($this->workspace) ?? false) : null;

        // From the URL: a value that can't be a user key is no filter (PostgreSQL would refuse it).
        return app(ReportService::class)->build($this->viewer(), $workspace, Crm::isUserKey($this->owner) ? $this->owner : null, $from, $to);
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
     * @return array<string, string>
     */
    #[Computed]
    public function owners(): array
    {
        $workspace = $this->workspace === '' ? null : $this->workspaceFromKey($this->workspace);

        if ($this->access()->resolver()->enabled() && $workspace === null) {
            return [];
        }

        return $this->access()->members($workspace)->limit(500)->get()
            ->mapWithKeys(fn ($user): array => [(string) Crm::userKey($user) => Crm::userName($user)])->all();
    }
}
