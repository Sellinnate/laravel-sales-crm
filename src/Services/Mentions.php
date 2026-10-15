<?php

namespace Sellinnate\SalesCrm\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The @mentions of a note: the users of the workspace whose whole name follows an «@», the longest
 * names first («@Jane Doering» is not «@Jane Doe»), without the author.
 */
class Mentions
{
    public function __construct(private Access $access) {}

    /**
     * @return Collection<int, Model>
     */
    public function in(string $body, ?Workspace $workspace, ?Authenticatable $author = null): Collection
    {
        if (! str_contains($body, '@')) {
            return new Collection;
        }

        $text = $body;
        $mentioned = [];
        $members = $this->access->members($workspace)->get();

        foreach ($members->sortByDesc(fn (Model $user): int => mb_strlen(Crm::userName($user))) as $user) {
            $name = Crm::userName($user);

            if ($name === '') {
                continue;
            }

            $pattern = '/(?<![\p{L}\p{N}])@'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu';

            if (preg_match($pattern, $text) === 1) {
                $text = (string) preg_replace($pattern, ' ', $text);

                if (! Crm::sameUser(Crm::userKey($user), Crm::userKey($author))) {
                    $mentioned[] = $user;
                }
            }
        }

        return (new Collection($mentioned))->sortBy(fn (Model $user): string => (string) $user->getKey())->values();
    }
}
