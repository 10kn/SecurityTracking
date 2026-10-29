import { XIcon } from 'lucide-react';

import { show } from '@/actions/App/Http/Controllers/Admin/IncidentController';
import { Card, CardHeader } from '@/components/admin/card';
import { CellTitle, EmptyState, SortableTh, Table, TableFilter, TablePagination, TableSearch, Td, Th, Tr } from '@/components/admin/data-table';
import { FixStatus, type IncidentSummary } from '@/components/admin/incidents/fix-status';
import { PageHeader } from '@/components/admin/page-header';
import { SeverityBadge } from '@/components/admin/severity-badge';
import { StatusDot } from '@/components/admin/status-dot';
import { Button } from '@/components/ui/button';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/lib/i18n';
import type { Option, Paginated, TableState } from '@/types';

interface Props {
    incidents: Paginated<IncidentSummary>;
    table: TableState & { technology: string | null; team: number | null; attention: number | null; dismissed: number | null };
    technologies: Option<string>[];
    teams: Option<number>[];
}

export default function IncidentsIndex({ incidents, table, technologies, teams }: Props) {
    const t = useTranslation();
    const query = useTableQuery(table, ['incidents']);
    const isFiltered = table.search !== '' || table.technology !== null || table.team !== null || table.attention !== null || table.dismissed !== null;

    return (
        <>
            <PageHeader
                title={t('Sự kiện bảo mật')}
                description={t('Tin bảo mật sớm của hãng, gom theo sự kiện. Xem gợi ý gộp, gộp hoặc tách tin, và đánh dấu đã đọc kèm mức ảnh hưởng cho từng team.')}
            />
            <Card>
                <CardHeader>
                    <div className="flex flex-1 flex-wrap items-center gap-2">
                        <TableSearch value={table.search} placeholder={t('Tìm sự kiện')} onSearch={(search) => query({ search })} />
                        <TableFilter value={table.technology} label={t('Công nghệ')} allLabel={t('Mọi công nghệ')} options={technologies} onChange={(technology) => query({ technology })} />
                        <TableFilter value={table.team} label={t('Team')} allLabel={t('Mọi team')} options={teams} onChange={(team) => query({ team })} />
                        <TableFilter
                            value={table.attention}
                            label={t('Cần xử lý')}
                            allLabel={t('Mọi sự kiện')}
                            options={[{ value: 1, label: t('Cần admin xử lý') }]}
                            onChange={(attention) => query({ attention })}
                        />
                        <TableFilter
                            value={table.dismissed}
                            label={t('Loại tin')}
                            allLabel={t('Tin bảo mật')}
                            options={[{ value: 1, label: t('Không phải tin bảo mật') }]}
                            onChange={(dismissed) => query({ dismissed })}
                        />
                        {isFiltered && (
                            <Button variant="ghost" size="sm" onClick={() => query({ search: null, technology: null, team: null, attention: null, dismissed: null })}>
                                {t('Bỏ lọc')}
                                <XIcon />
                            </Button>
                        )}
                    </div>
                    <span className="text-sm text-faint max-sm:hidden">{t(':count sự kiện', { count: incidents.meta.total })}</span>
                </CardHeader>
                {incidents.data.length === 0 ? (
                    isFiltered ? (
                        <EmptyState title={t('Không có sự kiện phù hợp')} description={t('Thử từ khóa hoặc bộ lọc khác.')} />
                    ) : (
                        <EmptyState title={t('Chưa có sự kiện nào')} description={t('Sự kiện xuất hiện khi hãng đăng tin bảo mật sớm.')} />
                    )
                ) : (
                    <Table className="min-w-[1000px]">
                        <thead>
                            <tr>
                                <SortableTh column="severity" sort={table.sort} onSort={(sort) => query({ sort })}>
                                    {t('Mức')}
                                </SortableTh>
                                <Th>{t('Sự kiện')}</Th>
                                <SortableTh column="first_published_at" sort={table.sort} onSort={(sort) => query({ sort })}>
                                    {t('Đăng lúc')}
                                </SortableTh>
                                <Th align="right">{t('Tin')}</Th>
                                <Th align="right">{t('Đã gửi tới team')}</Th>
                                <Th>{t('Bản vá')}</Th>
                                <Th>{t('Cần xử lý')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {incidents.data.map((incident) => (
                                <Tr key={incident.id} href={show.url(incident.id, { query: table.team === null ? undefined : { team: table.team } })}>
                                    <Td className="whitespace-nowrap">
                                        <SeverityBadge severity={incident.severity} />
                                        {incident.is_known_exploited && <StatusDot status={{ label: 'KEV', tone: 'danger' }} className="mt-1 flex" title={t('CISA KEV đang liệt kê CVE của sự kiện')} />}
                                    </Td>
                                    <Td className="max-w-[420px]">
                                        <CellTitle
                                            title={incident.lead ?? incident.title}
                                            sub={`#${incident.id} · ${incident.technology.label}`}
                                            href={show.url(incident.id, { query: table.team === null ? undefined : { team: table.team } })}
                                        />
                                    </Td>
                                    <Td className="text-sm whitespace-nowrap text-muted-foreground tabular-nums">{incident.first_published ?? '—'}</Td>
                                    <Td align="right">{incident.notices_count}</Td>
                                    <Td align="right">
                                        <DeliveryCell incident={incident} />
                                    </Td>
                                    <Td>
                                        <FixStatus incident={incident} />
                                    </Td>
                                    <Td>
                                        <AttentionCell incident={incident} />
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                )}
                <TablePagination meta={incidents.meta} table={table} onChange={query} />
            </Card>
        </>
    );
}

/**
 * The teams Google Chat accepted a message of the current thread for; the teams a card was queued for only when they
 * differ, and a mark when a message failed and was not sent again.
 */
function DeliveryCell({ incident }: { incident: IncidentSummary }) {
    const t = useTranslation();

    return (
        <>
            <span className="block">{incident.teams_delivered_count}</span>
            {incident.teams_told_count !== incident.teams_delivered_count && (
                <span className="block text-sm whitespace-nowrap text-faint">{t('Đã xếp gửi: :count', { count: incident.teams_told_count })}</span>
            )}
            {incident.has_delivery_failures && <StatusDot status={{ label: t('Gửi lỗi'), tone: 'danger' }} className="flex justify-end whitespace-nowrap" />}
        </>
    );
}

/** What needs an admin: teams to assess first, then suggestions to merge, kept apart; a zero is left out. */
function AttentionCell({ incident }: { incident: IncidentSummary }) {
    const t = useTranslation();
    const items = [
        incident.teams_to_acknowledge_count > 0 && t('Cần đánh giá: :count', { count: incident.teams_to_acknowledge_count }),
        incident.pending_suggestions_count > 0 && t('Gợi ý gộp: :count', { count: incident.pending_suggestions_count }),
    ].filter((item): item is string => typeof item === 'string');

    return items.length === 0 ? (
        <span className="text-sm text-muted-foreground">—</span>
    ) : (
        <>
            {items.map((item) => (
                <span key={item} className="block text-sm whitespace-nowrap">
                    {item}
                </span>
            ))}
        </>
    );
}
