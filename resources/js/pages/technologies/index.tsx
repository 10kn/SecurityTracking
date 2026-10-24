import { Link, router } from '@inertiajs/react';
import { RefreshCwIcon } from 'lucide-react';

import { store as syncCatalog } from '@/actions/App/Http/Controllers/Admin/TechnologyCatalogSyncController';
import { create, edit } from '@/actions/App/Http/Controllers/Admin/TechnologyController';
import { Card, CardHeader } from '@/components/admin/card';
import { CellTitle, EmptyState, SortableTh, Table, TablePagination, TableSearch, Td, Th, Tr } from '@/components/admin/data-table';
import { PageHeader } from '@/components/admin/page-header';
import { StatusDot } from '@/components/admin/status-dot';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/lib/i18n';
import type { Paginated, StatusDotData, TableState } from '@/types';

interface TechnologyRow {
    id: number;
    name: string;
    slug: string;
    endoflife_product: string;
    release_source: string;
    branches_count: number;
    runtimes_count: number;
    catalog: StatusDotData;
    last_synced: string | null;
    last_error: string | null;
}

export default function TechnologiesIndex({ technologies, table }: { technologies: Paginated<TechnologyRow>; table: TableState }) {
    const t = useTranslation();
    const query = useTableQuery(table, ['technologies']);

    return (
        <>
            <PageHeader
                title={t('Công nghệ')}
                description={t('Công nghệ chọn được khi nhập version cho service. Danh mục version tự cập nhật mỗi giờ.')}
                actions={
                    <Button asChild>
                        <Link href={create.url()}>{t('Thêm công nghệ')}</Link>
                    </Button>
                }
            />
            <Card>
                <CardHeader>
                    <TableSearch value={table.search} placeholder={t('Tìm công nghệ')} onSearch={(search) => query({ search })} />
                    <span className="text-sm text-faint">{t(':count công nghệ', { count: technologies.meta.total })}</span>
                </CardHeader>
                {technologies.data.length === 0 ? (
                    table.search !== '' ? (
                        <EmptyState title={t('Không có công nghệ phù hợp')} description={t('Thử từ khóa khác.')} />
                    ) : (
                        <EmptyState
                            title={t('Chưa có công nghệ nào')}
                            description={t('Thêm công nghệ để chọn được khi nhập version cho service.')}
                            action={
                                <Button asChild>
                                    <Link href={create.url()}>{t('Thêm công nghệ')}</Link>
                                </Button>
                            }
                        />
                    )
                ) : (
                    <Table className="min-w-[880px]">
                        <thead>
                            <tr>
                                <SortableTh column="name" sort={table.sort} onSort={(sort) => query({ sort })}>
                                    {t('Công nghệ')}
                                </SortableTh>
                                <Th>{t('Nguồn bản phát hành')}</Th>
                                <SortableTh column="branches_count" sort={table.sort} onSort={(sort) => query({ sort })} align="right">
                                    {t('Nhánh')}
                                </SortableTh>
                                <SortableTh column="runtimes_count" sort={table.sort} onSort={(sort) => query({ sort })} align="right">
                                    {t('Đang dùng')}
                                </SortableTh>
                                <Th>{t('Danh mục version')}</Th>
                                <Th />
                            </tr>
                        </thead>
                        <tbody>
                            {technologies.data.map((technology) => (
                                <Tr key={technology.id} href={edit.url(technology.id)}>
                                    <Td>
                                        <CellTitle title={technology.name} sub={<span className="font-mono">{technology.endoflife_product}</span>} href={edit.url(technology.id)} />
                                    </Td>
                                    <Td className="text-sm">{technology.release_source}</Td>
                                    <Td align="right">{technology.branches_count}</Td>
                                    <Td align="right">{technology.runtimes_count}</Td>
                                    <Td>
                                        <StatusDot status={technology.catalog} />
                                        {technology.last_synced && <span className="mt-0.5 block text-sm text-muted-foreground">{technology.last_synced}</span>}
                                        {technology.last_error && <span className="mt-0.5 block max-w-[320px] text-sm text-danger-text">{technology.last_error}</span>}
                                    </Td>
                                    <Td align="right" className="whitespace-nowrap">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => router.post(syncCatalog.url(technology.id), {}, { preserveScroll: true, only: ['technologies'] })}
                                        >
                                            <RefreshCwIcon />
                                            {t('Đồng bộ ngay')}
                                        </Button>
                                        <Button variant="ghost" size="sm" asChild>
                                            <Link href={edit.url(technology.id)}>{t('Sửa')}</Link>
                                        </Button>
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                )}
                <TablePagination meta={technologies.meta} table={table} onChange={query} />
            </Card>
        </>
    );
}
