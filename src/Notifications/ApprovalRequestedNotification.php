<?php

namespace Sellinnate\SalesCrm\Notifications;

use Illuminate\Contracts\Auth\Authenticatable;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Enums\ApprovalStatus;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * The approval of a deal asked to its approver: delivered while it is still pending and theirs.
 */
class ApprovalRequestedNotification extends CrmNotification
{
    public function __construct(public Deal $deal, public ?string $author = null)
    {
        parent::__construct();
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $current = Deal::query()->whereKey($this->deal->getKey())->first();

        return $notifiable instanceof Authenticatable && $current !== null
            && $current->approval_status === ApprovalStatus::Pending
            && Crm::sameUser($current->approver_id, Crm::userKey($notifiable))
            && app(Access::class)->canSeeDeal($notifiable, $current);
    }

    protected function title(): string
    {
        return (string) __('sales-crm::notifications.approval', ['deal' => $this->deal->title]);
    }

    protected function line(): string
    {
        return (string) __('sales-crm::notifications.approval_line', ['author' => $this->author ?? (string) __('sales-crm::notifications.colleague')]);
    }

    protected function url(): ?string
    {
        return self::route('sales-crm.deals.show', ['deal' => $this->deal->id]);
    }

    protected function data(): array
    {
        return ['deal_id' => $this->deal->id];
    }
}
