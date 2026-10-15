<div>
    <x-sales-crm::shell>
        <a href="{{ route('sales-crm.deals') }}" class="mb-3 inline-flex items-center gap-1 text-[13px] text-muted hover:text-ink"><x-sales-crm::icon name="arrow-left" :size="14" /> {{ __('sales-crm::ui.back_to_deals') }}</a>
        <div class="crm-card mx-auto max-w-4xl" data-test="deal-page">
            <livewire:sales-crm.deal-panel :deal-id="$deal->id" :page="true" :key="'deal-page-'.$deal->id" />
        </div>
    </x-sales-crm::shell>
</div>
