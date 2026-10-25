import { Link, router } from '@inertiajs/react';
import { RefreshCwIcon } from 'lucide-react';

import { edit, index } from '@/actions/App/Http/Controllers/Admin/ProjectController';
import { store as syncLockfiles } from '@/actions/App/Http/Controllers/Admin/ProjectLockfileSyncController';
import { create as createService, edit as editService } from '@/actions/App/Http/Controllers/Admin/ServiceController';
import { edit as editTeam } from '@/actions/App/Http/Controllers/Admin/TeamController';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/admin/card';
import { CellTitle, EmptyState, Table, Td, Th, Tr } from '@/components/admin/data-table';
import { PageHeader } from '@/components/admin/page-header';
import { LockfilesCell, OpenFindingsCell, RuntimesCell, serviceSubline, type ServiceRow } from '@/components/admin/services/service-cells';
import { StatusDot } from '@/components/admin/status-dot';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import type { StatusDotData } from '@/types';

interface Props {
    project: {
        id: number;
        name: string;
        slug: string;
        repository_url: string | null;
        repository_href: string | null;
        repository_branch: string | null;
        lockfile_sync: { status: StatusDotData; synced_at: string | null; commit: string | null; commit_href: string | null; error: string | null } | null;
        team: { id: number; name: string };
        alert_channel: StatusDotData;
        created_at: string | null;
    };
    services: { data: ServiceRow[] };
}

export default function ProjectsShow({ project, services }: Props) {
    const t = useTranslation();
    const createServiceUrl = createService.url({ query: { project: project.id } });

    return (
        <>
            <PageHeader
                title={project.name}
                breadcrumbs={[{ label: t('Projects'), href: index.url() }, { label: project.name }]}
                description={
                    <>
                        {t('Team')}{' '}
                        <Link href={editTeam.url(project.team.id)} className="font-medium text-link hover:underline hover:underline-offset-3">
                            {project.team.name}
                        </Link>{' '}
                        · {t(':count service', { count: services.data.length })}
                    </>
                }
                actions={
                    <>
                        {project.lockfile_sync && (
                            <Button variant="secondary" onClick={() => router.post(syncLockfiles.url(project.id), {}, { preserveScroll: true })}>
                                <RefreshCwIcon />
                                {t('Lấy lockfile ngay')}
                            </Button>
                        )}
                        <Button variant="secondary" asChild>
                            <Link href={edit.url(project.id)}>{t('Sửa')}</Link>
                        </Button>
                        <Button asChild>
                            <Link href={createServiceUrl}>{t('Thêm service')}</Link>
                        </Button>
                    </>
                }
            />
            <Card className="mb-6">
                <CardBody>
                    <dl className="grid grid-cols-3 gap-x-6 gap-y-5 max-lg:grid-cols-2 max-sm:grid-cols-1">
                        <div>
                            <dt className="text-sm text-muted-foreground">{t('Repository')}</dt>
                            <dd className="mt-1 font-mono text-sm [overflow-wrap:anywhere]">
                                {project.repository_href ? (
                                    <a href={project.repository_href} target="_blank" rel="noreferrer noopener" className="text-link hover:underline hover:underline-offset-3">
                                        {project.repository_url}
                                    </a>
                                ) : (
                                    (project.repository_url ?? '—')
                                )}
                            </dd>
                        </div>
                        {project.lockfile_sync && (
                            <div>
                                <dt className="text-sm text-muted-foreground">{t('Lockfile từ GitHub')}</dt>
                                <dd className="mt-1">
                                    <StatusDot status={project.lockfile_sync.status} className="items-baseline whitespace-normal" />
                                    <span className="mt-0.5 block text-sm text-muted-foreground">
                                        {t('Branch :branch', { branch: project.repository_branch ?? t('mặc định') })}
                                        {project.lockfile_sync.commit && (
                                            <>
                                                {' · '}
                                                {project.lockfile_sync.commit_href ? (
                                                    <a href={project.lockfile_sync.commit_href} target="_blank" rel="noreferrer noopener" className="font-mono text-link hover:underline hover:underline-offset-3">
                                                        {project.lockfile_sync.commit}
                                                    </a>
                                                ) : (
                                                    <span className="font-mono">{project.lockfile_sync.commit}</span>
                                                )}
                                            </>
                                        )}
                                    </span>
                                    {project.lockfile_sync.synced_at && (
                                        <span className="mt-0.5 block text-sm text-muted-foreground">{t('Thành công gần nhất: :time', { time: project.lockfile_sync.synced_at })}</span>
                                    )}
                                    {project.lockfile_sync.error && <span className="mt-0.5 block text-sm whitespace-pre-line text-danger-text">{project.lockfile_sync.error}</span>}
                                </dd>
                            </div>
                        )}
                        <div>
                            <dt className="text-sm text-muted-foreground">{t('Team sở hữu')}</dt>
                            <dd className="mt-1">
                                <Link href={editTeam.url(project.team.id)} className="font-medium text-link hover:underline hover:underline-offset-3">
                                    {project.team.name}
                                </Link>
                            </dd>
                        </div>
                        <div>
                            <dt className="text-sm text-muted-foreground">{t('Kênh cảnh báo')}</dt>
                            <dd className="mt-1">
                                <StatusDot status={project.alert_channel} />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-sm text-muted-foreground">{t('Tạo lúc')}</dt>
                            <dd className="mt-1">{project.created_at ?? '—'}</dd>
                        </div>
                    </dl>
                </CardBody>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>
                        {t('Services')} <span className="ml-1 text-sm font-normal text-faint">{services.data.length}</span>
                    </CardTitle>
                </CardHeader>
                {services.data.length === 0 ? (
                    <EmptyState title={t('Project chưa có service nào')} description={t('Thêm service và nhập version runtime để hệ thống bắt đầu theo dõi.')} />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>{t('Service')}</Th>
                                <Th>{t('Runtime')}</Th>
                                <Th>{t('Packages')}</Th>
                                <Th align="right">{t('Finding mở')}</Th>
                                <Th>{t('Cập nhật')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {services.data.map((service) => (
                                <Tr key={service.id} href={editService.url(service.id)}>
                                    <Td>
                                        <CellTitle title={service.name} sub={serviceSubline(service)} href={editService.url(service.id)} />
                                    </Td>
                                    <Td>
                                        <RuntimesCell runtimes={service.runtimes} />
                                    </Td>
                                    <Td>
                                        <LockfilesCell lockfiles={service.lockfiles} />
                                    </Td>
                                    <Td align="right">
                                        <OpenFindingsCell count={service.open_findings_count} />
                                    </Td>
                                    <Td className="text-sm whitespace-nowrap text-muted-foreground">{service.updated_at ?? '—'}</Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>
        </>
    );
}
