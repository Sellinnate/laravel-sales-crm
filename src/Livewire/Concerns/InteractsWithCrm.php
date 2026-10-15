<?php

namespace Sellinnate\SalesCrm\Livewire\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * What every component of the CRM UI shares: the signed-in user, the scope, the layout of the pages,
 * the toasts and the errors of the actions shown next to the fields of the component.
 */
trait InteractsWithCrm
{
    protected function viewer(): Authenticatable
    {
        $user = auth()->user();

        abort_unless($user instanceof Authenticatable && app(Access::class)->canAccess($user), 403);

        return $user;
    }

    protected function access(): Access
    {
        return app(Access::class);
    }

    /**
     * A page of the CRM in the configured layout.
     *
     * @param  array<string, mixed>  $data
     */
    protected function page(string $view, string $title, array $data = []): View
    {
        /** @var view-string $view */
        /** @var \Illuminate\View\View $page */
        $page = view($view, $data);
        /** @var view-string $layout */
        $layout = (string) config('sales-crm.ui.layout', 'sales-crm::layouts.app');

        return $page->layout($layout, ['title' => $title])->title($title);
    }

    protected function notify(string $message, string $tone = 'success'): void
    {
        $this->dispatch('crm-notify', message: $message, tone: $tone);
    }

    /**
     * Show the errors of an action on the fields of the component, under a prefix (`draft.`, `fill.`).
     */
    protected function showErrors(ValidationException $exception, string $prefix = ''): void
    {
        foreach ($exception->errors() as $key => $messages) {
            foreach ($messages as $message) {
                $this->addError($prefix.$key, $message);
            }
        }
    }

    /**
     * Whether the user configures the CRM of the workspace.
     */
    protected function canManage(?Workspace $workspace): bool
    {
        return $this->access()->canManage(auth()->user(), $workspace);
    }

    /**
     * Whether the user configures at least one workspace (or the shared CRM).
     */
    protected function managesAny(): bool
    {
        $access = $this->access();
        $user = auth()->user();

        if (! $access->resolver()->enabled()) {
            return $access->canManage($user, null);
        }

        foreach ($access->workspacesFor($user) as $workspace) {
            if ($access->canManage($user, $workspace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The workspaces of the user, key => label (empty without workspaces).
     *
     * @return array<string, string>
     */
    protected function workspaceOptions(): array
    {
        $options = [];

        foreach ($this->access()->workspacesFor(auth()->user()) as $workspace) {
            $options[$workspace->key()] = $this->access()->label($workspace);
        }

        asort($options);

        return $options;
    }

    /**
     * A workspace key the user works in, or null (the shared CRM, or none chosen).
     */
    protected function workspaceFromKey(string $key): ?Workspace
    {
        $workspace = Workspace::fromKey($key);

        return $workspace !== null && $this->access()->works(auth()->user(), $workspace) ? $workspace : null;
    }
}
