import { cn } from '@/lib/utils';
import type { StatusDotData } from '@/types';

/**
 * A status as a small dot plus text, without a background. The "unknown" tone draws a dashed ring so missing data
 * never looks like a passing state.
 */
export function StatusDot({ status, className, title }: { status: StatusDotData; className?: string; title?: string | null }) {
    return (
        <span className={cn('status-dot', className)} data-tone={status.tone} title={title ?? undefined}>
            {status.label}
        </span>
    );
}
