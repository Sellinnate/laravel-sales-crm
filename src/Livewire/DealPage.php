<?php

namespace Sellinnate\SalesCrm\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Sellinnate\SalesCrm\Livewire\Concerns\InteractsWithCrm;
use Sellinnate\SalesCrm\Models\Deal;

/**
 * A deal on its own page: the target of the links of notifications, mentions and emails.
 */
class DealPage extends Component
{
    use InteractsWithCrm;

    #[Locked]
    public int $dealId;

    public function mount(Deal $deal): void
    {
        Gate::forUser($this->viewer())->authorize('view', $deal);
        $this->dealId = $deal->id;
    }

    public function render(): View
    {
        $deal = Deal::query()->visibleTo($this->viewer())->findOrFail($this->dealId);

        return $this->page('sales-crm::livewire.deal-page', $deal->title, ['deal' => $deal]);
    }
}
