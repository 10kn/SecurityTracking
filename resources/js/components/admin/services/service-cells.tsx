import { StatusDot } from '@/components/admin/status-dot';
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
    runtimes: { name: string; status: StatusDotData; reason: string | null }[];
    lockfiles: { path: string; state: StatusDotData | null; packages: string | null; vulnerable: StatusDotData | null; error: string | null }[];
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

/** One line per runtime: "PHP 8.3.12" and its status; the reason shows on hover. */
export function RuntimesCell({ runtimes }: { runtimes: ServiceRow['runtimes'] }) {
    const t = useTranslation();

    if (runtimes.length === 0) {
        return <Missing reason={t('Chưa nhập version')} />;
    }

    return (
        <div className="grid gap-1">
            {runtimes.map((runtime) => (
                <div key={runtime.name} className="flex flex-wrap items-center gap-x-2" title={runtime.reason ?? undefined}>
                    <span className="font-mono text-sm">{runtime.name}</span>
                    <StatusDot status={runtime.status} />
                </div>
            ))}
        </div>
    );
}

/** One line per lockfile: its package count, and how many packages have an open vulnerability. */
export function LockfilesCell({ lockfiles }: { lockfiles: ServiceRow['lockfiles'] }) {
    const t = useTranslation();

    if (lockfiles.length === 0) {
        return <Missing reason={t('Chưa có lockfile')} />;
    }

    return (
        <div className="grid gap-1">
            {lockfiles.map((lockfile) => (
                <div key={lockfile.path} className="flex flex-wrap items-center gap-x-2" title={lockfile.error ?? undefined}>
                    <span className="font-mono text-sm">{lockfile.path}</span>
                    {lockfile.state && <StatusDot status={lockfile.state} />}
                    {lockfile.packages && <span className="text-sm text-muted-foreground">{lockfile.packages}</span>}
                    {lockfile.vulnerable && <StatusDot status={lockfile.vulnerable} />}
                </div>
            ))}
        </div>
    );
}

export function OpenFindingsCell({ count }: { count: number }) {
    return <span className={cn(count > 0 ? 'font-medium text-danger-text' : 'text-faint')}>{count}</span>;
}
