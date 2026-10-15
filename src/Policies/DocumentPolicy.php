<?php

namespace Sellinnate\SalesCrm\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\Document;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * Documents are downloaded by whoever works in the workspace (and sees their deal), deleted by who
 * uploaded them or a manager.
 */
class DocumentPolicy
{
    public function __construct(protected Access $access) {}

    public function view(Authenticatable $user, Document $document): bool
    {
        return $this->access->worksOn($user, $document) && ($document->deal === null || $this->access->canSeeDeal($user, $document->deal));
    }

    public function delete(Authenticatable $user, Document $document): bool
    {
        return $this->view($user, $document) && (Crm::sameUser($document->uploaded_by, Crm::userKey($user)) || $this->access->canManage($user, $document->workspaceRef()));
    }
}
