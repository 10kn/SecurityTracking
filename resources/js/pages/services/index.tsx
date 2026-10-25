import { Link } from '@inertiajs/react';

import { show as showProject } from '@/actions/App/Http/Controllers/Admin/ProjectController';
import { create, edit } from '@/actions/App/Http/Controllers/Admin/ServiceController';
import { Card, CardHeader } from '@/components/admin/card';
import { CellTitle, EmptyState, SortableTh, Table, TableFilter, TablePagination, TableSearch, Td, Th, Tr } from '@/components/admin/data-table';
import { PageHeader } from '@/components/admin/page-header';
import { LockfilesCell, OpenFindingsCell, RuntimesCell, serviceSubline, type ServiceRow } from '@/components/admin/services/service-cells';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/lib/i18n';
import type { Option, Paginated, TableState } from '@/types';

export default function ServicesIndex({ services, table, projects }: { services: Paginated<ServiceRow>; table: TableState & { project: number | null }; projects: Option<number>[] }) {
    const t = useTranslation();
    const query = useTableQuery(table, ['services']);
    const isFiltered = table.search !== '' || table.project !== null;

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
            <Card>
                <CardHeader>
                    <div className="flex flex-1 flex-wrap items-center gap-2">
                        <TableSearch value={table.search} placeholder={t('Tìm service')} onSearch={(search) => query({ search })} />
                        <TableFilter value={table.project} label={t('Project')} allLabel={t('Mọi project')} options={projects} onChange={(project) => query({ project })} />
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
                    <Table className="min-w-[960px]">
                        <thead>
                            <tr>
                                <SortableTh column="name" sort={table.sort} onSort={(sort) => query({ sort })}>
                                    {t('Service')}
                                </SortableTh>
                                <SortableTh column="project" sort={table.sort} onSort={(sort) => query({ sort })}>
                                    {t('Project')}
                                </SortableTh>
                                <Th>{t('Runtime')}</Th>
                                <Th>{t('Packages')}</Th>
                                <SortableTh column="open_findings_count" sort={table.sort} onSort={(sort) => query({ sort })} align="right">
                                    {t('Finding mở')}
                                </SortableTh>
                                <Th>{t('Cập nhật')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {services.data.map((service) => (
                                <Tr key={service.id} href={edit.url(service.id)}>
                                    <Td>
                                        <CellTitle title={service.name} sub={serviceSubline(service)} href={edit.url(service.id)} />
                                    </Td>
                                    <Td>
                                        {service.project && (
                                            <CellTitle title={service.project.name} sub={service.project.team ?? undefined} href={showProject.url(service.project.id)} />
                                        )}
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
                <TablePagination meta={services.meta} table={table} onChange={query} />
            </Card>
        </>
    );
}
