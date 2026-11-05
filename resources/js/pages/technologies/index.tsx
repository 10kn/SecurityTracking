import { router } from '@inertiajs/react';
import { RefreshCwIcon } from 'lucide-react';

import { store as syncCatalog } from '@/actions/App/Http/Controllers/Admin/TechnologyCatalogSyncController';
import { show } from '@/actions/App/Http/Controllers/Admin/TechnologyController';
import { Card } from '@/components/admin/card';
import { CellTitle, Table, Td, Th, Tr } from '@/components/admin/data-table';
import { PageHeader } from '@/components/admin/page-header';
import { StatusDot } from '@/components/admin/status-dot';
import { TechnologyLogo } from '@/components/admin/technology-logo';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import type { StatusDotData } from '@/types';

interface TechnologyRow {
    value: string;
    name: string;
    release_source: string;
    branches_count: number;
    runtimes_count: number;
    catalog: StatusDotData;
    last_synced: string | null;
    last_error: string | null;
}

export default function TechnologiesIndex({ technologies }: { technologies: TechnologyRow[] }) {
    const t = useTranslation();

    return (
        <>
            <PageHeader title={t('Công nghệ')} description={t('Các công nghệ hệ thống hỗ trợ khi nhập version cho service. Danh mục version tự cập nhật mỗi giờ.')} />
            <Card>
                <Table className="min-w-[760px]">
                    <thead>
                        <tr>
                            <Th>{t('Công nghệ')}</Th>
                            <Th align="right">{t('Nhánh')}</Th>
                            <Th align="right">{t('Đang dùng')}</Th>
                            <Th>{t('Danh mục version')}</Th>
                            <Th />
                        </tr>
                    </thead>
                    <tbody>
                        {technologies.map((technology) => (
                            <Tr key={technology.value} href={show.url(technology.value)}>
                                <Td>
                                    <CellTitle
                                        icon={<TechnologyLogo technology={technology.value} className="mr-2 align-[-0.2em]" />}
                                        title={technology.name}
                                        sub={t('endoflife.date và :source', { source: technology.release_source })}
                                        href={show.url(technology.value)}
                                    />
                                </Td>
                                <Td align="right">{technology.branches_count}</Td>
                                <Td align="right">{technology.runtimes_count}</Td>
                                <Td className="max-w-[260px]">
                                    <StatusDot status={technology.catalog} className="items-baseline whitespace-normal" />
                                    {technology.last_synced && <span className="mt-0.5 block text-sm text-muted-foreground">{technology.last_synced}</span>}
                                    {technology.last_error && <span className="mt-0.5 block max-w-[320px] text-sm text-danger-text">{technology.last_error}</span>}
                                </Td>
                                <Td align="right" className="whitespace-nowrap">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => router.post(syncCatalog.url(technology.value), {}, { preserveScroll: true, only: ['technologies'] })}
                                    >
                                        <RefreshCwIcon />
                                        {t('Đồng bộ ngay')}
                                    </Button>
                                </Td>
                            </Tr>
                        ))}
                    </tbody>
                </Table>
            </Card>
        </>
    );
}
