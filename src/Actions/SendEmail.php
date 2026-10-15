<?php

namespace Sellinnate\SalesCrm\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Contracts\SenderResolver;
use Sellinnate\SalesCrm\Enums\EmailDirection;
use Sellinnate\SalesCrm\Enums\EmailStatus;
use Sellinnate\SalesCrm\Events\EmailQueued;
use Sellinnate\SalesCrm\Jobs\DeliverEmail;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Email;
use Sellinnate\SalesCrm\Models\Person;
use Sellinnate\SalesCrm\Services\EmailTemplates;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Support\MessageId;

/**
 * An email from a deal: to its people or other addresses (at most `sales-crm.email.max_recipients`
 * in To and Cc), with the variables of the deal written in. It enters the timeline as queued and
 * leaves through Laravel's mailer ({@see DeliverEmail}) with a new Message-ID and, when it answers an
 * email of the deal, the In-Reply-To and References of its thread. One email at a time: no mass mailing.
 */
class SendEmail
{
    private const MAX_REFERENCES = 20;

    public function __construct(private EmailTemplates $templates, private SenderResolver $senders) {}

    /**
     * @param  array<string, mixed>  $data  to, cc (lists or comma separated), subject, body, reply_to_id
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Deal $deal, array $data, Authenticatable $by): Email
    {
        Gate::forUser($by)->authorize('update', $deal);

        if (! (bool) config('sales-crm.email.enabled', true)) {
            throw ValidationException::withMessages(['to' => __('sales-crm::validation.email_disabled')]);
        }

        $data = [
            ...$data,
            'to' => self::addresses($data['to'] ?? []),
            'cc' => self::addresses($data['cc'] ?? []),
            'subject' => trim(is_scalar($data['subject'] ?? null) ? (string) $data['subject'] : ''),
            'body' => trim(is_scalar($data['body'] ?? null) ? (string) $data['body'] : ''),
        ];
        $max = max(1, (int) config('sales-crm.email.max_recipients', 10));

        /** @var array{to: list<string>, cc: list<string>, subject: string, body: string, reply_to_id?: int|string|null} $validated */
        $validated = Validator::make($data, [
            'to' => ['required', 'array', 'min:1'],
            'to.*' => ['email', 'max:191'],
            'cc' => ['array'],
            'cc.*' => ['email', 'max:191'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'reply_to_id' => ['nullable', 'integer'],
        ], [
            'to.required' => __('sales-crm::validation.email_recipient'),
            'to.*.email' => __('sales-crm::validation.email_address'),
            'cc.*.email' => __('sales-crm::validation.email_address'),
        ], [
            'to' => mb_strtolower((string) __('sales-crm::fields.email.to')),
            'cc' => mb_strtolower((string) __('sales-crm::fields.email.cc')),
            'subject' => mb_strtolower((string) __('sales-crm::fields.email.subject')),
            'body' => mb_strtolower((string) __('sales-crm::fields.email.body')),
        ])->after(function ($validator) use ($data, $max): void {
            if (count($data['to']) + count($data['cc']) > $max) {
                $validator->errors()->add('to', (string) __('sales-crm::validation.email_max_recipients', ['max' => $max]));
            }
        })->validate();

        $replyTo = filled($validated['reply_to_id'] ?? null) ? Email::query()->where('deal_id', $deal->id)->find((int) $validated['reply_to_id']) : null;

        if (filled($validated['reply_to_id'] ?? null) && $replyTo === null) {
            throw ValidationException::withMessages(['reply_to_id' => __('sales-crm::validation.email_reply')]);
        }

        $sender = $this->senders->resolve($by, $deal->workspaceRef());
        $person = $deal->people()->where(Crm::table('people').'.email', $validated['to'][0])->first();
        $subject = Str::limit(str_replace(["\r", "\n"], ' ', $this->templates->render($validated['subject'], $deal, $person, $by)), 252, '…');
        $body = $this->templates->render($validated['body'], $deal, $person, $by);

        return Crm::db()->transaction(function () use ($deal, $validated, $by, $replyTo, $person, $subject, $body, $sender): Email {
            $email = (new Email([
                'deal_id' => $deal->id,
                'person_id' => $person instanceof Person ? $person->id : null,
                'direction' => EmailDirection::Outbound,
                'status' => EmailStatus::Queued,
                'from_email' => Str::lower($sender->address),
                'from_name' => $sender->name,
                'reply_to' => $sender->replyTo,
                'to' => $validated['to'],
                'cc' => $validated['cc'] === [] ? null : $validated['cc'],
                'subject' => $subject,
                'body_text' => $body,
                'message_id' => MessageId::generate($sender->address),
                'in_reply_to' => $replyTo?->message_id,
                'references' => $replyTo === null ? null : implode(' ', array_slice($replyTo->thread(), -self::MAX_REFERENCES)),
                'sent_by' => Crm::userKey($by),
            ]))->setWorkspace($deal->workspaceRef());
            $email->save();

            DeliverEmail::dispatch($email->id, $sender->mailer);
            EmailQueued::dispatch($email, $by);

            return $email;
        });
    }

    /**
     * The addresses of a list or of a text separated by commas, semicolons or spaces, lowercase, once each.
     *
     * @return list<string>
     */
    public static function addresses(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/[,;\s]+/', is_scalar($value) ? (string) $value : '');

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $address): string => Str::lower(trim(is_scalar($address) ? (string) $address : '')),
            $items ?: [],
        ), fn (string $address): bool => $address !== '')));
    }
}
