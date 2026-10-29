import { Link } from '@inertiajs/react';

import { create, edit, show } from '@/actions/App/Http/Controllers/Admin/ProjectController';
import { Card, CardHeader } from '@/components/admin/card';
import { CellTitle, EmptyState, SortableTh, Table, TableFilter, TablePagination, TableSearch, Td, Th, Tr } from '@/components/admin/data-table';
import { PageHeader } from '@/components/admin/page-header';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/lib/i18n';
import type { Option, Paginated, TableState } from '@/types';

interface ProjectRow {
    id: number;
    name: string;
    slug: string;
    services_count: number;
    team: { id: number; name: string };
}

export default function ProjectsIndex({ projects, table, teams }: { projects: Paginated<ProjectRow>; table: TableState & { team: number | null }; teams: Option<number>[] }) {
    const t = useTranslation();
    const query = useTableQuery(table, ['projects']);
    const isFiltered = table.search !== '' || table.team !== null;

    return (
        <>
            <PageHeader
                title={t('Projects')}
                description={t('Finding của mọi service trong project được gửi tới team sở hữu.')}
                actions={
                    <Button asChild>
                        <Link href={create.url()}>{t('Tạo project')}</Link>
                    </Button>
                }
            />
            <Card>
                <CardHeader>
                    <div className="flex flex-1 flex-wrap items-center gap-2">
                        <TableSearch value={table.search} placeholder={t('Tìm project')} onSearch={(search) => query({ search })} />
                        <TableFilter value={table.team} label={t('Team')} allLabel={t('Mọi team')} options={teams} onChange={(team) => query({ team })} />
                    </div>
                    <span className="text-sm text-faint max-sm:hidden">{t(':count project', { count: projects.meta.total })}</span>
                </CardHeader>
                {projects.data.length === 0 ? (
                    isFiltered ? (
                        <EmptyState title={t('Không có project phù hợp')} description={t('Thử từ khóa hoặc bộ lọc khác.')} />
                    ) : (
                        <EmptyState
                            title={t('Chưa có project nào')}
                            description={t('Tạo project rồi thêm service để hệ thống bắt đầu theo dõi.')}
                            action={
                                <Button asChild>
                                    <Link href={create.url()}>{t('Tạo project')}</Link>
                                </Button>
                            }
                        />
                    )
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <SortableTh column="name" sort={table.sort} onSort={(sort) => query({ sort })}>
                                    {t('Project')}
                                </SortableTh>
                                <SortableTh column="team" sort={table.sort} onSort={(sort) => query({ sort })}>
                                    {t('Team')}
                                </SortableTh>
                                <SortableTh column="services_count" sort={table.sort} onSort={(sort) => query({ sort })} align="right">
                                    {t('Services')}
                                </SortableTh>
                                <Th />
                            </tr>
                        </thead>
                        <tbody>
                            {projects.data.map((project) => (
                                <Tr key={project.id} href={show.url(project.id)}>
                                    <Td>
                                        <CellTitle title={project.name} sub={project.slug} href={show.url(project.id)} />
                                    </Td>
                                    <Td>{project.team.name}</Td>
                                    <Td align="right">{project.services_count}</Td>
                                    <Td align="right">
                                        <Button variant="ghost" size="sm" asChild>
                                            <Link href={edit.url(project.id)}>{t('Sửa')}</Link>
                                        </Button>
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                )}
                <TablePagination meta={projects.meta} table={table} onChange={query} />
            </Card>
        </>
    );
}
