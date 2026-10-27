<?php

namespace App\Support;

use App\Enums\FindingRule;
use App\Models\Finding;
use Illuminate\Database\Eloquent\Builder;

/**
 * Search and title sort of the admin Findings table in the viewer's language. The stored title is written in the alert
 * locale for Google Chat, so both work on the title as `Finding::localizedTitle()` shows it, rebuilt in SQL from the
 * rule's translated template and the context.
 */
final class FindingSearch
{
    /**
     * The language-neutral parts of a finding the search also looks in, e.g. "laravel/framework" or a CVE alias.
     *
     * @var list<string>
     */
    private const array CONTEXT_KEYS = ['technology', 'branch', 'latest', 'ecosystem', 'package', 'version', 'fixed', 'advisory', 'advisory_title', 'aliases'];

    private const int MAX_WORDS = 10;

    /**
     * Findings where every word of the search, e.g. "PHP 8.1 サポート終了", occurs in the title as the viewer reads it or
     * as Google Chat shows it, a context value, the label of the rule, or the name of the team, service or project.
     *
     * @param  Builder<Finding>  $query
     * @return Builder<Finding>
     */
    public static function apply(Builder $query, string $search): Builder
    {
        $words = array_slice(array_unique(preg_split('/[\s\x{3000}]+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: []), 0, self::MAX_WORDS);

        if ($words === []) {
            return $query;
        }

        [$title, $bindings] = self::localizedTitle($query);

        foreach ($words as $word) {
            $pattern = '%'.addcslashes($word, '\\%_').'%';
            $rules = array_values(array_filter(FindingRule::cases(), fn (FindingRule $rule): bool => mb_stripos($rule->getLabel(), $word) !== false));

            $query->where(function (Builder $query) use ($title, $bindings, $pattern, $rules): void {
                $query->whereRaw("({$title}) ilike ?", [...$bindings, $pattern])
                    ->orWhereLike('findings.title', $pattern)
                    ->when($rules !== [], fn (Builder $query) => $query->orWhereIn('findings.rule', $rules))
                    ->orWhereHas('team', fn (Builder $query) => $query->whereLike('name', $pattern))
                    ->orWhereHas('deployment.service', fn (Builder $query) => $query->whereLike('name', $pattern))
                    ->orWhereHas('deployment.service.project', fn (Builder $query) => $query->whereLike('name', $pattern));

                foreach (self::CONTEXT_KEYS as $key) {
                    $query->orWhereLike("findings.context->{$key}", $pattern);
                }
            });
        }

        return $query;
    }

    /**
     * @param  Builder<Finding>  $query
     * @return Builder<Finding>
     */
    public static function orderByTitle(Builder $query, bool $descending): Builder
    {
        [$title, $bindings] = self::localizedTitle($query);

        return $query->orderByRaw($title.($descending ? ' desc' : ' asc'), $bindings);
    }

    /**
     * SQL for the title as `FindingRule::title()` builds it in the current locale, with its bindings. Like
     * `Finding::localizedTitle()` it falls back to the stored title, as Postgres `||` gives null when a part is missing.
     *
     * @param  Builder<Finding>  $query
     * @return array{string, list<string>}
     */
    private static function localizedTitle(Builder $query): array
    {
        $grammar = $query->getQuery()->getGrammar();
        $stored = $grammar->wrap('findings.title');
        $cases = [];
        $bindings = [];

        foreach (FindingRule::cases() as $rule) {
            $parts = $rule->titleParts();

            if ($parts === []) {
                continue;
            }

            $pieces = preg_split('/:('.implode('|', $parts).')/', __((string) $rule->titleTemplate()), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            $concat = [];
            $bindings[] = $rule->value;

            foreach ($pieces as $index => $piece) {
                if ($index % 2 === 1) {
                    $concat[] = '('.$grammar->wrap("findings.context->{$piece}").')';
                } elseif ($piece !== '') {
                    $concat[] = '?::text';
                    $bindings[] = $piece;
                }
            }

            $cases[] = 'when ? then coalesce('.implode(' || ', $concat).", {$stored})";
        }

        return ['case '.$grammar->wrap('findings.rule').' '.implode(' ', $cases)." else {$stored} end", $bindings];
    }
}
