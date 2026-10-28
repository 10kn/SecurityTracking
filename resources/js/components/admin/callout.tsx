import { ChevronRightIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * A notice above the content (prototype v2 `.callout`): an icon, a bold line with details below, and an optional link
 * or button on the right. Danger is for "monitoring is silent because of an error"; the default is a warning. Under
 * 640px the action moves below the text.
 */
export function Callout({ tone = 'warning', icon, title, children, action, className }: { tone?: 'danger' | 'warning'; icon: ReactNode; title: ReactNode; children?: ReactNode; action?: ReactNode; className?: string }) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-start gap-x-4 gap-y-3 rounded-lg border border-border bg-surface px-5 py-3.5 max-sm:grid max-sm:grid-cols-[auto_minmax(0,1fr)] max-sm:gap-x-3 max-sm:gap-y-1.5 max-sm:px-3.5',
                tone === 'danger' && 'border-[color-mix(in_srgb,var(--danger)_35%,var(--border))] bg-danger-soft',
                className,
            )}
        >
            <span className={cn('mt-[3px] flex shrink-0 [&>svg]:size-[18px]', tone === 'danger' ? 'text-danger' : 'text-warning')}>{icon}</span>
            <div className="min-w-0 flex-[1_1_320px]">
                <strong className="font-semibold">{title}</strong>
                {children}
            </div>
            {action && <div className="shrink-0 max-sm:col-start-2">{action}</div>}
        </div>
    );
}

/** A muted sentence under the callout title. */
export function CalloutText({ className, ...props }: React.ComponentProps<'p'>) {
    return <p className={cn('mt-0.5 text-sm text-muted-foreground', className)} {...props} />;
}

/** One line per item under the callout title, each with its own link or details. */
export function CalloutList({ className, ...props }: React.ComponentProps<'ul'>) {
    return <ul className={cn('mt-1.5 grid gap-2 text-sm text-muted-foreground', className)} {...props} />;
}

export function CalloutListItem({ className, ...props }: React.ComponentProps<'li'>) {
    return <li className={cn('flex flex-wrap items-baseline gap-x-3 gap-y-0.5', className)} {...props} />;
}

/**
 * "Xem lỗi": the error and how to fix it, opened in place. Closed it sits after its line; open it takes the whole row,
 * boxed on the surface colour so it reads as part of its own line and not as the next item.
 */
export function CalloutDetails({ summary, children, className }: { summary: string; children: ReactNode; className?: string }) {
    return (
        <details className={cn('group mt-1 text-sm open:basis-full', className)}>
            <summary className="flex w-fit cursor-pointer list-none items-center gap-1 font-medium whitespace-nowrap text-link [&::-webkit-details-marker]:hidden">
                <ChevronRightIcon className="size-3.5 transition-transform group-open:rotate-90" aria-hidden />
                {summary}
            </summary>
            <div className="mt-1.5 grid gap-1.5 rounded-md border border-border bg-surface px-3 py-2.5 text-[13px] text-muted-foreground [overflow-wrap:anywhere]">{children}</div>
        </details>
    );
}

/** The dashed ring of "Thiếu dữ liệu", at icon size, so missing data never looks like a passing state. */
export function UnknownIcon() {
    return <span className="mt-[2px] size-3 rounded-full border-[1.5px] border-dashed border-warning" aria-hidden />;
}
