import { cn } from '@/lib/utils';

export interface TabItem<TValue extends string> {
    value: TValue;
    label: string;
    count?: number;
}

/** Pill tabs of prototype v2. The panels are rendered by the page. */
export function Tabs<TValue extends string>({ items, value, onChange, label }: { items: TabItem<TValue>[]; value: TValue; onChange: (value: TValue) => void; label: string }) {
    return (
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div className="inline-flex flex-wrap gap-0.5 rounded-lg border border-border bg-surface p-[3px]" role="tablist" aria-label={label}>
                {items.map((item) => (
                    <button
                        key={item.value}
                        type="button"
                        role="tab"
                        aria-selected={item.value === value}
                        onClick={() => onChange(item.value)}
                        className={cn(
                            'inline-flex h-8 items-center gap-1.5 rounded-md px-3 text-base font-medium text-muted-foreground hover:text-foreground',
                            item.value === value && 'bg-surface-2 text-foreground',
                        )}
                    >
                        {item.label}
                        {item.count !== undefined && <span className="text-xs text-faint tabular-nums">{item.count}</span>}
                    </button>
                ))}
            </div>
        </div>
    );
}
