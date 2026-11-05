import { Link, router } from '@inertiajs/react';
import { ArrowDownIcon, ArrowUpIcon, ChevronLeftIcon, ChevronRightIcon, SearchIcon } from 'lucide-react';
import { useEffect, useImperativeHandle, useRef, useState, type ReactNode, type Ref } from 'react';

import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/native-select';
import { useDebouncedCallback } from '@/hooks/use-debounced-callback';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Paginated, TableState } from '@/types';

/** How far the edges fade while a wide table scrolls sideways, so a cut column reads as "more to scroll". */
const SCROLL_FADE = '32px';

/**
 * A table that scrolls sideways when the screen is narrower than its columns. The edge with more columns behind it
 * fades out.
 */
export function Table({ className, ...props }: React.ComponentProps<'table'>) {
    const scrollerRef = useRef<HTMLDivElement>(null);
    const [hiddenSides, setHiddenSides] = useState({ start: false, end: false });

    useEffect(() => {
        const scroller = scrollerRef.current;

        if (!scroller) {
            return;
        }

        const measure = () => {
            const start = scroller.scrollLeft > 1;
            const end = scroller.scrollLeft + scroller.clientWidth < scroller.scrollWidth - 1;

            setHiddenSides((sides) => (sides.start === start && sides.end === end ? sides : { start, end }));
        };
        const observer = new ResizeObserver(measure);

        measure();
        observer.observe(scroller);

        if (scroller.firstElementChild) {
            observer.observe(scroller.firstElementChild);
        }

        scroller.addEventListener('scroll', measure, { passive: true });

        return () => {
            observer.disconnect();
            scroller.removeEventListener('scroll', measure);
        };
    }, []);

    const fade =
        hiddenSides.start || hiddenSides.end
            ? `linear-gradient(to right, ${hiddenSides.start ? 'transparent' : '#000'}, #000 ${SCROLL_FADE}, #000 calc(100% - ${SCROLL_FADE}), ${hiddenSides.end ? 'transparent' : '#000'})`
            : undefined;

    return (
        <div ref={scrollerRef} className="overflow-x-auto" style={{ maskImage: fade }}>
            <table className={cn('w-full min-w-[760px] border-collapse text-left', className)} {...props} />
        </div>
    );
}

export function Th({ className, align, ...props }: React.ComponentProps<'th'> & { align?: 'right' }) {
    return (
        <th
            className={cn('h-10 border-b border-border px-5 text-sm font-medium whitespace-nowrap text-muted-foreground max-sm:px-3.5', align === 'right' && 'text-right', className)}
            {...props}
        />
    );
}

/** A header that sorts by `column`, ascending first; the arrow shows the current direction. */
export function SortableTh({
    column,
    sort,
    onSort,
    align,
    children,
}: {
    column: string;
    sort: string;
    onSort: (sort: string) => void;
    align?: 'right';
    children: ReactNode;
}) {
    const isActive = sort.replace(/^-/, '') === column;
    const isDescending = isActive && sort.startsWith('-');
    const Arrow = isDescending ? ArrowDownIcon : ArrowUpIcon;

    return (
        <Th align={align} aria-sort={isActive ? (isDescending ? 'descending' : 'ascending') : undefined}>
            <button
                type="button"
                className={cn('inline-flex h-9 items-center gap-1 hover:text-foreground', align === 'right' && 'flex-row-reverse', isActive && 'text-foreground')}
                onClick={() => onSort(isActive && !isDescending ? `-${column}` : column)}
            >
                {children}
                <Arrow className={cn('size-3.5', !isActive && 'invisible')} aria-hidden />
            </button>
        </Th>
    );
}

export function Td({ className, align, ...props }: React.ComponentProps<'td'> & { align?: 'right' }) {
    return <td className={cn('border-b border-border px-5 py-3 align-middle max-sm:px-3.5', align === 'right' && 'text-right tabular-nums', className)} {...props} />;
}

/**
 * A row that opens `href` when clicked anywhere outside its links and buttons. The cells still hold a real link for the
 * keyboard and for opening in a new tab.
 */
export function Tr({ href, className, ...props }: React.ComponentProps<'tr'> & { href?: string }) {
    return (
        <tr
            className={cn('last:[&>td]:border-b-0 hover:bg-surface-2', href && 'cursor-pointer', className)}
            onClick={
                href
                    ? (event) => {
                          if (!(event.target as HTMLElement).closest('a, button, input, label') && !window.getSelection()?.toString()) {
                              router.visit(href);
                          }
                      }
                    : undefined
            }
            {...props}
        />
    );
}

/** Lets a page empty its `TableSearch`, e.g. on "Bỏ lọc", together with a search still waiting to be sent. */
export interface TableSearchHandle {
    clear: () => void;
}

/**
 * The search box of a table header. Searches as you type, 250 ms after the last key. The box keeps what was typed rather
 * than following `value`, so a reload coming back while you type on does not undo your keys; a page that resets the
 * search calls `clear()` through `ref`.
 */
export function TableSearch({ ref, value, placeholder, onSearch }: { ref?: Ref<TableSearchHandle>; value: string; placeholder: string; onSearch: (search: string) => void }) {
    const [search, setSearch] = useState(value);
    const debouncedSearch = useDebouncedCallback(onSearch, 250);

    useImperativeHandle(ref, () => ({
        clear: () => {
            debouncedSearch.cancel();
            setSearch('');
        },
    }));

    // A search still waiting must not interrupt a visit that leaves the page.
    useEffect(
        () =>
            router.on('start', (event) => {
                if (!event.detail.visit.preserveState) {
                    debouncedSearch.cancel();
                }
            }),
        [debouncedSearch],
    );

    return (
        <label className="relative block w-full max-w-[280px]">
            <SearchIcon className="pointer-events-none absolute top-1/2 left-2.5 size-[15px] -translate-y-1/2 text-faint" aria-hidden />
            <input
                type="search"
                value={search}
                placeholder={placeholder}
                aria-label={placeholder}
                onChange={(event) => {
                    setSearch(event.target.value);
                    debouncedSearch(event.target.value);
                }}
                className="h-9 w-full rounded-md border border-border bg-surface pr-3 pl-8 text-sm outline-none placeholder:text-faint focus:border-primary focus:shadow-[0_0_0_3px_var(--focus)]"
            />
        </label>
    );
}

/** A compact select for table filters, e.g. "Mọi team", drawn like the selects of the forms. */
export function TableFilter<TValue extends string | number>({
    value,
    label,
    allLabel,
    options,
    onChange,
}: {
    value: TValue | null;
    label: string;
    allLabel: string;
    options: { value: TValue; label: string }[];
    onChange: (value: string | null) => void;
}) {
    return (
        <NativeSelect
            aria-label={label}
            value={value ?? ''}
            onChange={(event) => onChange(event.target.value === '' ? null : event.target.value)}
            className="h-9 max-w-[220px] border-border text-sm"
        >
            <option value="">{allLabel}</option>
            {options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </NativeSelect>
    );
}

/** "Đang hiện 1–10 / 24", the page size and the previous / next page buttons. */
export function TablePagination({ meta, table, onChange }: { meta: Paginated<unknown>['meta']; table: TableState; onChange: (changes: Record<string, number>, resetPage?: boolean) => void }) {
    const t = useTranslation();

    if (meta.total === 0) {
        return null;
    }

    return (
        <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-3 text-sm text-muted-foreground max-sm:px-3.5">
            <span className="tabular-nums">{t('Đang hiện :from–:to / :total', { from: meta.from ?? 0, to: meta.to ?? 0, total: meta.total })}</span>
            <div className="flex items-center gap-2">
                <NativeSelect
                    aria-label={t('Số dòng mỗi trang')}
                    value={table.per_page}
                    onChange={(event) => onChange({ per_page: Number(event.target.value) })}
                    className="h-[30px] border-border pl-2.5 text-sm"
                >
                    {table.per_page_options.map((option) => (
                        <option key={option} value={option}>
                            {t(':count / trang', { count: option })}
                        </option>
                    ))}
                </NativeSelect>
                {meta.last_page > 1 && (
                    <>
                        <Button
                            variant="secondary"
                            size="icon-sm"
                            aria-label={t('Trang trước')}
                            disabled={meta.current_page <= 1}
                            onClick={() => onChange({ page: meta.current_page - 1 }, false)}
                        >
                            <ChevronLeftIcon />
                        </Button>
                        <span className="tabular-nums">{t('Trang :page / :pages', { page: meta.current_page, pages: meta.last_page })}</span>
                        <Button
                            variant="secondary"
                            size="icon-sm"
                            aria-label={t('Trang sau')}
                            disabled={meta.current_page >= meta.last_page}
                            onClick={() => onChange({ page: meta.current_page + 1 }, false)}
                        >
                            <ChevronRightIcon />
                        </Button>
                    </>
                )}
            </div>
        </footer>
    );
}

/** One sentence saying why the list is empty, and at most one action. */
export function EmptyState({ title, description, action }: { title: string; description?: string; action?: ReactNode }) {
    return (
        <div className="grid justify-items-center gap-1.5 px-6 py-14 text-center text-muted-foreground">
            <strong className="text-md font-semibold text-foreground">{title}</strong>
            {description && <p>{description}</p>}
            {action && <div className="mt-2.5">{action}</div>}
        </div>
    );
}

/** The title of a row with a muted line below, e.g. the team name and its slug. */
export function CellTitle({ title, sub, href, icon }: { title: string; sub?: ReactNode; href?: string; icon?: ReactNode }) {
    return (
        <>
            {icon}
            {href ? (
                <Link href={href} className="font-medium hover:underline hover:underline-offset-3">
                    {title}
                </Link>
            ) : (
                <span className="font-medium">{title}</span>
            )}
            {sub && <span className="mt-0.5 block text-sm text-muted-foreground">{sub}</span>}
        </>
    );
}
