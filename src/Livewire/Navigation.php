<?php

namespace Sellinnate\SalesCrm\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\Activity;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * The navigation of the CRM pages, with the counter of the activities due today or overdue.
 */
final class Navigation
{
    /**
     * @return list<array{key: string, label: string, url: string, icon: string, active: bool, count?: int}>
     */
    public static function items(): array
    {
        $user = auth()->user();
        $access = app(Access::class);

        if (! $user instanceof Authenticatable || ! $access->canAccess($user)) {
            return [];
        }

        $manages = ! $access->resolver()->enabled()
            ? $access->canManage($user, null)
            : collect($access->workspacesFor($user))->contains(fn ($workspace): bool => $access->canManage($user, $workspace));

        $items = [
            ['key' => 'deals', 'route' => 'sales-crm.deals', 'icon' => 'kanban', 'match' => ['sales-crm.deals', 'sales-crm.deals.show']],
            ['key' => 'agenda', 'route' => 'sales-crm.agenda', 'icon' => 'calendar-clock', 'match' => ['sales-crm.agenda']],
            ['key' => 'companies', 'route' => 'sales-crm.companies', 'icon' => 'building', 'match' => ['sales-crm.companies', 'sales-crm.companies.show']],
            ['key' => 'people', 'route' => 'sales-crm.people', 'icon' => 'users', 'match' => ['sales-crm.people', 'sales-crm.people.show']],
            ['key' => 'reports', 'route' => 'sales-crm.reports', 'icon' => 'chart', 'match' => ['sales-crm.reports']],
        ];

        if ($manages) {
            $items[] = ['key' => 'import', 'route' => 'sales-crm.import', 'icon' => 'upload', 'match' => ['sales-crm.import']];
        }

        $items[] = ['key' => 'settings', 'route' => 'sales-crm.settings', 'icon' => 'settings', 'match' => ['sales-crm.settings']];

        $current = request()->route()?->getName();
        $navigation = [];

        foreach ($items as $item) {
            if (! Route::has($item['route'])) {
                continue;
            }

            $entry = [
                'key' => $item['key'],
                'label' => (string) __('sales-crm::ui.nav.'.$item['key']),
                'url' => route($item['route']),
                'icon' => $item['icon'],
                'active' => in_array($current, $item['match'], true),
            ];

            if ($item['key'] === 'agenda') {
                $entry['count'] = self::dueCount($user);
            }

            $navigation[] = $entry;
        }

        return $navigation;
    }

    /**
     * The open activities of the user due today or overdue.
     */
    public static function dueCount(Authenticatable $user): int
    {
        return Activity::query()
            ->visibleTo($user)
            ->whereNull('done_at')
            ->where('assigned_to', Crm::userKey($user))
            ->where('due_at', '<=', CarbonImmutable::now()->endOfDay())
            ->count();
    }
}
