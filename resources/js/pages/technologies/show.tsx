import { router } from '@inertiajs/react';
import { RefreshCwIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { store as syncCatalog } from '@/actions/App/Http/Controllers/Admin/TechnologyCatalogSyncController';
import { index } from '@/actions/App/Http/Controllers/Admin/TechnologyController';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/admin/card';
import { EmptyState, Table, TableFilter, TablePagination, TableSearch, Td, Th, Tr } from '@/components/admin/data-table';
import { PageHeader } from '@/components/admin/page-header';
import { StatusDot } from '@/components/admin/status-dot';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/lib/i18n';
import type { Paginated, StatusDotData, TableState } from '@/types';

interface Props {
    technology: {
        value: string;
        name: string;
        release_source: string;
        release_source_url: string;
        endoflife_url: string;
        runtimes_count: number;
        catalog: StatusDotData;
        last_synced: string | null;
        last_error: string | null;
    };
    branches: {
        branch: string;
        latest_version: string | null;
        released_at: string | null;
        active_support_until: string | null;
        security_support_until: string | null;
        support: StatusDotData;
    }[];
    releases: Paginated<{ version: string; branch: string; released_at: string | null; is_security_release: boolean | null }>;
    table: TableState & { branch: string | null };
}

export default function TechnologiesShow({ technology, branches, releases, table }: Props) {
    const t = useTranslation();
    const query = useTableQuery(table, ['releases']);
    const isFiltered = table.search !== '' || table.branch !== null;

    return (
        <>
            <PageHeader
                title={technology.name}
                breadcrumbs={[{ label: t('Công nghệ'), href: index.url() }, { label: technology.name }]}
                description={t('Nhánh và ngày hết hỗ trợ lấy từ endoflife.date, danh sách bản phát hành lấy từ :source.', { source: technology.release_source })}
                actions={
                    <Button variant="secondary" onClick={() => router.post(syncCatalog.url(technology.value), {}, { preserveScroll: true })}>
                        <RefreshCwIcon />
                        {t('Đồng bộ ngay')}
                    </Button>
                }
            />
            <Card className="mb-6">
                <CardBody>
                    <dl className="grid grid-cols-3 gap-x-6 gap-y-5 max-lg:grid-cols-2 max-sm:grid-cols-1">
                        <Detail label={t('Danh mục version')}>
                            <StatusDot status={technology.catalog} className="items-baseline whitespace-normal" />
                            {technology.last_synced && <span className="mt-0.5 block text-sm text-muted-foreground">{technology.last_synced}</span>}
                            {technology.last_error && <span className="mt-0.5 block text-sm text-danger-text">{technology.last_error}</span>}
                        </Detail>
                        <Detail label={t('Nhánh và ngày hết hỗ trợ')}>
                            <ExternalLink href={technology.endoflife_url}>endoflife.date</ExternalLink>
                        </Detail>
                        <Detail label={t('Danh sách bản phát hành')}>
                            <ExternalLink href={technology.release_source_url}>{technology.release_source}</ExternalLink>
                        </Detail>
                        <Detail label={t('Tin bảo mật sớm')}>
                            {t('Theo dõi tin trên :source mỗi 15 phút', { source: technology.release_source })}
                        </Detail>
                        <Detail label={t('Đang dùng')}>{t(':count service', { count: technology.runtimes_count })}</Detail>
                    </dl>
                </CardBody>
            </Card>
            <Card className="mb-6">
                <CardHeader>
                    <CardTitle>
                        {t('Nhánh')} <span className="ml-1 text-sm font-normal text-faint">{branches.length}</span>
                    </CardTitle>
                </CardHeader>
                {branches.length === 0 ? (
                    <EmptyState title={t('Chưa có danh mục version')} description={t('Bấm Đồng bộ ngay để lấy danh mục từ nguồn.')} />
                ) : (
                    <Table className="min-w-[760px]">
                        <thead>
                            <tr>
                                <Th>{t('Nhánh')}</Th>
                                <Th>{t('Bản mới nhất')}</Th>
                                <Th>{t('Phát hành')}</Th>
                                <Th>{t('Hết hỗ trợ tích cực')}</Th>
                                <Th>{t('Hết vá bảo mật')}</Th>
                                <Th>{t('Trạng thái')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {branches.map((branch) => (
                                <Tr key={branch.branch}>
                                    <Td className="font-mono font-medium">{branch.branch}</Td>
                                    <Td className="font-mono">{branch.latest_version ?? '—'}</Td>
                                    <Td className="text-muted-foreground tabular-nums">{branch.released_at ?? '—'}</Td>
                                    <Td className="text-muted-foreground tabular-nums">{branch.active_support_until ?? '—'}</Td>
                                    <Td className="text-muted-foreground tabular-nums">{branch.security_support_until ?? '—'}</Td>
                                    <Td>
                                        <StatusDot status={branch.support} />
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>
            <Card>
                <CardHeader>
                    <div className="flex flex-1 flex-wrap items-center gap-2">
                        <TableSearch value={table.search} placeholder={t('Tìm version')} onSearch={(search) => query({ search })} />
                        <TableFilter
                            value={table.branch}
                            label={t('Nhánh')}
                            allLabel={t('Mọi nhánh')}
                            options={branches.map((branch) => ({ value: branch.branch, label: branch.branch }))}
                            onChange={(branch) => query({ branch })}
                        />
                    </div>
                    <span className="text-sm text-faint max-sm:hidden">{t(':count bản phát hành', { count: releases.meta.total })}</span>
                </CardHeader>
                {releases.data.length === 0 ? (
                    isFiltered ? (
                        <EmptyState title={t('Không có bản phát hành phù hợp')} description={t('Thử từ khóa hoặc bộ lọc khác.')} />
                    ) : (
                        <EmptyState title={t('Chưa có bản phát hành nào')} description={t('Bấm Đồng bộ ngay để lấy danh mục từ nguồn.')} />
                    )
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>{t('Version')}</Th>
                                <Th>{t('Nhánh')}</Th>
                                <Th>{t('Phát hành')}</Th>
                                <Th>{t('Bản vá bảo mật')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {releases.data.map((release) => (
                                <Tr key={release.version}>
                                    <Td className="font-mono font-medium">{release.version}</Td>
                                    <Td className="font-mono text-muted-foreground">{release.branch}</Td>
                                    <Td className="text-muted-foreground tabular-nums">{release.released_at ?? '—'}</Td>
                                    <Td>
                                        {release.is_security_release === true && <StatusDot status={{ label: t('Có'), tone: 'warning' }} />}
                                        {release.is_security_release === false && <span className="text-muted-foreground">—</span>}
                                        {release.is_security_release === null && <span className="text-sm text-muted-foreground">{t('Nguồn không ghi')}</span>}
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                )}
                <TablePagination meta={releases.meta} table={table} onChange={query} />
            </Card>
        </>
    );
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="mt-1">{children}</dd>
        </div>
    );
}

function ExternalLink({ href, children }: { href: string; children: ReactNode }) {
    return (
        <a href={href} target="_blank" rel="noreferrer noopener" className="font-medium text-link hover:underline hover:underline-offset-3">
            {children}
        </a>
    );
}
