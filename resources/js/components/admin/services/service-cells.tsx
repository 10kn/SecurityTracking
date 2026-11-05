import { StatusDot } from '@/components/admin/status-dot';
import { TechnologyName } from '@/components/admin/technology-logo';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { StatusDotData } from '@/types';

export interface ServiceRow {
    id: number;
    name: string;
    slug: string;
    kind: string;
    exposure: string;
    repository_path: string;
    project?: { id: number; name: string; team: string | null };
    runtimes: { technology: string; name: string; status: StatusDotData; reason: string }[];
    lockfiles: { path: string; state: StatusDotData | null; packages: string | null; vulnerable: StatusDotData | null; error: string | null }[];
    /** Why the last lockfile sync from the repository failed; the lockfiles below are then those of an earlier sync. */
    lockfiles_sync_error: string | null;
    open_findings_count: number;
    updated_at: string | null;
}

/** "ECS · Public · /apps/api" under the service name. */
export function serviceSubline(service: ServiceRow) {
    return (
        <>
            {service.kind} · {service.exposure} · <span className="font-mono">{service.repository_path}</span>
        </>
    );
}

function Missing({ reason }: { reason: string }) {
    const t = useTranslation();

    return (
        <>
            <StatusDot status={{ label: t('Thiếu dữ liệu'), tone: 'unknown' }} />
            <span className="mt-0.5 block text-sm text-muted-foreground">{reason}</span>
        </>
    );
}

/**
 * One line per runtime: "PHP 8.3.12" and its status, with the reason below unless the runtime is fine, so the reason
 * also reads on a phone.
 */
export function RuntimesCell({ runtimes }: { runtimes: ServiceRow['runtimes'] }) {
    const t = useTranslation();

    if (runtimes.length === 0) {
        return <Missing reason={t('Chưa nhập version')} />;
    }

    return (
        <div className="grid gap-1">
            {runtimes.map((runtime) => (
                <div key={runtime.name}>
                    <div className="flex flex-wrap items-center gap-x-2">
                        <TechnologyName technology={runtime.technology} label={runtime.name} className="font-mono text-sm" />
                        <StatusDot status={runtime.status} />
                    </div>
                    {runtime.status.tone !== 'success' && <span className="block max-w-[320px] text-sm text-muted-foreground">{runtime.reason}</span>}
                </div>
            ))}
        </div>
    );
}

/**
 * One line per lockfile: its package count, how many packages have an open vulnerability, and why it cannot be read.
 * A failed sync from the repository comes first, so a service listed as not fully evaluated says why.
 */
export function LockfilesCell({ lockfiles, syncError }: { lockfiles: ServiceRow['lockfiles']; syncError: string | null }) {
    const t = useTranslation();

    return (
        <div className="grid gap-1">
            {syncError !== null && (
                <div>
                    <StatusDot status={{ label: t('Lần đồng bộ gần nhất lỗi'), tone: 'warning' }} />
                    <span className="block max-w-[320px] text-sm text-muted-foreground">{syncError}</span>
                </div>
            )}
            {lockfiles.length === 0 && (
                <div>
                    <Missing reason={t('Chưa có lockfile')} />
                </div>
            )}
            {lockfiles.map((lockfile) => (
                <div key={lockfile.path}>
                    <div className="flex flex-wrap items-center gap-x-2">
                        <span className="font-mono text-sm">{lockfile.path}</span>
                        {lockfile.state && <StatusDot status={lockfile.state} />}
                        {lockfile.packages && <span className="text-sm text-muted-foreground">{lockfile.packages}</span>}
                        {lockfile.vulnerable && <StatusDot status={lockfile.vulnerable} />}
                    </div>
                    {lockfile.error && <span className="block max-w-[320px] text-sm text-muted-foreground">{lockfile.error}</span>}
                </div>
            ))}
        </div>
    );
}

export function OpenFindingsCell({ count }: { count: number }) {
    return <span className={cn(count > 0 ? 'font-medium text-danger-text' : 'text-faint')}>{count}</span>;
}
