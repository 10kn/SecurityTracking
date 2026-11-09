import { ExternalLinkIcon } from 'lucide-react';

import { Card, CardHeader } from '@/components/admin/card';
import { CellTitle, EmptyState, SortableTh, Table, TableFilter, TablePagination, TableSearch, Td, Th, Tr } from '@/components/admin/data-table';
import { PageHeader } from '@/components/admin/page-header';
import { SeverityBadge } from '@/components/admin/severity-badge';
import { StatusDot } from '@/components/admin/status-dot';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/lib/i18n';
import type { Option, Paginated, TableState } from '@/types';

interface OsAdvisoryRow {
    id: number;
    /** The distribution's id, e.g. "USN-8999-1". */
    reference: string;
    title: string;
    severity: { value: string; label: string };
    os: { value: string; label: string };
    /** The releases it fixes; one past its end of life is marked, e.g. Amazon Linux 2. */
    releases: { name: string; is_eol: boolean; eol_from: string | null }[];
    packages: string[];
    more_packages: number;
    cves: string[];
    more_cves: number;
    known_exploited: string[] | null;
    published: string | null;
    url: string | null;
    /** Sent to the security news space. */
    is_sent: boolean;
}

interface Props {
    advisories: Paginated<OsAdvisoryRow>;
    table: TableState & { os: string | null; release: string | null; severity: string | null; kev: number | null };
    systems: Option<string>[];
    /** The releases of the operating system picked; none until one is. */
    releases: Option<string>[];
    severities: Option<string>[];
    /** Each feed's last successful poll. */
    sources: { label: string; synced_at: string | null; is_stale: boolean }[];
}

export default function OsAdvisoriesIndex({ advisories, table, systems, releases, severities, sources }: Props) {
    const t = useTranslation();
    const query = useTableQuery(table, ['advisories', 'releases']);
    const isFiltered = table.search !== '' || table.os !== null || table.release !== null || table.severity !== null || table.kev !== null;

    return (
        <>
            <PageHeader
                title={t('Tin hệ điều hành')}
                description={t('Tin bảo mật của Amazon Linux, Ubuntu và Debian, chưa áp dụng vào project. Tin ở mức "Gửi ngay khi mức độ" hoặc có CVE trong CISA KEV được gửi vào space tin bảo mật.')}
            />
            <div className="mb-4 flex flex-wrap gap-x-5 gap-y-1.5 text-sm">
                {sources.map((source) => (
                    <StatusDot
                        key={source.label}
                        status={{
                            label: source.synced_at ? t(':source · cập nhật :time', { source: source.label, time: source.synced_at }) : t(':source · chưa cập nhật', { source: source.label }),
                            tone: source.is_stale ? 'warning' : 'success',
                        }}
                    />
                ))}
            </div>
            <Card>
                <CardHeader>
                    <div className="flex flex-1 flex-wrap items-center gap-2">
                        <TableSearch value={table.search} placeholder={t('Tìm mã tin, CVE hoặc gói')} onSearch={(search) => query({ search })} />
                        <TableFilter value={table.os} label={t('Hệ điều hành')} allLabel={t('Mọi hệ điều hành')} options={systems} onChange={(os) => query({ os, release: null })} />
                        {table.os !== null && <TableFilter value={table.release} label={t('Phiên bản')} allLabel={t('Mọi phiên bản')} options={releases} onChange={(release) => query({ release })} />}
                        <TableFilter value={table.severity} label={t('Mức')} allLabel={t('Mọi mức')} options={severities} onChange={(severity) => query({ severity })} />
                        <TableFilter value={table.kev} label={t('CISA KEV')} allLabel={t('Mọi tin')} options={[{ value: 1, label: t('Có CVE trong CISA KEV') }]} onChange={(kev) => query({ kev })} />
                    </div>
                    <span className="text-sm text-faint max-sm:hidden">{t(':count tin', { count: advisories.meta.total })}</span>
                </CardHeader>
                {advisories.data.length === 0 ? (
                    isFiltered ? (
                        <EmptyState title={t('Không có tin phù hợp')} description={t('Thử từ khóa hoặc bộ lọc khác.')} />
                    ) : (
                        <EmptyState title={t('Chưa có tin bảo mật hệ điều hành')} description={t('Tin xuất hiện sau lần chạy psm:watch-os-advisories đầu tiên (30 phút một lần).')} />
                    )
                ) : (
                    <Table className="min-w-[960px]">
                        <thead>
                            <tr>
                                <SortableTh column="severity" sort={table.sort} onSort={(sort) => query({ sort })}>
                                    {t('Mức')}
                                </SortableTh>
                                <SortableTh column="title" sort={table.sort} onSort={(sort) => query({ sort })}>
                                    {t('Tin')}
                                </SortableTh>
                                <Th>{t('Phiên bản')}</Th>
                                <Th>{t('Gói · CVE')}</Th>
                                <SortableTh column="published_at" sort={table.sort} onSort={(sort) => query({ sort })}>
                                    {t('Ngày đăng')}
                                </SortableTh>
                                <Th>{t('Google Chat')}</Th>
                                <Th />
                            </tr>
                        </thead>
                        <tbody>
                            {advisories.data.map((advisory) => (
                                <Tr key={advisory.id}>
                                    <Td>
                                        <SeverityBadge severity={advisory.severity} />
                                    </Td>
                                    <Td>
                                        <CellTitle title={advisory.title} sub={`${advisory.reference} · ${advisory.os.label}`} />
                                        {advisory.known_exploited && <StatusDot status={{ label: t('CISA KEV: :cves', { cves: advisory.known_exploited.join(', ') }), tone: 'danger' }} className="mt-1" />}
                                    </Td>
                                    <Td className="text-sm">
                                        {advisory.releases.length === 0
                                            ? '—'
                                            : advisory.releases.map((release) => (
                                                  <span key={release.name} className="mr-1.5 inline-block whitespace-nowrap tabular-nums">
                                                      {release.name}
                                                      {release.is_eol && (
                                                          <span className="ml-1 text-warning-text" title={release.eol_from ? t('Hết hỗ trợ từ :date', { date: release.eol_from }) : undefined}>
                                                              {t('(hết hỗ trợ)')}
                                                          </span>
                                                      )}
                                                  </span>
                                              ))}
                                    </Td>
                                    <Td className="max-w-[280px] text-sm">
                                        <span className="block truncate">
                                            {advisory.packages.length === 0 ? '—' : advisory.packages.join(', ')}
                                            {advisory.more_packages > 0 && <span className="text-faint"> {t('+:count', { count: advisory.more_packages })}</span>}
                                        </span>
                                        <span className="block truncate font-mono text-xs text-muted-foreground">
                                            {advisory.cves.length === 0 ? t('Chưa có CVE') : advisory.cves.join(', ')}
                                            {advisory.more_cves > 0 && <span className="text-faint"> {t('+:count', { count: advisory.more_cves })}</span>}
                                        </span>
                                    </Td>
                                    <Td className="text-sm whitespace-nowrap text-muted-foreground tabular-nums">{advisory.published ?? '—'}</Td>
                                    <Td>{advisory.is_sent ? <StatusDot status={{ label: t('Đã gửi'), tone: 'neutral' }} /> : <span className="text-sm text-faint">—</span>}</Td>
                                    <Td align="right">
                                        {advisory.url && (
                                            <a
                                                href={advisory.url}
                                                target="_blank"
                                                rel="noreferrer noopener"
                                                className="inline-flex items-center gap-1 text-sm font-medium whitespace-nowrap text-link hover:underline hover:underline-offset-3"
                                            >
                                                {t('Mở nguồn tin')}
                                                <ExternalLinkIcon className="size-3.5" aria-hidden />
                                            </a>
                                        )}
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                )}
                <TablePagination meta={advisories.meta} table={table} onChange={query} />
            </Card>
        </>
    );
}
