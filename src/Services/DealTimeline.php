<?php

namespace Sellinnate\SalesCrm\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Sellinnate\SalesCrm\Contracts\TimelineProvider;
use Sellinnate\SalesCrm\Data\TimelineEntry;
use Sellinnate\SalesCrm\Enums\EmailDirection;
use Sellinnate\SalesCrm\Enums\EmailStatus;
use Sellinnate\SalesCrm\Models\Activity;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\DealEvent;
use Sellinnate\SalesCrm\Models\Document;
use Sellinnate\SalesCrm\Models\Email;
use Sellinnate\SalesCrm\Models\Note;
use Sellinnate\SalesCrm\Models\Person;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Support\Format;

/**
 * The single timeline of a deal, a company or a person: notes (pinned on top), activities done,
 * emails, changes of the history, documents and the read-only entries of the timeline providers of
 * the host, newest first, filterable by kind, with a counter per kind.
 */
class DealTimeline
{
    public const KINDS = ['note', 'activity', 'email', 'event', 'document'];

    public function __construct(private Container $container) {}

    /**
     * The kinds of the subject with their label: the built-in ones and those of the providers.
     *
     * @return array<string, string>
     */
    public function kinds(Deal|Company|Person $subject): array
    {
        $kinds = [];

        foreach (self::KINDS as $kind) {
            if (($kind === 'event' && ! $subject instanceof Deal) || ($kind === 'document' && $subject instanceof Person)) {
                continue;
            }

            $kinds[$kind] = (string) __('sales-crm::ui.timeline.'.$kind);
        }

        foreach ($this->providers() as $provider) {
            $kinds[$provider->kind()] = $provider->label();
        }

        return $kinds;
    }

    /**
     * How many entries of each kind the subject has: the counters of the filters, never capped by the
     * length of the timeline.
     *
     * @return array<string, int>
     */
    public function counts(Deal|Company|Person $subject, ?Authenticatable $viewer): array
    {
        $kinds = $this->kinds($subject);
        $counts = [];

        foreach (array_keys($kinds) as $kind) {
            $counts[$kind] = match ($kind) {
                'note' => Note::query()->tap(fn (Builder $query) => $this->scope($query, $subject))->count(),
                'activity' => Activity::query()->tap(fn (Builder $query) => $this->scope($query, $subject))->whereNotNull('done_at')->count(),
                'email' => $this->emails($subject)->count(),
                'event' => $subject instanceof Deal ? DealEvent::query()->where('deal_id', $subject->id)->count() : 0,
                'document' => Document::query()->tap(fn (Builder $query) => $this->scope($query, $subject))->count(),
                default => $this->provider($kind)?->count($subject, $viewer) ?? 0,
            };
        }

        return $counts;
    }

    /**
     * The entries, newest first (pinned notes on top), at most `$limit`.
     *
     * @return list<TimelineEntry>
     */
    public function for(Deal|Company|Person $subject, ?Authenticatable $viewer, ?string $kind = null, ?int $limit = null): array
    {
        $limit ??= max(10, (int) config('sales-crm.timeline.limit', 80));
        $wants = fn (string $one): bool => $kind === null || $kind === '' || $kind === $one;
        $kinds = $this->kinds($subject);
        $entries = [];

        if ($wants('note')) {
            // The pinned notes first, so the limit never leaves out one kept on top.
            $notes = Note::query()->tap(fn (Builder $query) => $this->scope($query, $subject))->with('author')
                ->orderByRaw('CASE WHEN pinned_at IS NULL THEN 1 ELSE 0 END')->latest('id')->limit($limit)->get();

            foreach ($notes as $note) {
                $entries[] = new TimelineEntry('note-'.$note->id, 'note', $note->created_at ?? CarbonImmutable::now(), 'notebook-pen', (string) __('sales-crm::ui.timeline.note_title'), $note->body, null, Crm::userName($note->author) ?: null, $note->pinned_at !== null, $note->id);
            }
        }

        if ($wants('activity')) {
            $activities = Activity::query()->tap(fn (Builder $query) => $this->scope($query, $subject))->whereNotNull('done_at')->with('doneBy')->latest('done_at')->limit($limit)->get();

            foreach ($activities as $activity) {
                $entries[] = new TimelineEntry('activity-'.$activity->id, 'activity', $activity->done_at ?? CarbonImmutable::now(), $activity->type->icon(), $activity->type->label().' · '.$activity->title, $activity->outcome, (string) __('sales-crm::ui.timeline.done'), Crm::userName($activity->doneBy) ?: null, false, $activity->id);
            }
        }

        if ($wants('email')) {
            // By the moment shown: an old email read late does not push the recent ones out.
            $emails = $this->emails($subject)->with('sender')->orderByRaw('COALESCE(sent_at, created_at) DESC')->latest('id')->limit($limit)->get();

            foreach ($emails as $email) {
                $title = $email->direction === EmailDirection::Inbound
                    ? (string) __('sales-crm::ui.timeline.email_from', ['from' => $email->from_name ?: $email->from_email])
                    : (string) __('sales-crm::ui.timeline.email_to', ['to' => implode(', ', $email->to)]);
                $meta = $email->status->label().($email->status === EmailStatus::Failed && filled($email->error) ? ': '.Str::limit((string) $email->error, 160) : '');

                $entries[] = new TimelineEntry('email-'.$email->id, 'email', $email->sent_at ?? $email->created_at ?? CarbonImmutable::now(), $email->direction->icon(), $title.' · '.$email->subject, $email->body_text, $meta, Crm::userName($email->sender) ?: ($email->direction === EmailDirection::Outbound ? $email->from_email : null), false, $email->id);
            }
        }

        if ($wants('event') && $subject instanceof Deal) {
            foreach (DealEvent::query()->where('deal_id', $subject->id)->with('user')->latest('id')->limit($limit)->get() as $event) {
                $from = is_scalar($event->from) ? (string) $event->from : null;
                $to = is_scalar($event->to) ? (string) $event->to : null;
                $change = trim(($from !== null ? $from.' → ' : '').($to ?? ''));

                $entries[] = new TimelineEntry('event-'.$event->id, 'event', $event->created_at ?? CarbonImmutable::now(), $event->type->icon(), $event->field !== null && $event->field !== 'stage_id' ? DealHistory::label($event->field) : $event->type->label(), $change === '' ? null : $change, null, Crm::userName($event->user) ?: (string) __('sales-crm::ui.system'), false, $event->id);
            }
        }

        if ($wants('document') && isset($kinds['document'])) {
            foreach (Document::query()->tap(fn (Builder $query) => $this->scope($query, $subject))->with('uploader')->latest('id')->limit($limit)->get() as $document) {
                $entries[] = new TimelineEntry(
                    'document-'.$document->id,
                    'document',
                    $document->created_at ?? CarbonImmutable::now(),
                    $document->kind->icon(),
                    $document->kind->label().($document->version > 1 ? ' v'.$document->version : '').' · '.$document->name,
                    $document->valid_until ? (string) __('sales-crm::ui.timeline.valid_until', ['date' => Format::date($document->valid_until)]) : null,
                    Format::date($document->document_date) ?: null,
                    Crm::userName($document->uploader) ?: null,
                    false,
                    $document->id,
                );
            }
        }

        foreach ($this->providers() as $provider) {
            if ($wants($provider->kind())) {
                foreach ($provider->entries($subject, $viewer, $limit) as $entry) {
                    $entries[] = $entry;
                }
            }
        }

        usort($entries, fn (TimelineEntry $a, TimelineEntry $b): int => [$b->pinned, $b->at->getTimestamp(), $b->key] <=> [$a->pinned, $a->at->getTimestamp(), $a->key]);

        return array_slice($entries, 0, $limit);
    }

    /**
     * @return list<TimelineProvider>
     */
    public function providers(): array
    {
        $providers = [];
        $configured = config('sales-crm.timeline.providers', []);

        foreach (is_array($configured) ? $configured : [] as $class) {
            if (is_string($class) && class_exists($class)) {
                $provider = $this->container->make($class);

                if ($provider instanceof TimelineProvider) {
                    $providers[] = $provider;
                }
            }
        }

        return $providers;
    }

    private function provider(string $kind): ?TimelineProvider
    {
        foreach ($this->providers() as $provider) {
            if ($provider->kind() === $kind) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private function scope(Builder $query, Deal|Company|Person $subject): void
    {
        $query->where(match (true) {
            $subject instanceof Deal => 'deal_id',
            $subject instanceof Company => 'company_id',
            default => 'person_id',
        }, $subject->id);
    }

    /**
     * The emails of the subject: of the deal; of the deals and people of the company; of the person.
     *
     * @return Builder<Email>
     */
    private function emails(Deal|Company|Person $subject): Builder
    {
        return match (true) {
            $subject instanceof Deal => Email::query()->where('deal_id', $subject->id),
            $subject instanceof Company => Email::query()->where(fn (Builder $inner) => $inner
                ->whereIn('deal_id', Deal::query()->select('id')->where('company_id', $subject->id))
                ->orWhereIn('person_id', Person::query()->select('id')->where('company_id', $subject->id))),
            default => Email::query()->where('person_id', $subject->id),
        };
    }
}
