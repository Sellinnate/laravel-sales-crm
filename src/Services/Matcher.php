<?php

namespace Sellinnate\SalesCrm\Services;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Sellinnate\SalesCrm\Events\CompanyCreated;
use Sellinnate\SalesCrm\Events\PersonCreated;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Person;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Support\Locks;
use Sellinnate\SalesCrm\Support\Phone;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The deduplication of companies and people, once per workspace: a company by VAT number, then by
 * domain (never a free mail one), then by normalised name; a person by email, then by phone (E.164),
 * then by name within the same company. A match is completed with the details it lacks and never
 * overwritten. Quick add, integrations, import and MCP all go through here, under one lock per
 * workspace.
 */
class Matcher
{
    /**
     * Legal forms dropped from a company name before comparing it: «Acme S.r.l.» is «Acme srl» is «Acme».
     */
    private const LEGAL_FORMS = [
        'srl', 'srls', 'spa', 'sas', 'snc', 'sapa', 'scarl', 'scrl', 'sc', 'soc', 'coop', 'societa', 'cooperativa',
        'semplificata', 'unipersonale', 'su', 'ltd', 'limited', 'gmbh', 'sa', 'sarl', 'llc', 'inc', 'corp',
        'co', 'ag', 'bv', 'nv', 'plc', 'oy', 'ab', 'as', 'aps', 'kg', 'sl', 'slu', 'pty',
    ];

    /**
     * The comparable key of a company name: lowercase, no accents, punctuation or legal form.
     */
    public static function nameKey(?string $name): string
    {
        $words = preg_split('/\s+/', trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii(str_replace('.', '', (string) $name)))))) ?: [];
        $words = array_values(array_filter($words, fn (string $word): bool => $word !== ''));
        $kept = array_values(array_filter($words, fn (string $word): bool => ! in_array($word, self::LEGAL_FORMS, true)));

        return mb_substr(implode(' ', $kept === [] ? $words : $kept), 0, 200);
    }

    /**
     * The comparable key of a VAT number: letters and digits, uppercase, without the IT prefix of an
     * Italian number (the most common duplicate in the original data).
     */
    public static function vatKey(?string $vat): ?string
    {
        $key = Str::upper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $vat));

        if (preg_match('/^IT(\d{11})$/', $key, $matches) === 1) {
            $key = $matches[1];
        }

        return $key === '' ? null : mb_substr($key, 0, 32);
    }

    /**
     * The domain of a company: the host of its website without «www.» when it is not a platform shared
     * by many companies, else the domain of its email when it is not a free mail provider.
     */
    public static function domainOf(?string $website, ?string $email = null): ?string
    {
        $website = trim((string) $website);

        if ($website !== '') {
            $host = parse_url(str_contains($website, '://') ? $website : 'https://'.$website, PHP_URL_HOST);
            $host = is_string($host) ? Str::lower((string) preg_replace('/^www\./i', '', $host)) : '';

            if ($host !== '' && ! self::listed($host, 'sales-crm.companies.shared_site_hosts')) {
                return mb_substr($host, 0, 191);
            }
        }

        $email = (string) $email;
        $domain = Str::lower(trim(Str::after($email, '@')));

        return $domain === '' || ! str_contains($email, '@') || self::isFreeMail($domain) ? null : mb_substr($domain, 0, 191);
    }

    /**
     * Whether the domain is a free mail provider, or one of its subdomains: it says nothing about the company.
     */
    public static function isFreeMail(string $domain): bool
    {
        return self::listed(Str::lower($domain), 'sales-crm.companies.free_mail_domains');
    }

    private static function listed(string $host, string $config): bool
    {
        $list = config($config, []);

        foreach (is_array($list) ? $list : [] as $entry) {
            if (is_string($entry) && ($host === $entry || str_ends_with($host, '.'.$entry))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Run the callback under the lock of the deduplication of the workspace.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function locked(?Workspace $workspace, Closure $callback): mixed
    {
        return Locks::contacts($workspace?->key() ?? '', $callback);
    }

    /**
     * The company of the workspace matching the data, or null.
     *
     * @param  array<string, mixed>  $data  name, vat_number, website, email
     */
    public function findCompany(?Workspace $workspace, array $data): ?Company
    {
        $query = fn () => Company::query()->inWorkspace($workspace)->orderBy('id');

        if (($vat = self::vatKey(self::text($data['vat_number'] ?? null))) !== null && ($found = $query()->where('vat_key', $vat)->first()) !== null) {
            return $found;
        }

        if (($domain = self::domainOf(self::text($data['website'] ?? null), self::text($data['email'] ?? null))) !== null
            && ($found = $query()->where('domain', $domain)->first()) !== null) {
            return $found;
        }

        $name = trim((string) self::text($data['name'] ?? null));

        return $name === '' ? null : $query()->where('name_key', self::nameKey($name))->first();
    }

    /**
     * The person of the workspace matching the data, or null.
     *
     * @param  array<string, mixed>  $data  first_name, last_name, email, mobile, phone
     */
    public function findPerson(?Workspace $workspace, array $data, ?int $companyId = null): ?Person
    {
        $query = fn () => Person::query()->inWorkspace($workspace)->orderBy('id');
        $email = mb_strtolower(trim((string) self::text($data['email'] ?? null)));

        if ($email !== '' && ($found = $query()->where('email', $email)->first()) !== null) {
            return $found;
        }

        $phones = array_values(array_filter([Phone::normalize(self::text($data['mobile'] ?? null)), Phone::normalize(self::text($data['phone'] ?? null))]));

        if ($phones !== [] && ($found = $query()->where(fn ($inner) => $inner->whereIn('mobile', $phones)->orWhereIn('phone', $phones))->first()) !== null) {
            return $found;
        }

        $first = trim((string) self::text($data['first_name'] ?? null));
        $last = trim((string) self::text($data['last_name'] ?? null));

        if ($companyId === null || ($first === '' && $last === '')) {
            return null;
        }

        return $query()
            ->where('company_id', $companyId)
            ->whereRaw("LOWER(COALESCE(first_name, '')) = ?", [mb_strtolower($first)])
            ->whereRaw("LOWER(COALESCE(last_name, '')) = ?", [mb_strtolower($last)])
            ->first();
    }

    /**
     * The company matching the data, completed with what it lacks, or a new one.
     *
     * @param  array<string, mixed>  $data
     */
    public function company(?Workspace $workspace, array $data, ?Authenticatable $by = null): Company
    {
        return $this->locked($workspace, function () use ($workspace, $data, $by): Company {
            $attributes = $this->companyAttributes($data);
            $company = $this->findCompany($workspace, $data);

            if ($company === null) {
                $company = new Company([...$attributes, 'created_by' => Crm::userKey($by)]);
                $company->setWorkspace($workspace)->save();
                CompanyCreated::dispatch($company, $by);

                return $company;
            }

            $company->fill(array_filter($attributes, fn (mixed $value, string $key): bool => $value !== null && $value !== '' && blank($company->getAttribute($key)), ARRAY_FILTER_USE_BOTH))->save();

            return $company;
        });
    }

    /**
     * The person matching the data, completed with what it lacks, or a new one.
     *
     * @param  array<string, mixed>  $data
     */
    public function person(?Workspace $workspace, array $data, ?Company $company = null, ?Authenticatable $by = null): Person
    {
        return $this->locked($workspace, function () use ($workspace, $data, $company, $by): Person {
            $attributes = [
                'first_name' => self::clean($data['first_name'] ?? null, 120),
                'last_name' => self::clean($data['last_name'] ?? null, 120),
                'job_title' => self::clean($data['job_title'] ?? null, 160),
                'email' => self::clean($data['email'] ?? null, 191),
                'mobile' => Phone::normalize(self::text($data['mobile'] ?? null)),
                'phone' => Phone::normalize(self::text($data['phone'] ?? null)),
                'phone_source' => self::clean($data['phone_source'] ?? null, 255),
                'company_id' => $company?->id,
            ];
            $person = $this->findPerson($workspace, $data, $company?->id);

            if ($person === null) {
                $person = new Person([...$attributes, 'created_by' => Crm::userKey($by)]);
                $person->setWorkspace($workspace)->save();
                PersonCreated::dispatch($person, $by);

                return $person;
            }

            $person->fill(array_filter($attributes, fn (mixed $value, string $key): bool => $value !== null && blank($person->getAttribute($key)), ARRAY_FILTER_USE_BOTH))->save();

            return $person;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function companyAttributes(array $data): array
    {
        return [
            'name' => self::clean($data['name'] ?? null, 200) ?? '',
            'legal_name' => self::clean($data['legal_name'] ?? null, 200),
            'vat_number' => self::clean($data['vat_number'] ?? null, 32),
            'website' => self::clean($data['website'] ?? null, 255),
            'email' => self::clean($data['email'] ?? null, 191),
            'phone' => Phone::normalize(self::text($data['phone'] ?? null)),
            'address' => self::clean($data['address'] ?? null, 255),
            'city' => self::clean($data['city'] ?? null, 120),
            'region' => self::clean($data['region'] ?? null, 120),
            'postal_code' => self::clean($data['postal_code'] ?? null, 20),
            'country' => ($country = self::clean($data['country'] ?? null, 2)) === null ? null : Str::upper($country),
            'industry' => self::clean($data['industry'] ?? null, 120),
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private static function clean(mixed $value, int $max): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
