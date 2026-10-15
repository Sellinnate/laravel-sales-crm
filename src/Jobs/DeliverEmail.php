<?php

namespace Sellinnate\SalesCrm\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Sellinnate\SalesCrm\Enums\EmailStatus;
use Sellinnate\SalesCrm\Events\EmailFailed;
use Sellinnate\SalesCrm\Events\EmailSent;
use Sellinnate\SalesCrm\Mail\DealEmail;
use Sellinnate\SalesCrm\Models\Email;
use Sellinnate\SalesCrm\Services\DealActivityState;
use Throwable;

/**
 * An email of a deal leaves through Laravel's mailer, once (under the lock of the email): sent, it
 * moves the last contact of the deal; failed after the retries, it stays "not sent" with a reason
 * that never quotes the exception (which goes to the error report).
 */
class DeliverEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $emailId, public ?string $mailer = null)
    {
        $queue = config('sales-crm.email.queue');

        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }

        $this->afterCommit();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(DealActivityState $state): void
    {
        Cache::lock('sales-crm:email:'.$this->emailId, 120)->block(30, function () use ($state): void {
            $email = Email::query()->find($this->emailId);

            if ($email === null || $email->status !== EmailStatus::Queued) {
                return;
            }

            Mail::mailer($this->mailer)->send(new DealEmail($email));

            $now = CarbonImmutable::now();
            $email->forceFill(['status' => EmailStatus::Sent, 'sent_at' => $now, 'error' => null])->save();

            try {
                if ($email->deal_id !== null) {
                    $state->refresh($email->deal_id, $now, $now);
                }
            } catch (Throwable $exception) {
                // Sent is sent: the last contact of the deal never makes the email go twice.
                report($exception);
            }

            EmailSent::dispatch($email);
        });
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }

        $email = Email::query()->find($this->emailId);

        if ($email === null || $email->status !== EmailStatus::Queued) {
            return;
        }

        $email->forceFill(['status' => EmailStatus::Failed, 'error' => Str::limit((string) __('sales-crm::ui.email_failed'), 500)])->save();

        EmailFailed::dispatch($email);
    }
}
