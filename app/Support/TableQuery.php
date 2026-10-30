<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Search, sort and page size of an admin table, read from the query string, e.g. "?search=shop&sort=-name&per_page=25".
 * A leading "-" sorts descending. Unknown sort columns and page sizes fall back to the defaults.
 */
final readonly class TableQuery
{
    /**
     * @var list<int>
     */
    public const array PER_PAGE_OPTIONS = [10, 25, 50];

    private function __construct(
        public string $search,
        public string $sort,
        public bool $descending,
        public int $perPage,
    ) {}

    /**
     * @param  list<string>  $sortable
     */
    public static function fromRequest(Request $request, array $sortable, string $defaultSort): self
    {
        $sort = $request->string('sort')->toString();
        $column = ltrim($sort, '-');

        if (! in_array($column, $sortable, true)) {
            $sort = $defaultSort;
            $column = ltrim($sort, '-');
        }

        $perPage = $request->integer('per_page', self::PER_PAGE_OPTIONS[0]);

        return new self(
            search: mb_substr(trim($request->string('search')->toString()), 0, 100),
            sort: $column,
            descending: str_starts_with($sort, '-'),
            perPage: in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::PER_PAGE_OPTIONS[0],
        );
    }

    /**
     * Rows where any of the columns contains the search text, or that `$orWhere` adds with `orWhere…` to the same
     * group, given the LIKE pattern of the text (e.g. to look in related rows).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     * @param  (Closure(Builder<TModel>, string): mixed)|null  $orWhere
     * @return Builder<TModel>
     */
    public function search(Builder $query, array $columns, ?Closure $orWhere = null): Builder
    {
        if ($this->search === '') {
            return $query;
        }

        $pattern = '%'.addcslashes($this->search, '\\%_').'%';

        return $query->where(function (Builder $query) use ($columns, $pattern, $orWhere): void {
            foreach ($columns as $column) {
                $query->orWhereLike($column, $pattern);
            }

            if ($orWhere !== null) {
                $orWhere($query, $pattern);
            }
        });
    }

    public function direction(): string
    {
        return $this->descending ? 'desc' : 'asc';
    }

    /**
     * What the table shows in its toolbar and sends back when it changes.
     *
     * @return array{search: string, sort: string, per_page: int, per_page_options: list<int>}
     */
    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'sort' => ($this->descending ? '-' : '').$this->sort,
            'per_page' => $this->perPage,
            'per_page_options' => self::PER_PAGE_OPTIONS,
        ];
    }
}
