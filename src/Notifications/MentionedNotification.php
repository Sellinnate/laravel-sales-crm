<?php

namespace Sellinnate\SalesCrm\Notifications;

use Illuminate\Contracts\Auth\Authenticatable;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Note;

/**
 * A mention in a note, with the link to its deal, company or person; never the text of the note.
 */
class MentionedNotification extends CrmNotification
{
    public function __construct(public Note $note, public ?string $author = null)
    {
        parent::__construct();
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $deal = $this->note->deal_id === null ? null : Deal::query()->find($this->note->deal_id);

        // Never the title of a deal the user doesn't see (deal_visibility "owner", an Authorizer of the host).
        return $notifiable instanceof Authenticatable && app(Access::class)->worksOn($notifiable, $this->note)
            && ($this->note->deal_id === null || ($deal !== null && app(Access::class)->canSeeDeal($notifiable, $deal)));
    }

    protected function title(): string
    {
        return (string) __('sales-crm::notifications.mentioned', ['author' => $this->author ?? (string) __('sales-crm::notifications.colleague'), 'subject' => $this->subject()]);
    }

    protected function line(): string
    {
        return (string) __('sales-crm::notifications.mentioned_line', ['author' => $this->author ?? (string) __('sales-crm::notifications.colleague')]);
    }

    protected function url(): ?string
    {
        return match (true) {
            $this->note->deal_id !== null => self::route('sales-crm.deals.show', ['deal' => $this->note->deal_id]),
            $this->note->company_id !== null => self::route('sales-crm.companies.show', ['company' => $this->note->company_id]),
            $this->note->person_id !== null => self::route('sales-crm.people.show', ['person' => $this->note->person_id]),
            default => self::route('sales-crm.deals'),
        };
    }

    protected function data(): array
    {
        return ['note_id' => $this->note->id, 'deal_id' => $this->note->deal_id];
    }

    private function subject(): string
    {
        return $this->note->deal->title ?? $this->note->company->name ?? $this->note->person->name ?? (string) config('sales-crm.ui.brand', 'Sales CRM');
    }
}
