<?php

namespace Sellinnate\SalesCrm\Livewire\Concerns;

use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The workspace filter and the workspace of a new record of the lists of companies and people.
 */
trait ListsRecords
{
    /**
     * The workspace a new record goes in: the one of the filter, else the first of the user (null in
     * the shared CRM).
     */
    /**
     * The workspace a new record starts in: the one of the filter, else the first one the form shows,
     * so what the select shows is where the record goes.
     */
    protected function draftWorkspace(): string
    {
        if (! $this->access()->resolver()->enabled() || $this->workspaceFromKey($this->workspace) !== null) {
            return $this->workspace;
        }

        return (string) array_key_first($this->workspaceOptions());
    }

    protected function targetWorkspace(): ?Workspace
    {
        if (! $this->access()->resolver()->enabled()) {
            return null;
        }

        return $this->workspaceFromKey((string) ($this->draft['workspace'] ?? $this->workspace))
            ?? ($this->access()->workspacesFor(auth()->user())[0] ?? null);
    }
}
