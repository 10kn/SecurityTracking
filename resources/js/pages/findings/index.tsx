import { router } from '@inertiajs/react';
import { CheckIcon, XIcon } from 'lucide-react';
import { Fragment, useEffect, useState } from 'react';

import { store as acknowledge } from '@/actions/App/Http/Controllers/Admin/FindingAcknowledgementController';
import { edit as editService } from '@/actions/App/Http/Controllers/Admin/ServiceController';
import { Card, CardHeader } from '@/components/admin/card';
import { CellTitle, EmptyState, SortableTh, Table, TableFilter, TablePagination, TableSearch, Td, Th, Tr } from '@/components/admin/data-table';
import { LockfileName } from '@/components/admin/lockfile-logo';
import { PageHeader } from '@/components/admin/page-header';
import { SeverityBadge } from '@/components/admin/severity-badge';
import { StatusDot } from '@/components/admin/status-dot';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Option, Paginated, TableState } from '@/types';

interface FindingRow {
    id: number;
    version: number | null;
    severity: { value: string; label: string };
    title: string;
    rule: string;
    /** Where the affected package is, relative to the service directory, e.g. "frontend/package-lock.json". */
    lockfiles: string[];
    service: { id: number; name: string; project: string };
    team: string;
    first_seen: string | null;
    deadline: string | null;
    is_overdue: boolean;
    known_exploited: string[] | null;
    acknowledged: { by: string | null; at: string } | null;
}

interface Props {
    findings: Paginated<FindingRow>;
    table: TableState & { team: number | null; project: number | null; service: number | null; status: string | null; priority: number | null; ids: string | null };
    teams: Option<number>[];
    /** The projects of the team picked, or every project. */
    projects: Option<number>[];
    /** The services of the project picked; none until one is. */
    services: Option<number>[];
    statuses: Option<string>[];
}

export default function FindingsIndex({ findings, table, teams, projects, services, statuses }: Props) {
    const t = useTranslation();
    const query = useTableQuery(table, ['findings', 'projects', 'services']);
    const [selected, setSelected] = useState<number[]>([]);
    const [isSaving, setIsSaving] = useState(false);
    const isFiltered = table.search !== '' || table.team !== null || table.project !== null || table.service !== null || table.status !== null || table.priority !== null || table.ids !== null;
    const unread = findings.data.filter((finding) => finding.acknowledged === null);
    const isAllSelected = unread.length > 0 && unread.every((finding) => selected.includes(finding.id));

    // Only what the page shows can be marked: a new filter or page drops the rest of the selection.
    useEffect(() => setSelected((ids) => ids.filter((id) => findings.data.some((finding) => finding.id === id && finding.acknowledged === null))), [findings.data]);

    // Each finding goes with the version shown, so one that rose in severity or opened again meanwhile is not marked.
    const markAsRead = (ids: number[]) =>
        router.post(acknowledge.url(), { findings: findings.data.filter((finding) => ids.includes(finding.id)).map((finding) => ({ id: finding.id, version: finding.version })) }, {
            preserveScroll: true,
            onStart: () => setIsSaving(true),
            onFinish: () => setIsSaving(false),
            onSuccess: () => setSelected([]),
        });

    return (
        <>
            <PageHeader
                title={t('Findings')}
                description={t('Finding đang mở. Đánh dấu đã đọc để dừng nhắc hằng ngày; finding vẫn mở tới khi service được nâng cấp và được nhắc lại một lần khi quá hạn.')}
                actions={
                    selected.length > 0 && (
                        <Button loading={isSaving} onClick={() => markAsRead(selected)}>
                            <CheckIcon />
                            {t('Đánh dấu đã đọc (:count)', { count: selected.length })}
                        </Button>
                    )
                }
            />
            <Card>
                <CardHeader>
                    <div className="flex flex-1 flex-wrap items-center gap-2">
                        <TableSearch value={table.search} placeholder={t('Tìm finding')} onSearch={(search) => query({ search })} />
                        <TableFilter value={table.team} label={t('Team')} allLabel={t('Mọi team')} options={teams} onChange={(team) => query({ team, project: null, service: null })} />
                        <TableFilter value={table.project} label={t('Project')} allLabel={t('Mọi project')} options={projects} onChange={(project) => query({ project, service: null })} />
                        {table.project !== null && <TableFilter value={table.service} label={t('Service')} allLabel={t('Mọi service')} options={services} onChange={(service) => query({ service })} />}
                        <TableFilter value={table.status} label={t('Trạng thái')} allLabel={t('Mọi trạng thái')} options={statuses} onChange={(status) => query({ status })} />
                        <TableFilter
                            value={table.priority}
                            label={t('Ưu tiên')}
                            allLabel={t('Mọi mức ưu tiên')}
                            options={[{ value: 1, label: t('Finding ưu tiên') }]}
                            onChange={(priority) => query({ priority })}
                        />
                        {table.ids !== null && (
                            <Button variant="secondary" size="sm" onClick={() => query({ ids: null })}>
                                {t('Chỉ một cảnh báo')}
                                <XIcon />
                            </Button>
                        )}
                    </div>
                    <span className="text-sm text-faint max-sm:hidden">{t(':count finding', { count: findings.meta.total })}</span>
                </CardHeader>
                {findings.data.length === 0 ? (
                    isFiltered ? (
                        <EmptyState title={t('Không có finding phù hợp')} description={t('Thử từ khóa hoặc bộ lọc khác.')} />
                    ) : (
                        <EmptyState title={t('Không có finding nào đang mở')} />
                    )
                ) : (
                    <Table className="min-w-[960px]">
                        <thead>
                            <tr>
                                <Th className="w-10 pr-0">
                                    <Checkbox
                                        aria-label={t('Chọn mọi finding chưa đọc trên trang')}
                                        checked={isAllSelected}
                                        disabled={unread.length === 0}
                                        onCheckedChange={(checked) => setSelected(checked === true ? unread.map((finding) => finding.id) : [])}
                                    />
                                </Th>
                                <SortableTh column="severity" sort={table.sort} onSort={(sort) => query({ sort })}>
                                    {t('Mức')}
                                </SortableTh>
                                <SortableTh column="title" sort={table.sort} onSort={(sort) => query({ sort })}>
                                    {t('Finding')}
                                </SortableTh>
                                <Th>{t('Service')}</Th>
                                <SortableTh column="first_seen_at" sort={table.sort} onSort={(sort) => query({ sort })}>
                                    {t('Mở từ')}
                                </SortableTh>
                                <SortableTh column="sla_due_at" sort={table.sort} onSort={(sort) => query({ sort })}>
                                    {t('Hạn xử lý')}
                                </SortableTh>
                                <Th>{t('Trạng thái')}</Th>
                                <Th />
                            </tr>
                        </thead>
                        <tbody>
                            {findings.data.map((finding) => (
                                <Tr key={finding.id}>
                                    <Td className="pr-0">
                                        {finding.acknowledged === null && (
                                            <Checkbox
                                                aria-label={t('Chọn :finding', { finding: finding.title })}
                                                checked={selected.includes(finding.id)}
                                                onCheckedChange={(checked) =>
                                                    setSelected((ids) => (checked === true ? [...ids, finding.id] : ids.filter((id) => id !== finding.id)))
                                                }
                                            />
                                        )}
                                    </Td>
                                    <Td>
                                        <SeverityBadge severity={finding.severity} />
                                    </Td>
                                    <Td>
                                        <CellTitle
                                            title={finding.title}
                                            sub={
                                                <>
                                                    {finding.rule}
                                                    {finding.lockfiles.map((path) => (
                                                        <Fragment key={path}>
                                                            {' · '}
                                                            <LockfileName path={path} className="font-mono" />
                                                        </Fragment>
                                                    ))}
                                                </>
                                            }
                                        />
                                        {finding.known_exploited && <StatusDot status={{ label: t('CISA KEV: :cves', { cves: finding.known_exploited.join(', ') }), tone: 'danger' }} className="mt-1" />}
                                    </Td>
                                    <Td>
                                        <CellTitle title={finding.service.name} sub={`${finding.service.project} · ${finding.team}`} href={editService.url(finding.service.id)} />
                                    </Td>
                                    <Td className="text-sm whitespace-nowrap text-muted-foreground tabular-nums">{finding.first_seen ?? '—'}</Td>
                                    <Td className={cn('text-sm whitespace-nowrap tabular-nums', finding.is_overdue ? 'font-medium text-danger-text' : 'text-muted-foreground')}>
                                        {finding.deadline ? (finding.is_overdue ? t('Quá hạn :date', { date: finding.deadline }) : finding.deadline) : '—'}
                                    </Td>
                                    <Td>
                                        {finding.acknowledged ? (
                                            <>
                                                <StatusDot status={{ label: t('Đã đọc'), tone: 'neutral' }} />
                                                <span className="mt-0.5 block text-sm text-muted-foreground">
                                                    {finding.acknowledged.by ? t(':user · :time', { user: finding.acknowledged.by, time: finding.acknowledged.at }) : finding.acknowledged.at}
                                                </span>
                                            </>
                                        ) : (
                                            <StatusDot status={{ label: t('Chưa đọc'), tone: 'warning' }} />
                                        )}
                                    </Td>
                                    <Td align="right">
                                        {finding.acknowledged === null && (
                                            <Button variant="secondary" size="sm" disabled={isSaving} onClick={() => markAsRead([finding.id])}>
                                                {t('Đã đọc')}
                                            </Button>
                                        )}
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                )}
                <TablePagination meta={findings.meta} table={table} onChange={query} />
            </Card>
        </>
    );
}
