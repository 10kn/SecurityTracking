import { Link, router } from '@inertiajs/react';
import { CircleAlertIcon } from 'lucide-react';
import { useState } from 'react';

import { index } from '@/actions/App/Http/Controllers/Admin/ProjectController';
import { confirm, create } from '@/actions/App/Http/Controllers/Admin/ProjectImportController';
import { Callout, CalloutText } from '@/components/admin/callout';
import { Card, CardHeader, CardTitle } from '@/components/admin/card';
import { ConfirmButton } from '@/components/admin/confirm-button';
import { CellTitle, EmptyState, Table, Td, Th, Tr } from '@/components/admin/data-table';
import { PageHeader } from '@/components/admin/page-header';
import { StatusDot } from '@/components/admin/status-dot';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import type { StatusDotData } from '@/types';

interface ImportRow {
    row: number;
    status: StatusDotData;
    team: string;
    project: string;
    project_slug: string | null;
    service: string;
    service_slug: string | null;
    details: string[];
    errors: string[];
}

interface ImportSummary {
    new_projects: number;
    services: number;
    skipped: number;
    invalid: number;
}

/**
 * The preview of an uploaded file: what each row creates or why it cannot. Nothing is created until the admin confirms,
 * and a file with an error is fixed in Excel and uploaded again.
 */
export default function ProjectImportsShow({ import: importId, summary, can_import, rows }: { import: string; summary: ImportSummary; can_import: boolean; rows: ImportRow[] }) {
    const t = useTranslation();
    const [showsOnlyInvalid, setShowsOnlyInvalid] = useState(false);
    const [isImporting, setIsImporting] = useState(false);
    const shownRows = showsOnlyInvalid ? rows.filter((row) => row.errors.length > 0) : rows;

    return (
        <>
            <PageHeader
                title={t('Xem trước import')}
                breadcrumbs={[{ label: t('Projects'), href: index.url() }, { label: t('Import project'), href: create.url() }, { label: t('Xem trước') }]}
                actions={
                    <>
                        <Button variant="secondary" asChild>
                            <Link href={create.url()}>{t('Tải file khác')}</Link>
                        </Button>
                        <ConfirmButton
                            variant="default"
                            disabled={!can_import || isImporting}
                            title={t('Tạo :count service?', { count: summary.services })}
                            description={t('Hệ thống tạo :projects project mới và :services service, rồi đánh giá các service mới. Muốn bỏ thì phải xóa từng project hoặc service.', {
                                projects: summary.new_projects,
                                services: summary.services,
                            })}
                            confirmLabel={t('Tạo')}
                            onConfirm={() => router.post(confirm.url(importId), {}, { onStart: () => setIsImporting(true), onFinish: () => setIsImporting(false) })}
                        >
                            {t('Tạo :count service', { count: summary.services })}
                        </ConfirmButton>
                    </>
                }
            />
            {summary.invalid > 0 && (
                <Callout
                    className="mb-6"
                    tone="danger"
                    icon={<CircleAlertIcon />}
                    title={t(':count dòng lỗi', { count: summary.invalid })}
                    action={
                        <Button variant="secondary" size="sm" onClick={() => setShowsOnlyInvalid(!showsOnlyInvalid)}>
                            {showsOnlyInvalid ? t('Hiện mọi dòng') : t('Chỉ hiện dòng lỗi')}
                        </Button>
                    }
                >
                    <CalloutText>{t('Sửa các dòng này trong file Excel rồi tải lên lại. Chưa có gì được tạo.')}</CalloutText>
                </Callout>
            )}
            <dl className="mb-6 grid grid-cols-4 gap-4 max-md:grid-cols-2">
                <SummaryItem label={t('Project mới')} value={summary.new_projects} />
                <SummaryItem label={t('Service sẽ tạo')} value={summary.services} />
                <SummaryItem label={t('Bỏ qua vì đã có')} value={summary.skipped} />
                <SummaryItem label={t('Dòng lỗi')} value={summary.invalid} />
            </dl>
            <Card>
                <CardHeader>
                    <CardTitle>
                        {t('Các dòng trong file')} <span className="ml-1 text-sm font-normal text-faint">{rows.length}</span>
                    </CardTitle>
                </CardHeader>
                {rows.length === 0 ? (
                    <EmptyState title={t('File chưa có dòng nào')} description={t('Điền ở sheet "Services", mỗi dòng một service, rồi tải lên lại.')} />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th align="right">{t('Dòng')}</Th>
                                <Th>{t('Project')}</Th>
                                <Th>{t('Service')}</Th>
                                <Th>{t('Kết quả')}</Th>
                                <Th>{t('Chi tiết')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {shownRows.map((row) => (
                                <Tr key={row.row}>
                                    <Td align="right" className="text-muted-foreground tabular-nums">
                                        {row.row}
                                    </Td>
                                    <Td className="min-w-52">
                                        <CellTitle title={row.project || '—'} sub={[row.team, row.project_slug].filter(Boolean).join(' · ')} />
                                    </Td>
                                    <Td className="min-w-40">
                                        <CellTitle title={row.service || '—'} sub={row.service_slug ?? undefined} />
                                    </Td>
                                    <Td className="whitespace-nowrap">
                                        <StatusDot status={row.status} />
                                    </Td>
                                    <Td>
                                        <RowDetails row={row} />
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>
        </>
    );
}

function SummaryItem({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-lg border border-border bg-surface px-4 py-3">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="mt-1 text-xl font-semibold tabular-nums">{value}</dd>
        </div>
    );
}

function RowDetails({ row }: { row: ImportRow }) {
    if (row.errors.length > 0) {
        return (
            <ul className="grid gap-1 text-sm text-danger-text">
                {row.errors.map((error) => (
                    <li key={error}>{error}</li>
                ))}
            </ul>
        );
    }

    return <span className="text-sm">{row.details.join(' · ')}</span>;
}
