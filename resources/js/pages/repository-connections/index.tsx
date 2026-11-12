import { Link } from '@inertiajs/react';

import { create, edit } from '@/actions/App/Http/Controllers/Admin/RepositoryConnectionController';
import { Card } from '@/components/admin/card';
import { CellTitle, Table, Td, Th, Tr } from '@/components/admin/data-table';
import { PageHeader } from '@/components/admin/page-header';
import type { ConnectionSummary } from '@/components/admin/repository-connections/connection-summary';
import { StatusDot } from '@/components/admin/status-dot';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';

interface Props {
    gitHubApp: { is_configured: boolean; repositories_count: number; services_count: number };
    connections: (ConnectionSummary & { repositories_count: number; services_count: number })[];
}

export default function RepositoryConnectionsIndex({ gitHubApp, connections }: Props) {
    const t = useTranslation();

    return (
        <>
            <PageHeader
                title={t('Kết nối repository')}
                description={t('Cách hệ thống đọc lockfile từ GitHub: GitHub App của công ty, hoặc token của account dev được khách mời vào repository.')}
                actions={
                    <Button asChild>
                        <Link href={create.url()}>{t('Thêm kết nối')}</Link>
                    </Button>
                }
            />
            <Card>
                <Table>
                    <thead>
                        <tr>
                            <Th>{t('Kết nối')}</Th>
                            <Th align="right">{t('Repository')}</Th>
                            <Th align="right">{t('Services')}</Th>
                            <Th>{t('Hết hạn')}</Th>
                            <Th>{t('Trạng thái')}</Th>
                            <Th />
                        </tr>
                    </thead>
                    <tbody>
                        <Tr>
                            <Td>
                                <CellTitle title={t('GitHub App')} sub={t('Cấu hình trong .env (PSM_GITHUB_*)')} />
                            </Td>
                            <Td align="right">{gitHubApp.repositories_count}</Td>
                            <Td align="right">{gitHubApp.services_count}</Td>
                            <Td className="text-faint">{t('Không hết hạn')}</Td>
                            <Td>
                                <StatusDot status={gitHubApp.is_configured ? { label: t('Hoạt động'), tone: 'success' } : { label: t('Chưa cấu hình'), tone: 'neutral' }} />
                            </Td>
                            <Td />
                        </Tr>
                        {connections.map((connection) => (
                            <Tr key={connection.id} href={edit.url(connection.id)}>
                                <Td>
                                    <CellTitle title={connection.name} sub={connection.account_login ?? connection.masked_token} href={edit.url(connection.id)} />
                                </Td>
                                <Td align="right">{connection.repositories_count}</Td>
                                <Td align="right">{connection.services_count}</Td>
                                <Td className={connection.expires_at === null ? 'text-faint' : undefined}>{connection.expires_at ?? t('Không hết hạn')}</Td>
                                <Td>
                                    <StatusDot status={connection.status} />
                                </Td>
                                <Td align="right">
                                    <Button variant="ghost" size="sm" asChild>
                                        <Link href={edit.url(connection.id)}>{t('Sửa')}</Link>
                                    </Button>
                                </Td>
                            </Tr>
                        ))}
                    </tbody>
                </Table>
            </Card>
            {connections.length === 0 && <p className="mt-3 text-sm text-muted-foreground">{t('Chưa có kết nối nào. Thêm một kết nối khi repository nằm ở GitHub của khách mà GitHub App không được cài.')}</p>}
        </>
    );
}
