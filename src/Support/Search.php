<?php

namespace Sellinnate\SalesCrm\Support;

use Illuminate\Contracts\Database\Query\Expression;

/**
 * The LIKE pattern of a search: lowercase, contains, with the wildcards of the text escaped by `!`
 * (use `ESCAPE '!'`).
 */
final class Search
{
    /**
     * «<expression> LIKE ? ESCAPE '!'» as a raw expression for whereRaw().
     */
    public static function like(string $expression): Expression
    {
        return Crm::raw($expression." LIKE ? ESCAPE '!'");
    }

    /**
     * The SQL concatenation of the expressions with a space between them: CONCAT on MySQL and
     * MariaDB, || elsewhere.
     */
    public static function concat(string ...$expressions): string
    {
        $driver = Crm::db()->getDriverName();

        return in_array($driver, ['mysql', 'mariadb'], true)
            ? 'CONCAT('.implode(", ' ', ", $expressions).')'
            : implode(" || ' ' || ", $expressions);
    }

    public static function contains(string $text): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($text))).'%';
    }
}
