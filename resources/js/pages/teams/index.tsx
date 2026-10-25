import { Link } from '@inertiajs/react';

import { create, edit } from '@/actions/App/Http/Controllers/Admin/TeamController';
import { Card, CardHeader } from '@/components/admin/card';
import { CellTitle, EmptyState, SortableTh, Table, TablePagination, TableSearch, Td, Th, Tr } from '@/components/admin/data-table';
import { PageHeader } from '@/components/admin/page-header';
import { StatusDot } from '@/components/admin/status-dot';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Paginated, StatusDotData, TableState } from '@/types';

interface TeamRow {
    id: number;
    name: string;
    slug: string;
    projects_count: number;
    open_findings_count: number;
    google_chat: StatusDotData;
}

export default function TeamsIndex({ teams, table }: { teams: Paginated<TeamRow>; table: TableState }) {
    const t = useTranslation();
    const query = useTableQuery(table, ['teams']);

    return (
        <>
            <PageHeader
                title={t('Teams')}
                description={t('Finding được gửi tới team sở hữu project bị ảnh hưởng.')}
                actions={
                    <Button asChild>
                        <Link href={create.url()}>{t('Tạo team')}</Link>
                    </Button>
                }
            />
            <Card>
                <CardHeader>
                    <TableSearch value={table.search} placeholder={t('Tìm team')} onSearch={(search) => query({ search })} />
                    <span className="text-sm text-faint max-sm:hidden">{t(':count team', { count: teams.meta.total })}</span>
                </CardHeader>
                {teams.data.length === 0 ? (
                    table.search !== '' ? (
                        <EmptyState title={t('Không có team phù hợp')} description={t('Thử từ khóa khác.')} />
                    ) : (
                        <EmptyState
                            title={t('Chưa có team nào')}
                            description={t('Finding được gửi tới team sở hữu project bị ảnh hưởng.')}
                            action={
                                <Button asChild>
                                    <Link href={create.url()}>{t('Tạo team')}</Link>
                                </Button>
                            }
                        />
                    )
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <SortableTh column="name" sort={table.sort} onSort={(sort) => query({ sort })}>
                                    {t('Team')}
                                </SortableTh>
                                <SortableTh column="projects_count" sort={table.sort} onSort={(sort) => query({ sort })} align="right">
                                    {t('Projects')}
                                </SortableTh>
                                <SortableTh column="open_findings_count" sort={table.sort} onSort={(sort) => query({ sort })} align="right">
                                    {t('Finding mở')}
                                </SortableTh>
                                <Th>{t('Google Chat')}</Th>
                                <Th />
                            </tr>
                        </thead>
                        <tbody>
                            {teams.data.map((team) => (
                                <Tr key={team.id} href={edit.url(team.id)}>
                                    <Td>
                                        <CellTitle title={team.name} sub={team.slug} href={edit.url(team.id)} />
                                    </Td>
                                    <Td align="right">{team.projects_count}</Td>
                                    <Td align="right" className={cn(team.open_findings_count > 0 ? 'font-medium text-danger-text' : 'text-faint')}>
                                        {team.open_findings_count}
                                    </Td>
                                    <Td>
                                        <StatusDot status={team.google_chat} />
                                    </Td>
                                    <Td align="right">
                                        <Button variant="ghost" size="sm" asChild>
                                            <Link href={edit.url(team.id)}>{t('Sửa')}</Link>
                                        </Button>
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                )}
                <TablePagination meta={teams.meta} table={table} onChange={query} />
            </Card>
        </>
    );
}
