<?php

namespace Sellinnate\SalesCrm\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Events\PersonCreated;
use Sellinnate\SalesCrm\Models\Activity;
use Sellinnate\SalesCrm\Models\Person;
use Sellinnate\SalesCrm\Services\DealPositions;
use Sellinnate\SalesCrm\Services\Matcher;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Support\Phone;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * Create or change a person: a new one is checked against the people of the workspace (email, phone,
 * name in the company) and the possible duplicate is reported unless the user confirms it is another
 * person. Deleting one is for managers.
 */
class SavePerson
{
    public function __construct(private Matcher $matcher, private DealPositions $positions) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Model|Workspace|null $workspace, array $data, Authenticatable $by, ?Person $person = null, bool $force = false): Person
    {
        $workspace = Workspace::of($workspace);

        $person === null
            ? Gate::forUser($by)->authorize('create', [Person::class, $workspace])
            : Gate::forUser($by)->authorize('update', $person);

        if ($person !== null && ! $person->inWorkspace($workspace)) {
            throw ValidationException::withMessages(['person' => __('sales-crm::validation.other_workspace')]);
        }

        $data = array_map(fn (mixed $value): mixed => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $data);
        $companies = Rule::exists(Crm::table('companies'), 'id');
        $workspace === null ? $companies->whereNull('workspace_type') : $companies->where('workspace_type', $workspace->type)->where('workspace_id', $workspace->id);

        /** @var array<string, string|int|null> $validated */
        $validated = Validator::make($data, [
            ...ManageDealPeople::personRules(),
            'company_id' => ['nullable', 'integer', $companies],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [], ManageDealPeople::personAttributes())->validate();

        if (blank($validated['first_name'] ?? null) && blank($validated['last_name'] ?? null) && blank($validated['email'] ?? null)
            && blank($validated['mobile'] ?? null) && blank($validated['phone'] ?? null)) {
            throw ValidationException::withMessages(['first_name' => __('sales-crm::validation.person_identity')]);
        }

        foreach (['mobile', 'phone'] as $field) {
            if (array_key_exists($field, $validated)) {
                $validated[$field] = Phone::normalize(is_string($validated[$field]) ? $validated[$field] : null);
            }
        }

        return $this->matcher->locked($workspace, fn (): Person => Crm::db()->transaction(function () use ($workspace, $validated, $by, $person, $force): Person {
            $companyId = isset($validated['company_id']) ? (int) $validated['company_id'] : null;

            if ($person === null && ! $force && ($duplicate = $this->matcher->findPerson($workspace, $validated, $companyId)) !== null) {
                throw ValidationException::withMessages(['duplicate' => __('sales-crm::validation.duplicate_person', ['name' => $duplicate->name, 'id' => $duplicate->id])]);
            }

            $created = $person === null;
            $person ??= (new Person(['created_by' => Crm::userKey($by)]))->setWorkspace($workspace);
            $person->fill($validated);
            $changed = ! $created && $person->isDirty();
            $person->save();

            if ($changed) {
                $this->positions->touchDealsOf($person);
            }

            if ($created) {
                PersonCreated::dispatch($person, $by);
            }

            return $person;
        }));
    }

    /**
     * @throws AuthorizationException
     */
    public function delete(Person $person, Authenticatable $by): void
    {
        Gate::forUser($by)->authorize('delete', $person);

        Crm::db()->transaction(function () use ($person): void {
            // The activities on the person go with it; those of a deal with the person stay, without it.
            Activity::query()->where('person_id', $person->id)->whereNull('deal_id')->whereNull('company_id')->delete();
            $this->positions->touchDealsOf($person);
            $pivot = Crm::table('deal_person');
            $primaryOf = Crm::db()->table($pivot)->where('person_id', $person->id)->where('is_primary', true)->pluck('deal_id');
            $person->delete();

            // The deals where the person was the primary contact get the next one.
            foreach ($primaryOf as $dealId) {
                $next = Crm::db()->table($pivot)->where('deal_id', $dealId)->orderBy('id')->value('id');

                if ($next !== null) {
                    Crm::db()->table($pivot)->where('id', $next)->update(['is_primary' => true]);
                }
            }
        });
    }
}
