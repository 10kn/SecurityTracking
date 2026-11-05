import { CheckIcon, ChevronDownIcon, PlusIcon } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';

import { Command, CommandEmpty, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { inputClassName } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface ComboboxOption<TValue extends string | number> {
    value: TValue;
    label: string;
    description?: string;
    /** Shown before the label, in the list and once chosen, e.g. a technology's logo. */
    icon?: ReactNode;
    disabled?: boolean;
}

/** Rendering thousands of rows makes typing lag; the search narrows the list anyway. */
const MAX_VISIBLE_OPTIONS = 100;

/**
 * A select with a search box. Filtering runs in the browser, so the list narrows on every key without a request.
 *
 * With `onCreate`, text that matches no option can be chosen as a new value, e.g. a version such as
 * "8.3.12-1+deb.sury.org+1" that no catalog lists. `canCreate` decides which text is acceptable.
 */
export function Combobox<TValue extends string | number>({
    id,
    value,
    options,
    onChange,
    onCreate,
    canCreate = (text) => text !== '',
    match = 'contains',
    placeholder,
    searchPlaceholder,
    emptyText,
    invalid = false,
    disabled = false,
    mono = false,
    className,
}: {
    id?: string;
    value: TValue | null;
    options: ComboboxOption<TValue>[];
    onChange: (value: TValue) => void;
    onCreate?: (text: string) => void;
    canCreate?: (text: string) => boolean;
    /** "prefix" suits versions: "8.3" lists 8.3.x but not 18.3.0. */
    match?: 'contains' | 'prefix';
    placeholder?: string;
    searchPlaceholder?: string;
    emptyText?: string;
    invalid?: boolean;
    disabled?: boolean;
    mono?: boolean;
    className?: string;
}) {
    const t = useTranslation();
    const [isOpen, setIsOpen] = useState(false);
    const [search, setSearch] = useState('');

    const selected = options.find((option) => option.value === value);
    const query = search.trim();

    const visibleOptions = useMemo(() => {
        const needle = query.toLocaleLowerCase();

        if (needle === '') {
            return options.slice(0, MAX_VISIBLE_OPTIONS);
        }

        const matches = options.filter((option) => {
            const label = option.label.toLocaleLowerCase();

            return match === 'prefix'
                ? label.startsWith(needle)
                : label.includes(needle) || (option.description?.toLocaleLowerCase().includes(needle) ?? false);
        });

        // An exact match comes first, so Enter after typing "8.3.1" picks 8.3.1 rather than 8.3.19.
        const exact = matches.findIndex((option) => option.label.toLocaleLowerCase() === needle);

        if (exact > 0) {
            matches.unshift(...matches.splice(exact, 1));
        }

        return matches.slice(0, MAX_VISIBLE_OPTIONS);
    }, [options, query, match]);

    const isNewValue = onCreate !== undefined && query !== '' && !options.some((option) => String(option.value) === query) && canCreate(query);

    const close = () => {
        setIsOpen(false);
        setSearch('');
    };

    return (
        <Popover open={isOpen} onOpenChange={(open) => (open ? setIsOpen(true) : close())}>
            <PopoverTrigger asChild disabled={disabled}>
                <button
                    id={id}
                    type="button"
                    role="combobox"
                    aria-expanded={isOpen}
                    aria-invalid={invalid || undefined}
                    className={cn(inputClassName, 'flex items-center justify-between gap-2 text-left', isOpen && 'border-primary shadow-[0_0_0_3px_var(--focus)]', className)}
                >
                    <span className={cn('flex min-w-0 items-center gap-2', mono && 'font-mono text-[calc(var(--fs-sm)-0.5px)]', value === null && 'text-faint')}>
                        {selected?.icon}
                        <span className="truncate">{selected?.label ?? (value !== null ? String(value) : (placeholder ?? t('Chọn')))}</span>
                    </span>
                    <ChevronDownIcon className="size-4 shrink-0 text-faint" aria-hidden />
                </button>
            </PopoverTrigger>
            <PopoverContent className="w-(--radix-popover-trigger-width) min-w-56 p-0">
                <Command shouldFilter={false} loop>
                    <CommandInput value={search} onValueChange={setSearch} placeholder={searchPlaceholder ?? t('Tìm')} className={cn(mono && 'font-mono text-sm')} />
                    <CommandList>
                        {isNewValue && (
                            <CommandItem
                                value={`create:${query}`}
                                onSelect={() => {
                                    onCreate(query);
                                    close();
                                }}
                            >
                                <PlusIcon className="text-faint" />
                                <span className="min-w-0 truncate">{t('Dùng “:value”', { value: query })}</span>
                            </CommandItem>
                        )}
                        {visibleOptions.map((option) => (
                            <CommandItem
                                key={option.value}
                                value={String(option.value)}
                                disabled={option.disabled}
                                onSelect={() => {
                                    onChange(option.value);
                                    close();
                                }}
                            >
                                {option.icon}
                                <span className={cn('min-w-0 flex-1 truncate', mono && 'font-mono text-sm')}>{option.label}</span>
                                {option.description && <span className="shrink-0 text-sm text-faint">{option.description}</span>}
                                <CheckIcon className={cn('text-foreground', option.value !== value && 'invisible')} aria-hidden />
                            </CommandItem>
                        ))}
                        {!isNewValue && <CommandEmpty>{emptyText ?? t('Không có kết quả phù hợp.')}</CommandEmpty>}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
