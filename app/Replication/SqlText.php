<?php

namespace App\Replication;

use InvalidArgumentException;

/**
 * Checks and prepares the SQL administrators write: dashboard-stream queries over copied tables,
 * and row filters on source tables. Only reading SQL is accepted, and :named parameters become
 * positional bindings (drivers differ in reusing a name).
 */
class SqlText
{
    /**
     * Accept exactly one SELECT (or WITH … SELECT) statement.
     */
    public static function assertSelect(string $query): string
    {
        $query = rtrim(trim($query), "; \t\n\r");
        $bare = self::withoutLiterals(self::withoutComments($query));

        if (! preg_match('/^\s*(select|with)\b/i', $bare)) {
            throw new InvalidArgumentException('Only SELECT queries are allowed.');
        }
        self::assertReadOnly($bare);

        return $query;
    }

    /**
     * Accept a WHERE condition (without the WHERE keyword) that only reads.
     */
    public static function assertCondition(string $condition): string
    {
        $condition = trim($condition);
        self::assertReadOnly(self::withoutLiterals(self::withoutComments($condition)));

        return $condition;
    }

    /**
     * @return array<string, string>
     */
    public static function parameters(string $text): array
    {
        return collect(preg_split('/\R/', $text))
            ->map(fn (string $line) => trim($line))
            ->filter(fn (string $line) => $line !== '' && ! str_starts_with($line, '#') && str_contains($line, '='))
            ->mapWithKeys(function (string $line) {
                [$name, $value] = array_map('trim', explode('=', $line, 2));

                return [$name => trim($value, "\"'")];
            })
            ->all();
    }

    /**
     * Replace :named parameters with `?` and return the bindings in order.
     *
     * @param  array<string, string>  $parameters
     * @return array{0: string, 1: list<mixed>}
     */
    public static function bind(string $sql, array $parameters): array
    {
        $bindings = [];
        $masked = self::maskLiterals($sql, $literals);

        $bound = preg_replace_callback('/(?<![:\w]):([a-zA-Z_]\w*)/', function (array $m) use ($parameters, &$bindings) {
            if (! array_key_exists($m[1], $parameters)) {
                throw new InvalidArgumentException("The SQL uses :{$m[1]}, which isn't defined in the query parameters.");
            }
            $bindings[] = $parameters[$m[1]];

            return '?';
        }, $masked);

        return [self::unmaskLiterals($bound, $literals), $bindings];
    }

    /**
     * The tables a query reads (every FROM / JOIN target that isn't a subquery).
     *
     * @return list<string>
     */
    public static function tablesRead(string $query): array
    {
        $bare = self::withoutLiterals(self::withoutComments($query));
        preg_match_all('/\b(?:from|join)\s+(?!\()([`"\[\]\w.]+)/i', $bare, $m);

        return array_values(array_unique(array_map(fn (string $t) => trim($t, '`"[]'), $m[1])));
    }

    private static function assertReadOnly(string $bare): void
    {
        if (str_contains($bare, ';')) {
            throw new InvalidArgumentException('Only a single statement is allowed.');
        }
        if (preg_match('/\b(insert|update|delete|merge|drop|alter|create|truncate|exec|execute|grant|revoke|into|call|load_file|outfile|dumpfile)\b/i', $bare)) {
            throw new InvalidArgumentException('Only reading SQL is allowed.');
        }
    }

    private static function withoutComments(string $sql): string
    {
        return preg_replace(['~--[^\n]*~', '~/\*.*?\*/~s'], '', $sql);
    }

    private static function withoutLiterals(string $sql): string
    {
        return self::maskLiterals($sql, $ignored);
    }

    /**
     * Replace quoted string literals with placeholders, so checks and parameter binding ignore them.
     *
     * @param-out list<string> $literals
     */
    private static function maskLiterals(string $sql, ?array &$literals): string
    {
        $literals = [];

        return preg_replace_callback("/'(?:[^']|'')*'/", function (array $m) use (&$literals) {
            $literals[] = $m[0];

            return '__cis_lit_'.(count($literals) - 1).'__';
        }, $sql);
    }

    /**
     * @param  list<string>  $literals
     */
    private static function unmaskLiterals(string $sql, array $literals): string
    {
        return preg_replace_callback('/__cis_lit_(\d+)__/', fn (array $m) => $literals[(int) $m[1]], $sql);
    }
}
