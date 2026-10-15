<?php

namespace Sellinnate\SalesCrm\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Events\NoteAdded;
use Sellinnate\SalesCrm\Events\UserMentioned;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Note;
use Sellinnate\SalesCrm\Models\Person;
use Sellinnate\SalesCrm\Services\DealActivityState;
use Sellinnate\SalesCrm\Services\Mentions;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * The notes of the CRM: a quick note with its author on a deal, a company or a person, the
 * mentions of users (an event and, by default, a notification), the pin that keeps it on top. The
 * author changes their note; the author or a manager deletes it.
 */
class ManageNotes
{
    public const MAX_LENGTH = 5000;

    public function __construct(private Mentions $mentions, private DealActivityState $state) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function add(Deal|Company|Person $target, string $body, Authenticatable $by, bool $pinned = false, bool $notify = true): Note
    {
        Gate::forUser($by)->authorize('update', $target);

        $body = $this->body($body);

        return Crm::db()->transaction(function () use ($target, $body, $by, $pinned, $notify): Note {
            $mentioned = $this->mentions->in($body, $target->workspaceRef(), $by);

            $note = (new Note([
                'deal_id' => $target instanceof Deal ? $target->id : null,
                'company_id' => $target instanceof Company ? $target->id : null,
                'person_id' => $target instanceof Person ? $target->id : null,
                'user_id' => Crm::userKey($by),
                'body' => $body,
                'mentions' => $mentioned->map(fn (Model $user): mixed => $user->getKey())->values()->all() ?: null,
                'pinned_at' => $pinned ? CarbonImmutable::now() : null,
            ]))->setWorkspace($target->workspaceRef());
            $note->save();

            if ($note->deal_id !== null) {
                $this->state->refresh($note->deal_id, CarbonImmutable::now());
            }

            NoteAdded::dispatch($note, $by);

            // A note written by an import mentions nobody personally: a sheet may name people at every row.
            foreach ($notify ? $mentioned : [] as $user) {
                UserMentioned::dispatch($note, $user, $by);
            }

            return $note;
        });
    }

    /**
     * The author changes the text; only the newly mentioned users hear of it.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function update(Note $note, string $body, Authenticatable $by): Note
    {
        Gate::forUser($by)->authorize('update', $note);

        $body = $this->body($body);

        return Crm::db()->transaction(function () use ($note, $body, $by): Note {
            $before = array_map('strval', $note->mentions ?? []);
            $mentioned = $this->mentions->in($body, $note->workspaceRef(), $by);

            $note->forceFill(['body' => $body, 'mentions' => $mentioned->map(fn (Model $user): mixed => $user->getKey())->values()->all() ?: null])->save();

            foreach ($mentioned->reject(fn (Model $user): bool => in_array((string) $user->getKey(), $before, true)) as $user) {
                UserMentioned::dispatch($note, $user, $by);
            }

            return $note;
        });
    }

    /**
     * @throws AuthorizationException
     */
    public function pin(Note $note, bool $pinned, Authenticatable $by): Note
    {
        Gate::forUser($by)->authorize('pin', $note);

        $note->forceFill(['pinned_at' => $pinned ? CarbonImmutable::now() : null])->save();

        return $note;
    }

    /**
     * @throws AuthorizationException
     */
    public function delete(Note $note, Authenticatable $by): void
    {
        Gate::forUser($by)->authorize('delete', $note);

        $note->delete();
    }

    /**
     * @throws ValidationException
     */
    private function body(string $body): string
    {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => __('sales-crm::validation.note_empty')]);
        }

        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw ValidationException::withMessages(['body' => __('sales-crm::validation.note_too_long', ['max' => self::MAX_LENGTH])]);
        }

        return $body;
    }
}
