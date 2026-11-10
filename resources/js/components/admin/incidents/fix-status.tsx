import { StatusDot } from '@/components/admin/status-dot';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { StatusDotData } from '@/types';

/** A row of the incidents list, also the header of the incident page (IncidentController::summary()). */
export interface IncidentSummary {
    id: number;
    title: string;
    lead: string | null;
    technology: { value: string; label: string };
    severity: { value: string; label: string };
    is_dismissed: boolean;
    is_known_exploited: boolean;
    first_published: string | null;
    notices_count: number;
    teams_told_count: number;
    teams_delivered_count: number;
    has_delivery_failures: boolean;
    fixes: { branch: string; version: string | null; is_affected: boolean; is_eol: boolean }[];
    fix_status: StatusDotData;
    is_outside_reminder_window: boolean;
    pending_suggestions_count: number;
    teams_to_acknowledge_count: number;
}

/**
 * What the vendor released, then each branch with its fixed version. A branch without one says "hết hỗ trợ" when the
 * catalog says it reached end of life, "chờ bản vá" when the vendor named it affected, and "chưa rõ" when the vendor did
 * not name it either way, so it may not be affected at all. An incident too old for reminders says so on a line of its
 * own. Compact, the branches keep to one line; the incident page lists them all.
 */
export function FixStatus({ incident, isCompact = false }: { incident: Pick<IncidentSummary, 'fixes' | 'fix_status' | 'is_outside_reminder_window'>; isCompact?: boolean }) {
    const t = useTranslation();
    const branchOf = (fix: IncidentSummary['fixes'][number]) => {
        if (fix.version) {
            return `${fix.branch} → ${fix.version}`;
        }

        if (fix.is_eol) {
            return t(':branch hết hỗ trợ', { branch: fix.branch });
        }

        return fix.is_affected ? t(':branch chờ bản vá', { branch: fix.branch }) : t(':branch chưa rõ', { branch: fix.branch });
    };

    return (
        <>
            <StatusDot status={incident.fix_status} />
            {incident.fixes.length > 0 && (
                <span className={cn('mt-0.5 block font-mono text-sm text-muted-foreground', isCompact && 'truncate')}>{incident.fixes.map(branchOf).join(' · ')}</span>
            )}
            {incident.is_outside_reminder_window && <span className="mt-0.5 block text-sm text-faint">{t('Ngoài cửa sổ nhắc')}</span>}
        </>
    );
}
