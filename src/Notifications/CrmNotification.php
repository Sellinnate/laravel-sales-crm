<?php

namespace Sellinnate\SalesCrm\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * The base of the notifications of the CRM: the channels of `sales-crm.notifications.channels`, a
 * mail with a title, a line and a link, and the same data for the database channel. Texts of notes
 * and emails never go in a notification.
 */
abstract class CrmNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $queue = config('sales-crm.notifications.queue');

        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }

        $this->afterCommit();
    }

    abstract protected function title(): string;

    abstract protected function line(): string;

    abstract protected function url(): ?string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function data(): array;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = config('sales-crm.notifications.channels', ['database', 'mail']);

        return array_values(array_filter(is_array($channels) ? $channels : [], 'is_string'));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject($this->title())->line($this->line());
        $url = $this->url();

        return $url === null ? $message : $message->action((string) __('sales-crm::notifications.open'), $url);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title(), 'description' => $this->line(), 'url' => $this->url(), ...$this->data()];
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    protected static function route(string $name, array $parameters = []): ?string
    {
        return Route::has($name) ? route($name, $parameters) : null;
    }
}
