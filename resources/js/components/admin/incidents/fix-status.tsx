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
    fixes: { branch: string; version: string | null; is_eol: boolean }[];
    fix_status: StatusDotData;
    is_outside_reminder_window: boolean;
    pending_suggestions_count: number;
    teams_to_acknowledge_count: number;
}

/**
 * What the vendor released, then each branch with its fixed version, or "chờ" while it waits ("hết hỗ trợ" when the
 * catalog says the branch reached end of life). An incident too old for reminders says so on a line of its own.
 */
export function FixStatus({ incident }: { incident: Pick<IncidentSummary, 'fixes' | 'fix_status' | 'is_outside_reminder_window'> }) {
    const t = useTranslation();
    const branchOf = (fix: IncidentSummary['fixes'][number]) =>
        fix.version ? `${fix.branch} → ${fix.version}` : fix.is_eol ? t(':branch hết hỗ trợ', { branch: fix.branch }) : t(':branch chờ', { branch: fix.branch });

    return (
        <>
            <StatusDot status={incident.fix_status} />
            {incident.fixes.length > 0 && <span className="mt-0.5 block font-mono text-sm text-muted-foreground">{incident.fixes.map(branchOf).join(' · ')}</span>}
            {incident.is_outside_reminder_window && <span className="mt-0.5 block text-sm text-faint">{t('Ngoài cửa sổ nhắc')}</span>}
        </>
    );
}
