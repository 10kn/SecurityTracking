import { cn } from '@/lib/utils';

/** A soft badge for Critical and High only; the other severities stay plain text. */
export function SeverityBadge({ severity }: { severity: { value: string; label: string } }) {
    const tone = {
        critical: 'bg-danger-soft text-danger-text',
        high: 'bg-orange-soft text-orange-text',
    }[severity.value];

    return tone ? (
        <span className={cn('inline-flex h-6 items-center rounded-[5px] px-2 text-xs font-medium whitespace-nowrap', tone)}>{severity.label}</span>
    ) : (
        <span className="text-sm text-muted-foreground">{severity.label}</span>
    );
}
