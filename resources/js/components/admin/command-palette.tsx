import { router } from '@inertiajs/react';
import { SearchIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { index as projectsIndex } from '@/actions/App/Http/Controllers/Admin/ProjectController';
import SearchController from '@/actions/App/Http/Controllers/Admin/SearchController';
import { index as servicesIndex } from '@/actions/App/Http/Controllers/Admin/ServiceController';
import { index as teamsIndex } from '@/actions/App/Http/Controllers/Admin/TeamController';
import { index as technologiesIndex } from '@/actions/App/Http/Controllers/Admin/TechnologyController';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { useDebouncedCallback } from '@/hooks/use-debounced-callback';
import { getJson } from '@/lib/http';
import { useTranslation } from '@/lib/i18n';

interface SearchResult {
    label: string;
    hint: string;
    url: string;
}

/** The topbar search: ⌘K / Ctrl+K opens it, pages are listed first, records are searched as you type. */
export function CommandPalette() {
    const t = useTranslation();
    const [isOpen, setIsOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<SearchResult[]>([]);
    const [isSearching, setIsSearching] = useState(false);
    const requestRef = useRef<AbortController | null>(null);

    const pages: SearchResult[] = [
        { label: t('Projects'), hint: t('Trang'), url: projectsIndex.url() },
        { label: t('Services'), hint: t('Trang'), url: servicesIndex.url() },
        { label: t('Teams'), hint: t('Trang'), url: teamsIndex.url() },
        { label: t('Công nghệ'), hint: t('Trang'), url: technologiesIndex.url() },
    ];

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key.toLowerCase() === 'k' && (event.metaKey || event.ctrlKey)) {
                event.preventDefault();
                setIsOpen((open) => !open);
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    const search = useDebouncedCallback((text: string) => {
        requestRef.current?.abort();

        if (text.trim() === '') {
            setResults([]);
            setIsSearching(false);

            return;
        }

        const controller = new AbortController();
        requestRef.current = controller;

        getJson<{ results: SearchResult[] }>(SearchController.url({ query: { query: text } }), controller.signal)
            .then((response) => setResults(response.results))
            .catch(() => undefined)
            .finally(() => controller === requestRef.current && setIsSearching(false));
    }, 150);

    const open = (url: string) => {
        setIsOpen(false);
        router.visit(url);
    };

    const needle = query.trim().toLocaleLowerCase();
    const matchingPages = pages.filter((page) => page.label.toLocaleLowerCase().includes(needle));

    return (
        <>
            <button
                type="button"
                onClick={() => setIsOpen(true)}
                className="ml-auto flex h-9 w-[280px] items-center gap-2 rounded-md border border-border bg-surface pr-1.5 pl-2.5 text-sm text-faint hover:border-border-strong max-[860px]:w-auto"
            >
                <SearchIcon className="size-[15px]" aria-hidden />
                <span className="max-[860px]:hidden">{t('Tìm kiếm')}</span>
                <kbd className="ml-auto rounded-sm border border-border px-1.5 text-xs text-faint max-[860px]:hidden">⌘K</kbd>
            </button>
            <Dialog
                open={isOpen}
                onOpenChange={(value) => {
                    setIsOpen(value);

                    if (!value) {
                        setQuery('');
                        setResults([]);
                    }
                }}
            >
                <DialogContent showCloseButton={false} className="top-[12vh] w-[min(560px,calc(100%-32px))] translate-y-0 overflow-hidden p-0">
                    <DialogTitle className="sr-only">{t('Tìm kiếm')}</DialogTitle>
                    <DialogDescription className="sr-only">{t('Tìm project, service, team hoặc công nghệ')}</DialogDescription>
                    <Command shouldFilter={false} loop>
                        <CommandInput
                            value={query}
                            onValueChange={(value) => {
                                setQuery(value);
                                setIsSearching(value.trim() !== '');
                                search(value);
                            }}
                            placeholder={t('Tìm project, service, team hoặc công nghệ')}
                            className="h-[50px] text-md"
                        />
                        <CommandList className="max-h-[340px] p-1.5">
                            {matchingPages.length > 0 && (
                                <CommandGroup heading={t('Trang')}>
                                    {matchingPages.map((page) => (
                                        <CommandItem key={page.url} value={`page:${page.url}`} onSelect={() => open(page.url)}>
                                            <span className="flex-1">{page.label}</span>
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            )}
                            {results.length > 0 && (
                                <CommandGroup heading={t('Kết quả')}>
                                    {results.map((result) => (
                                        <CommandItem key={result.url} value={result.url} onSelect={() => open(result.url)}>
                                            <span className="min-w-0 flex-1 truncate">{result.label}</span>
                                            <span className="shrink-0 text-sm text-faint">{result.hint}</span>
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            )}
                            {!isSearching && <CommandEmpty>{t('Không có kết quả phù hợp.')}</CommandEmpty>}
                        </CommandList>
                    </Command>
                </DialogContent>
            </Dialog>
        </>
    );
}
