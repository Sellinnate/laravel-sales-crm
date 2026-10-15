@php
    use Sellinnate\SalesCrm\Support\Format;

    $report = $this->report;
    [$from, $to] = $this->period();
    $maxStage = max(1, collect($report['open'])->max('value') ?? 1);
    $maxForecast = max(1, collect($report['forecast'])->max('value') ?? 1);
@endphp
<div>
    <x-sales-crm::shell :title="__('sales-crm::ui.nav.reports')" :subtitle="__('sales-crm::ui.reports.period', ['from' => Format::date($from), 'to' => Format::date($to)])">
        <div class="mb-4 flex flex-wrap items-end gap-2" data-test="reports-filters">
            @if ($this->workspaces !== [])
                <x-sales-crm::field :label="__('sales-crm::ui.workspace')">
                    <select class="crm-select w-56" wire:model.live="workspace">
                        <option value="">{{ __('sales-crm::ui.all_workspaces') }}</option>
                        @foreach ($this->workspaces as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </select>
                </x-sales-crm::field>
            @endif
            @if ($this->owners !== [])
                <x-sales-crm::field :label="__('sales-crm::ui.columns.owner')">
                    <select class="crm-select w-48" wire:model.live="owner" data-test="reports-owner">
                        <option value="">{{ __('sales-crm::ui.any_owner') }}</option>
                        @foreach ($this->owners as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                </x-sales-crm::field>
            @endif
            <x-sales-crm::field :label="__('sales-crm::ui.reports.from')"><input type="date" class="crm-input" wire:model.live="from" value="{{ $from->toDateString() }}"></x-sales-crm::field>
            <x-sales-crm::field :label="__('sales-crm::ui.reports.to')"><input type="date" class="crm-input" wire:model.live="to" value="{{ $to->toDateString() }}"></x-sales-crm::field>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-test="reports-stats">
            <div class="crm-stat"><span class="crm-stat-label">{{ __('sales-crm::ui.reports.open_pipeline') }}</span><span class="crm-stat-value" data-test="report-open-value">{{ Format::money($report['open_total']['value']) }}</span><span class="text-xs text-muted">{{ trans_choice('sales-crm::ui.reports.deals_count', $report['open_total']['count'], ['count' => $report['open_total']['count']]) }} · {{ __('sales-crm::ui.columns.weighted') }} {{ Format::money($report['open_total']['weighted']) }}</span></div>
            <div class="crm-stat"><span class="crm-stat-label">{{ __('sales-crm::ui.reports.won') }}</span><span class="crm-stat-value text-success" data-test="report-won-value">{{ Format::money($report['won']['value']) }}</span><span class="text-xs text-muted">{{ trans_choice('sales-crm::ui.reports.deals_count', $report['won']['count'], ['count' => $report['won']['count']]) }}</span></div>
            <div class="crm-stat"><span class="crm-stat-label">{{ __('sales-crm::ui.reports.lost') }}</span><span class="crm-stat-value text-danger">{{ Format::money($report['lost']['value']) }}</span><span class="text-xs text-muted">{{ trans_choice('sales-crm::ui.reports.deals_count', $report['lost']['count'], ['count' => $report['lost']['count']]) }}</span></div>
            <div class="crm-stat"><span class="crm-stat-label">{{ __('sales-crm::ui.reports.win_rate') }}</span><span class="crm-stat-value" data-test="report-win-rate">{{ Format::percent($report['win_rate']) }}</span><span class="text-xs text-muted">{{ __('sales-crm::ui.reports.win_rate_hint') }}</span></div>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <section class="crm-card crm-card-body" data-test="report-stages">
                <h2 class="crm-section-title mb-3">{{ __('sales-crm::ui.reports.by_stage') }}</h2>
                @forelse ($report['open'] as $row)
                    <div class="mb-2">
                        <div class="flex justify-between text-[13px]"><span>{{ $row['stage'] }} <span class="text-xs text-muted">({{ $row['count'] }})</span></span><span class="crm-num">{{ Format::money($row['value']) }} <span class="text-xs text-muted">· {{ Format::money($row['weighted']) }}</span></span></div>
                        <div class="mt-1 h-2 rounded bg-sunken"><div class="h-2 rounded bg-brand" style="width: {{ round($row['value'] / $maxStage * 100, 1) }}%"></div></div>
                    </div>
                @empty
                    <p class="text-xs text-muted">{{ __('sales-crm::ui.reports.empty') }}</p>
                @endforelse
            </section>

            <section class="crm-card crm-card-body" data-test="report-forecast">
                <h2 class="crm-section-title mb-3">{{ __('sales-crm::ui.reports.forecast') }}</h2>
                <div class="flex h-40 gap-2">
                    @foreach ($report['forecast'] as $month)
                        <div class="flex flex-1 flex-col items-center gap-1" title="{{ Format::money($month['value']) }} · {{ __('sales-crm::ui.columns.weighted') }} {{ Format::money($month['weighted']) }}">
                            <div class="relative flex w-full flex-1 items-end">
                                <div class="w-full rounded-t bg-brand/25" style="height: {{ round($month['value'] / $maxForecast * 100, 1) }}%"></div>
                                <div class="absolute bottom-0 w-full rounded-t bg-brand" style="height: {{ round($month['weighted'] / $maxForecast * 100, 1) }}%"></div>
                            </div>
                            <span class="text-[11px] text-muted">{{ Format::month($month['month']) }}</span>
                        </div>
                    @endforeach
                </div>
                <p class="mt-2 text-xs text-muted">{{ __('sales-crm::ui.reports.forecast_hint') }}</p>
            </section>

            <section class="crm-card crm-card-body" data-test="report-attention">
                <h2 class="crm-section-title mb-3">{{ __('sales-crm::ui.reports.attention') }}</h2>
                <dl class="grid grid-cols-2 gap-3 text-[13px]">
                    @foreach ($report['attention'] as $key => $count)
                        <div class="rounded-md border border-line p-3"><dt class="text-xs text-muted">{{ __('sales-crm::ui.reports.attention_'.$key) }}</dt><dd class="text-lg font-semibold crm-num @if ($count > 0) text-warning @endif">{{ $count }}</dd></div>
                    @endforeach
                </dl>
            </section>

            <section class="crm-card crm-card-body" data-test="report-lost-reasons">
                <h2 class="crm-section-title mb-3">{{ __('sales-crm::ui.reports.lost_reasons') }}</h2>
                @forelse ($report['lost_reasons'] as $row)
                    <div class="flex justify-between border-b border-line py-1.5 text-[13px] last:border-0"><span>{{ $row['reason'] }}</span><span class="crm-num">{{ $row['count'] }}</span></div>
                @empty
                    <p class="text-xs text-muted">{{ __('sales-crm::ui.reports.empty') }}</p>
                @endforelse
            </section>

            <section class="crm-card crm-card-body" data-test="report-stage-days">
                <h2 class="crm-section-title mb-3">{{ __('sales-crm::ui.reports.stage_days') }}</h2>
                @forelse ($report['stage_days'] as $row)
                    <div class="flex justify-between border-b border-line py-1.5 text-[13px] last:border-0"><span>{{ $row['stage'] }}</span><span class="crm-num">{{ __('sales-crm::ui.reports.days', ['days' => Format::number($row['days'], 1)]) }} <span class="text-xs text-muted">({{ $row['visits'] }})</span></span></div>
                @empty
                    <p class="text-xs text-muted">{{ __('sales-crm::ui.reports.empty') }}</p>
                @endforelse
            </section>

            <section class="crm-card crm-card-body" data-test="report-sources">
                <h2 class="crm-section-title mb-3">{{ __('sales-crm::ui.reports.sources') }}</h2>
                <table class="crm-table">
                    <thead><tr><th>{{ __('sales-crm::ui.columns.source') }}</th><th class="text-right">{{ __('sales-crm::ui.reports.open') }}</th><th class="text-right">{{ __('sales-crm::ui.reports.won') }}</th><th class="text-right">%</th></tr></thead>
                    <tbody>
                        @forelse ($report['sources'] as $row)
                            <tr><td>{{ $row['source'] }}</td><td class="crm-num text-right">{{ Format::money($row['open_value']) }}</td><td class="crm-num text-right">{{ Format::money($row['won_value']) }}</td><td class="crm-num text-right">{{ Format::percent($row['share']) }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="text-xs text-muted">{{ __('sales-crm::ui.reports.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
                @if ($report['integrations']['count'] > 0)
                    <p class="mt-2 text-xs text-muted">{{ __('sales-crm::ui.reports.integrations', ['count' => $report['integrations']['count'], 'won' => Format::money($report['integrations']['won_value']), 'share' => Format::percent($report['integrations']['share'])]) }}</p>
                @endif
            </section>

            <section class="crm-card crm-card-body" data-test="report-owners">
                <h2 class="crm-section-title mb-3">{{ __('sales-crm::ui.reports.owners') }}</h2>
                <table class="crm-table">
                    <thead><tr><th>{{ __('sales-crm::ui.columns.owner') }}</th><th class="text-right">{{ __('sales-crm::ui.reports.open') }}</th><th class="text-right">{{ __('sales-crm::ui.reports.won') }}</th><th class="text-right">{{ __('sales-crm::ui.reports.win_rate') }}</th></tr></thead>
                    <tbody>
                        @forelse ($report['owners'] as $row)
                            <tr><td>{{ $row['owner'] }}</td><td class="crm-num text-right">{{ $row['open'] }} · {{ Format::money($row['open_value']) }}</td><td class="crm-num text-right">{{ $row['won'] }} · {{ Format::money($row['won_value']) }}</td><td class="crm-num text-right">{{ Format::percent($row['win_rate']) }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="text-xs text-muted">{{ __('sales-crm::ui.reports.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>

            <section class="crm-card crm-card-body" data-test="report-activities">
                <h2 class="crm-section-title mb-3">{{ __('sales-crm::ui.reports.activities') }}</h2>
                <table class="crm-table">
                    <thead><tr><th>{{ __('sales-crm::fields.activity.assigned_to') }}</th><th class="text-right">{{ __('sales-crm::ui.reports.done') }}</th><th class="text-right">{{ __('sales-crm::ui.overdue') }}</th></tr></thead>
                    <tbody>
                        @forelse ($report['activities'] as $row)
                            <tr><td>{{ $row['user'] }}</td><td class="crm-num text-right">{{ $row['done'] }}</td><td class="crm-num text-right @if ($row['overdue'] > 0) text-danger @endif">{{ $row['overdue'] }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="text-xs text-muted">{{ __('sales-crm::ui.reports.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        </div>
    </x-sales-crm::shell>
</div>
