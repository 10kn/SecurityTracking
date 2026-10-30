import { Link } from '@inertiajs/react';
import { XIcon } from 'lucide-react';

import { show } from '@/actions/App/Http/Controllers/Admin/IncidentController';
import { Card, CardHeader } from '@/components/admin/card';
import { CellTitle, EmptyState, SortableTh, Table, TableFilter, TablePagination, TableSearch, Td, Th, Tr } from '@/components/admin/data-table';
import { FixStatus, type IncidentSummary } from '@/components/admin/incidents/fix-status';
import { PageHeader } from '@/components/admin/page-header';
import { SeverityBadge } from '@/components/admin/severity-badge';
import { StatusDot } from '@/components/admin/status-dot';
import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/native-select';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/lib/i18n';
import type { Option, Paginated, TableState } from '@/types';

interface Props {
    incidents: Paginated<IncidentSummary>;
    table: TableState & { technology: string | null; team: number | null; attention: number | null; unknown: number | null; dismissed: number | null };
    technologies: Option<string>[];
    teams: Option<number>[];
}

export default function IncidentsIndex({ incidents, table, technologies, teams }: Props) {
    const t = useTranslation();
    const query = useTableQuery(table, ['incidents']);
    const urlOf = (incident: IncidentSummary) => show.url(incident.id, { query: table.team === null ? undefined : { team: table.team } });
    const isFiltered = table.search !== '' || table.technology !== null || table.team !== null || table.attention !== null || table.unknown !== null || table.dismissed !== null;

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
                            value={table.unknown}
                            label={t('Đánh giá')}
                            allLabel={t('Mọi đánh giá')}
                            options={[{ value: 1, label: t('Đã đánh giá: Chưa rõ') }]}
                            onChange={(unknown) => query({ unknown })}
                        />
                        <TableFilter
                            value={table.dismissed}
                            label={t('Loại tin')}
                            allLabel={t('Tin bảo mật')}
                            options={[{ value: 1, label: t('Không phải tin bảo mật') }]}
                            onChange={(dismissed) => query({ dismissed })}
                        />
                        {isFiltered && (
                            <Button variant="ghost" size="sm" onClick={() => query({ search: null, technology: null, team: null, attention: null, unknown: null, dismissed: null })}>
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
                    <>
                        <div className="max-sm:hidden">
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
                                        <Tr key={incident.id} href={urlOf(incident)}>
                                            <Td className="whitespace-nowrap">
                                                <SeverityBadge severity={incident.severity} />
                                                {incident.is_known_exploited && <StatusDot status={{ label: 'KEV', tone: 'danger' }} className="mt-1 flex" title={t('CISA KEV đang liệt kê CVE của sự kiện')} />}
                                            </Td>
                                            <Td className="min-w-[200px] max-w-[420px]">
                                                <CellTitle
                                                    title={incident.lead ?? incident.title}
                                                    sub={`#${incident.id} · ${incident.technology.label}`}
                                                    href={urlOf(incident)}
                                                />
                                            </Td>
                                            <Td className="text-sm whitespace-nowrap text-muted-foreground tabular-nums">{incident.first_published ?? '—'}</Td>
                                            <Td align="right">{incident.notices_count}</Td>
                                            <Td align="right">
                                                <DeliveryCell incident={incident} />
                                            </Td>
                                            <Td className="max-w-[260px]">
                                                <FixStatus incident={incident} />
                                            </Td>
                                            <Td>
                                                <AttentionCell incident={incident} />
                                            </Td>
                                        </Tr>
                                    ))}
                                </tbody>
                            </Table>
                        </div>
                        <IncidentCards incidents={incidents} table={table} urlOf={urlOf} onSort={(sort) => query({ sort })} />
                    </>
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

/**
 * The incidents below 640px, one card each, where the table would need sideways scrolling: what needs an admin and the
 * fix status first, then the details in smaller type. The whole card opens the incident. The sort headers are hidden,
 * so a select sorts instead.
 */
function IncidentCards({ incidents, table, urlOf, onSort }: { incidents: Props['incidents']; table: Props['table']; urlOf: (incident: IncidentSummary) => string; onSort: (sort: string) => void }) {
    const t = useTranslation();
    const sorts = [
        { value: '-first_published_at', label: t('Mới đăng trước') },
        { value: 'first_published_at', label: t('Cũ trước') },
        { value: 'severity', label: t('Mức cao trước') },
        { value: '-severity', label: t('Mức thấp trước') },
    ];

    return (
        <div className="sm:hidden">
            <div className="flex items-center justify-between gap-3 border-b border-border px-3.5 py-2">
                <span className="text-sm text-faint">{t(':count sự kiện', { count: incidents.meta.total })}</span>
                <NativeSelect aria-label={t('Sắp xếp')} value={table.sort} onChange={(event) => onSort(event.target.value)} className="h-[30px] w-auto pl-2.5 text-sm">
                    {sorts.map((sort) => (
                        <option key={sort.value} value={sort.value}>
                            {sort.label}
                        </option>
                    ))}
                </NativeSelect>
            </div>
            <ul className="divide-y divide-border">
                {incidents.data.map((incident) => (
                    <li key={incident.id}>
                        <Link href={urlOf(incident)} className="block px-3.5 py-3 hover:bg-surface-2 outline-none focus-visible:bg-surface-2 focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-inset">
                            <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <SeverityBadge severity={incident.severity} />
                                {incident.is_known_exploited && <StatusDot status={{ label: 'KEV', tone: 'danger' }} />}
                                {incident.has_delivery_failures && <StatusDot status={{ label: t('Gửi lỗi'), tone: 'danger' }} />}
                            </span>
                            <span className="mt-1.5 line-clamp-2 font-medium">{incident.lead ?? incident.title}</span>
                            {attentionOf(incident, t).map((item) => (
                                <span key={item} className="mt-1 block text-sm">
                                    {item}
                                </span>
                            ))}
                            <span className="mt-1.5 block min-w-0">
                                <FixStatus incident={incident} isCompact />
                            </span>
                            <span className="mt-1.5 block text-xs text-muted-foreground">
                                {[
                                    `#${incident.id}`,
                                    incident.technology.label,
                                    incident.first_published,
                                    t(':count tin', { count: incident.notices_count }),
                                    t('Đã gửi tới team: :count', { count: incident.teams_delivered_count }),
                                    incident.teams_told_count !== incident.teams_delivered_count ? t('Đã xếp gửi: :count', { count: incident.teams_told_count }) : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** What needs an admin: teams to assess first, then suggestions to merge, kept apart; a zero is left out. */
function attentionOf(incident: IncidentSummary, t: ReturnType<typeof useTranslation>): string[] {
    return [
        incident.teams_to_acknowledge_count > 0 && t('Cần đánh giá: :count', { count: incident.teams_to_acknowledge_count }),
        incident.pending_suggestions_count > 0 && t('Gợi ý gộp: :count', { count: incident.pending_suggestions_count }),
    ].filter((item): item is string => typeof item === 'string');
}

function AttentionCell({ incident }: { incident: IncidentSummary }) {
    const t = useTranslation();
    const items = attentionOf(incident, t);

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
