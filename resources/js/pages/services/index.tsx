import { Link } from '@inertiajs/react';
import { ChevronDownIcon, XIcon } from 'lucide-react';
import { useRef, useState } from 'react';

import { show as showProject } from '@/actions/App/Http/Controllers/Admin/ProjectController';
import { create, edit } from '@/actions/App/Http/Controllers/Admin/ServiceController';
import { Card, CardHeader } from '@/components/admin/card';
import { CellTitle, EmptyState, SortableTh, Table, TableFilter, TablePagination, TableSearch, Td, Th, Tr, type TableSearchHandle } from '@/components/admin/data-table';
import { isGitHubActionsFile } from '@/components/admin/lockfile-logo';
import { PageHeader } from '@/components/admin/page-header';
import { LockfilesCell, OpenFindingsCell, RuntimesCell, serviceSubline, type ServiceRow } from '@/components/admin/services/service-cells';
import { StatusDot } from '@/components/admin/status-dot';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/lib/i18n';
import type { Option, Paginated, TableState } from '@/types';

export default function ServicesIndex({ services, table, projects }: { services: Paginated<ServiceRow>; table: TableState & { project: number | null; attention: number | null }; projects: Option<number>[] }) {
    const t = useTranslation();
    const query = useTableQuery(table, ['services']);
    const searchRef = useRef<TableSearchHandle>(null);
    const isFiltered = table.search !== '' || table.project !== null || table.attention !== null;

    return (
        <>
            <PageHeader
                title={t('Services')}
                description={t('Version runtime và lockfile đang chạy trên production của từng service.')}
                actions={
                    <Button asChild>
                        <Link href={create.url()}>{t('Thêm service')}</Link>
                    </Button>
                }
            />
            <Card className="@container">
                <CardHeader>
                    <div className="flex flex-1 flex-wrap items-center gap-2">
                        <TableSearch ref={searchRef} value={table.search} placeholder={t('Tìm service')} onSearch={(search) => query({ search })} />
                        <TableFilter value={table.project} label={t('Project')} allLabel={t('Mọi project')} options={projects} onChange={(project) => query({ project })} />
                        <TableFilter
                            value={table.attention}
                            label={t('Đánh giá')}
                            allLabel={t('Mọi service')}
                            options={[{ value: 1, label: t('Chưa đánh giá đủ') }]}
                            onChange={(attention) => query({ attention })}
                        />
                        {isFiltered && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                    searchRef.current?.clear();
                                    query({ search: null, project: null, attention: null });
                                }}
                            >
                                {t('Bỏ lọc')}
                                <XIcon />
                            </Button>
                        )}
                    </div>
                    <span className="text-sm text-faint max-sm:hidden">{t(':count service', { count: services.meta.total })}</span>
                </CardHeader>
                {services.data.length === 0 ? (
                    isFiltered ? (
                        <EmptyState title={t('Không có service phù hợp')} description={t('Thử từ khóa hoặc bộ lọc khác.')} />
                    ) : (
                        <EmptyState
                            title={t('Chưa có service nào')}
                            description={t('Thêm service và nhập version runtime để hệ thống bắt đầu theo dõi.')}
                            action={
                                <Button asChild>
                                    <Link href={create.url()}>{t('Thêm service')}</Link>
                                </Button>
                            }
                        />
                    )
                ) : (
                    <Table className="min-w-[850px]">
                        <thead>
                            <tr>
                                <SortableTh column="name" sort={table.sort} onSort={(sort) => query({ sort })}>
                                    {t('Service')}
                                </SortableTh>
                                <SortableTh column="project" sort={table.sort} onSort={(sort) => query({ sort })}>
                                    {t('Project')}
                                </SortableTh>
                                <Th>{t('Runtime')}</Th>
                                <Th>{t('Packages & CI')}</Th>
                                <SortableTh column="open_findings_count" sort={table.sort} onSort={(sort) => query({ sort })} align="right">
                                    {t('Finding mở')}
                                </SortableTh>
                                <Th>{t('Cập nhật')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {services.data.map((service) => (
                                <ServiceTableRow key={service.id} service={service} />
                            ))}
                        </tbody>
                    </Table>
                )}
                <TablePagination meta={services.meta} table={table} onChange={query} />
            </Card>
        </>
    );
}

function ServiceTableRow({ service }: { service: ServiceRow }) {
    const t = useTranslation();
    const [isExpanded, setIsExpanded] = useState(false);
    const detailsId = `service-files-${service.id}`;
    const serviceUrl = edit.url(service.id);
    const syncError = service.lockfiles_sync_error;
    const lockfiles: ServiceRow['lockfiles'] = [];
    const ciFiles: ServiceRow['lockfiles'] = [];

    for (const file of service.lockfiles) {
        (isGitHubActionsFile(file.path) ? ciFiles : lockfiles).push(file);
    }

    const groups = [
        { title: t('Lockfile'), files: lockfiles, countLabel: ':count lockfile' },
        { title: 'GitHub Actions', files: ciFiles, countLabel: ':count tệp CI' },
    ].filter(({ files }) => files.length > 0);
    const summary = groups.map(({ files, countLabel }) => t(countLabel, { count: files.length })).join(' · ') || t('Chi tiết đồng bộ');
    const hasIncompleteFile = service.lockfiles.some((file) => file.state?.tone === 'unknown' || file.error !== null);
    const hasDetails = groups.length > 0 || syncError !== null;
    const showDetails = hasDetails && isExpanded;

    return (
        <>
            <Tr href={serviceUrl}>
                <Td className="align-top">
                    <CellTitle title={service.name} sub={serviceSubline(service)} href={serviceUrl} />
                </Td>
                <Td className="align-top">
                    {service.project && <CellTitle title={service.project.name} sub={service.project.team ?? undefined} href={showProject.url(service.project.id)} />}
                </Td>
                <Td className="align-top">
                    <RuntimesCell runtimes={service.runtimes} stackStatus />
                </Td>
                <Td className="align-top">
                    <div className="grid justify-items-start gap-0.5">
                        {hasDetails && (
                            <Button
                                type="button"
                                variant="link"
                                size="sm"
                                className="h-auto justify-start px-0 text-left"
                                aria-label={`${summary} (${service.name})`}
                                aria-expanded={showDetails}
                                aria-controls={showDetails ? detailsId : undefined}
                                onClick={() => setIsExpanded((expanded) => !expanded)}
                            >
                                {summary}
                                <ChevronDownIcon className={showDetails ? 'rotate-180' : undefined} aria-hidden />
                            </Button>
                        )}
                        {syncError !== null && <StatusDot status={{ label: t('Lần đồng bộ gần nhất lỗi'), tone: 'warning' }} />}
                        {groups.length === 0 && <StatusDot status={{ label: t('Chưa có lockfile'), tone: 'unknown' }} />}
                        {hasIncompleteFile && <StatusDot status={{ label: t('Có tệp chưa quét đầy đủ'), tone: 'unknown' }} />}
                    </div>
                </Td>
                <Td align="right" className="align-top">
                    <OpenFindingsCell count={service.open_findings_count} serviceId={service.id} />
                </Td>
                <Td className="align-top text-sm whitespace-nowrap text-muted-foreground">{service.updated_at ?? '—'}</Td>
            </Tr>
            {showDetails && (
                <tr id={detailsId} className="bg-surface-2 last:[&>td]:border-b-0">
                    <Td colSpan={6}>
                        <div className="sticky left-5 grid max-w-[calc(100cqw-2.5rem)] gap-3 max-sm:left-3.5 max-sm:max-w-[calc(100cqw-1.75rem)]">
                            {syncError !== null && <p className="text-sm wrap-anywhere text-muted-foreground">{syncError}</p>}
                            {groups.length > 0 && (
                                <div className="grid gap-4 @xl:grid-cols-[repeat(auto-fit,minmax(16rem,1fr))]">
                                    {groups.map(({ title, files, countLabel }) => (
                                        <section key={countLabel} className="grid min-w-0 content-start gap-1.5">
                                            <h3 className="text-sm font-medium text-muted-foreground">{title}</h3>
                                            <LockfilesCell lockfiles={files} />
                                        </section>
                                    ))}
                                </div>
                            )}
                        </div>
                    </Td>
                </tr>
            )}
        </>
    );
}
