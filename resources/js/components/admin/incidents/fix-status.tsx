import { StatusDot } from '@/components/admin/status-dot';
import { useTranslation } from '@/lib/i18n';
import type { StatusDotData } from '@/types';

/** A row of the incidents list, also the header of the incident page (IncidentController::summary()). */
export interface IncidentSummary {
    id: number;
    title: string;
    lead: string | null;
    technology: { value: string; label: string };
    severity: { value: string; label: string };
    first_published: string | null;
    notices_count: number;
    teams_told_count: number;
    fixes: { branch: string; version: string | null }[];
    fix_status: StatusDotData;
    pending_suggestions_count: number;
    teams_to_acknowledge_count: number;
}

/** The fix status, then each branch with its fixed version, or "chờ" while it waits. */
export function FixStatus({ incident }: { incident: Pick<IncidentSummary, 'fixes' | 'fix_status'> }) {
    const t = useTranslation();

    return (
        <>
            <StatusDot status={incident.fix_status} />
            {incident.fixes.length > 0 && (
                <span className="mt-0.5 block font-mono text-sm text-muted-foreground">
                    {incident.fixes.map((fix) => (fix.version ? `${fix.branch} → ${fix.version}` : t(':branch chờ', { branch: fix.branch }))).join(' · ')}
                </span>
            )}
        </>
    );
}
