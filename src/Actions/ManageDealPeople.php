<?php

namespace Sellinnate\SalesCrm\Actions;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Enums\PersonRole;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Person;
use Sellinnate\SalesCrm\Services\DealPositions;
use Sellinnate\SalesCrm\Services\Matcher;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Support\Phone;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The people of a deal and their role: an existing person of the workspace, or a new one
 * deduplicated by the matcher; one of them is the primary contact, shown on the card.
 */
class ManageDealPeople
{
    public function __construct(private Matcher $matcher, private DealPositions $positions) {}

    /**
     * Add a person to the deal: `person_id` of the workspace, or the details of a person to match or create.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function attach(Deal $deal, array $data, ?Authenticatable $by): Person
    {
        if ($by !== null) {
            Gate::forUser($by)->authorize('update', $deal);
        }

        $workspace = $deal->workspaceRef();
        $validated = self::validate($workspace, $data);

        return $this->matcher->locked($workspace, fn (): Person => Crm::db()->transaction(function () use ($deal, $workspace, $validated, $by): Person {
            $person = isset($validated['person_id'])
                ? Person::query()->inWorkspace($workspace)->findOrFail((int) $validated['person_id'])
                : $this->matcher->person($workspace, $validated, $deal->company, $by);

            if ($person->company_id === null && $deal->company_id !== null) {
                $person->forceFill(['company_id' => $deal->company_id])->save();
            }

            // A person already on the deal keeps the role and the primary place the input does not change.
            $current = Crm::db()->table(Crm::table('deal_person'))->where('deal_id', $deal->id)->where('person_id', $person->id)->first(['role', 'is_primary']);
            $primary = (bool) ($validated['is_primary'] ?? false) || (bool) ($current->is_primary ?? false) || ! $deal->people()->exists();
            $role = PersonRole::tryFrom((string) (filled($validated['role'] ?? null) ? $validated['role'] : ($current->role ?? '')));

            $deal->people()->syncWithoutDetaching([$person->id => ['role' => $role?->value, 'is_primary' => $primary]]);

            if ($primary) {
                $this->makePrimary($deal, $person);
            }

            $this->positions->touch($deal->pipeline_id);

            return $person;
        }));
    }

    /**
     * Change the role of a person of the deal, or make them the primary contact.
     *
     * @throws AuthorizationException
     */
    public function update(Deal $deal, Person $person, ?PersonRole $role, bool $primary, Authenticatable $by): void
    {
        Gate::forUser($by)->authorize('update', $deal);

        Crm::db()->transaction(function () use ($deal, $person, $role, $primary): void {
            $deal->people()->updateExistingPivot($person->id, ['role' => $role?->value]);

            if ($primary) {
                $this->makePrimary($deal, $person);
            }

            $this->positions->touch($deal->pipeline_id);
        });
    }

    /**
     * Take a person off the deal; the person stays in the CRM and the next one becomes primary.
     *
     * @throws AuthorizationException
     */
    public function detach(Deal $deal, Person $person, Authenticatable $by): void
    {
        Gate::forUser($by)->authorize('update', $deal);

        Crm::db()->transaction(function () use ($deal, $person): void {
            $deal->people()->detach($person->id);
            $next = $deal->people()->first();

            if ($next !== null && ! $deal->people()->wherePivot('is_primary', true)->exists()) {
                $this->makePrimary($deal, $next);
            }

            $this->positions->touch($deal->pipeline_id);
        });
    }

    /**
     * The rules of the details of a person (shared by the deal forms and the people pages).
     *
     * @return array<string, mixed>
     */
    public static function personRules(): array
    {
        $phone = ['nullable', 'string', 'max:40', self::phoneRule()];

        return [
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'job_title' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:191'],
            'mobile' => $phone,
            'phone' => $phone,
            'phone_source' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function personAttributes(): array
    {
        $attributes = [];

        foreach (['person_id', 'first_name', 'last_name', 'job_title', 'email', 'mobile', 'phone', 'phone_source', 'role', 'company_id', 'notes'] as $field) {
            $attributes[$field] = mb_strtolower((string) __('sales-crm::fields.person.'.$field));
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function validate(?Workspace $workspace, array $data): array
    {
        $data = array_map(fn (mixed $value): mixed => is_string($value) && trim($value) === '' ? null : $value, $data);
        $people = Rule::exists(Crm::table('people'), 'id');
        $workspace === null ? $people->whereNull('workspace_type') : $people->where('workspace_type', $workspace->type)->where('workspace_id', $workspace->id);

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($data, [
            'person_id' => ['nullable', 'integer', $people],
            ...self::personRules(),
            'role' => ['nullable', Rule::enum(PersonRole::class)],
            'is_primary' => ['boolean'],
        ], [], self::personAttributes())->validate();

        if (! isset($validated['person_id']) && blank($validated['first_name'] ?? null) && blank($validated['last_name'] ?? null)
            && blank($validated['email'] ?? null) && blank($validated['mobile'] ?? null) && blank($validated['phone'] ?? null)) {
            throw ValidationException::withMessages(['first_name' => __('sales-crm::validation.person_identity')]);
        }

        return $validated;
    }

    private function makePrimary(Deal $deal, Person $person): void
    {
        $pivot = Crm::db()->table(Crm::table('deal_person'));
        (clone $pivot)->where('deal_id', $deal->id)->update(['is_primary' => false]);
        (clone $pivot)->where('deal_id', $deal->id)->where('person_id', $person->id)->update(['is_primary' => true]);
    }

    public static function phoneRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && trim($value) !== '' && Phone::normalize($value) === null) {
                $fail((string) __('sales-crm::validation.phone'));
            }
        };
    }
}
