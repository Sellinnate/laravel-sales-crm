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
use Illuminate\Support\Testing\Fakes\MailFake;
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

    // Longer than a slow SMTP server, shorter than the lock of the email.
    public int $timeout = 90;

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

            if ($email === null) {
                return;
            }

            // Still "sending" from an attempt that stopped halfway (the worker killed during the SMTP
            // conversation): whether it left is unknown, so it is closed as not sent, never sent again.
            if ($email->status === EmailStatus::Sending) {
                $this->markFailed($email, (string) __('sales-crm::ui.email_unknown'));

                return;
            }

            // Claimed before the mailer sees it: once the message may have left, no retry sends it again.
            if (Email::query()->whereKey($email->id)->where('status', EmailStatus::Queued->value)->update(['status' => EmailStatus::Sending->value]) === 0) {
                return;
            }

            try {
                $sent = Mail::mailer($this->mailer)->send(new DealEmail($email));
            } catch (Throwable $exception) {
                // Refused by the mailer: not sent, back in the queue for the next attempt.
                Email::query()->whereKey($email->id)->where('status', EmailStatus::Sending->value)->update(['status' => EmailStatus::Queued->value]);

                throw $exception;
            }

            // Vetoed by a listener of the host (MessageSending answered false): nothing left. (Mail::fake()
            // answers null to every send: the tests of the host keep their emails sent.)
            if ($sent === null && ! Mail::getFacadeRoot() instanceof MailFake) {
                $this->markFailed($email, (string) __('sales-crm::ui.email_vetoed'));

                return;
            }

            $now = CarbonImmutable::now();

            try {
                retry(3, fn () => $email->forceFill(['status' => EmailStatus::Sent, 'sent_at' => $now, 'error' => null])->save(), 200);
            } catch (Throwable $exception) {
                // Sent, though not recorded (the database away): it stays "sending", never sent twice.
                report($exception);

                return;
            }

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

        if ($email === null || ! in_array($email->status, [EmailStatus::Queued, EmailStatus::Sending], true)) {
            return;
        }

        // Still "sending" (the last attempt timed out or its worker died): whether it left is unknown.
        $this->markFailed($email, (string) __($email->status === EmailStatus::Sending ? 'sales-crm::ui.email_unknown' : 'sales-crm::ui.email_failed'));
    }

    private function markFailed(Email $email, string $reason): void
    {
        $email->forceFill(['status' => EmailStatus::Failed, 'error' => Str::limit($reason, 500)])->save();

        EmailFailed::dispatch($email);
    }
}
