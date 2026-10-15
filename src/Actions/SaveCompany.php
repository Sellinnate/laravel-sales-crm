<?php

namespace Sellinnate\SalesCrm\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Events\CompanyCreated;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Services\DealPositions;
use Sellinnate\SalesCrm\Services\Matcher;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Support\Phone;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * Create or change a company: a new one is checked against the companies of the workspace first and
 * the possible duplicate is reported instead of creating it, unless the user confirms (`$force`) it
 * is another company. Deleting one is for managers, and only without deals.
 */
class SaveCompany
{
    public function __construct(private Matcher $matcher, private ManageDocuments $documents, private DealPositions $positions) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Model|Workspace|null $workspace, array $data, Authenticatable $by, ?Company $company = null, bool $force = false): Company
    {
        $workspace = Workspace::of($workspace);

        $company === null
            ? Gate::forUser($by)->authorize('create', [Company::class, $workspace])
            : Gate::forUser($by)->authorize('update', $company);

        if ($company !== null && ! $company->inWorkspace($workspace)) {
            throw ValidationException::withMessages(['company' => __('sales-crm::validation.other_workspace')]);
        }

        $data = array_map(fn (mixed $value): mixed => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $data);

        /** @var array<string, string|null> $validated */
        $validated = Validator::make($data, self::rules(), ['vat_number.regex' => __('sales-crm::validation.vat_number')], self::attributes())->validate();

        if (array_key_exists('phone', $validated)) {
            $validated['phone'] = Phone::normalize($validated['phone']);
        }

        if (isset($validated['country'])) {
            $validated['country'] = mb_strtoupper($validated['country']);
        }

        return $this->matcher->locked($workspace, fn (): Company => Crm::db()->transaction(function () use ($workspace, $validated, $by, $company, $force): Company {
            if ($company === null && ! $force && ($duplicate = $this->matcher->findCompany($workspace, $validated)) !== null) {
                throw ValidationException::withMessages(['duplicate' => __('sales-crm::validation.duplicate_company', ['name' => $duplicate->name, 'id' => $duplicate->id])]);
            }

            $created = $company === null;
            $company ??= (new Company(['created_by' => Crm::userKey($by)]))->setWorkspace($workspace);
            $company->fill($validated);
            $changed = ! $created && $company->isDirty();
            $company->save();

            if ($changed) {
                $this->positions->touchDealsOf($company);
            }

            if ($created) {
                CompanyCreated::dispatch($company, $by);
            }

            return $company;
        }));
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function delete(Company $company, Authenticatable $by): void
    {
        Gate::forUser($by)->authorize('delete', $company);

        Crm::db()->transaction(function () use ($company): void {
            // Locked before looking for deals: a deal taking the company meanwhile waits for the deletion.
            Company::query()->whereKey($company->id)->lockForUpdate()->first();

            if ($company->deals()->exists()) {
                throw ValidationException::withMessages(['company' => __('sales-crm::validation.company_has_deals')]);
            }

            $this->documents->purgeWith($company);
            $company->delete();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'vat_number' => ['nullable', 'string', 'max:32', 'regex:/^[A-Z]{0,2}\s?[0-9A-Z]{6,14}$/i'],
            'website' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40', ManageDealPeople::phoneRule()],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
            'industry' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        $attributes = [];

        foreach (array_keys(self::rules()) as $field) {
            $attributes[$field] = mb_strtolower((string) __('sales-crm::fields.company.'.$field));
        }

        return $attributes;
    }
}
