<?php

namespace Sellinnate\SalesCrm\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\Note;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * Notes are read and pinned by whoever works in the workspace, changed by their author, deleted by
 * their author or a manager.
 */
class NotePolicy
{
    public function __construct(protected Access $access) {}

    public function view(Authenticatable $user, Note $note): bool
    {
        return $this->access->worksOn($user, $note) && ($note->deal === null || $this->access->canSeeDeal($user, $note->deal));
    }

    public function pin(Authenticatable $user, Note $note): bool
    {
        return $this->view($user, $note);
    }

    public function update(Authenticatable $user, Note $note): bool
    {
        return $this->view($user, $note) && Crm::sameUser($note->user_id, Crm::userKey($user));
    }

    public function delete(Authenticatable $user, Note $note): bool
    {
        return $this->view($user, $note) && (Crm::sameUser($note->user_id, Crm::userKey($user)) || $this->access->canManage($user, $note->workspaceRef()));
    }
}
