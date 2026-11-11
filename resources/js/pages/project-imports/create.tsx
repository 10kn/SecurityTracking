import { Link, useForm } from '@inertiajs/react';
import { DownloadIcon, FileSpreadsheetIcon, TriangleAlertIcon, UploadIcon, XIcon } from 'lucide-react';
import { useRef, useState } from 'react';

import { index } from '@/actions/App/Http/Controllers/Admin/ProjectController';
import { store, template } from '@/actions/App/Http/Controllers/Admin/ProjectImportController';
import { create as createTeam } from '@/actions/App/Http/Controllers/Admin/TeamController';
import { Callout, CalloutText } from '@/components/admin/callout';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/admin/card';
import { Field, FormActions } from '@/components/admin/form-section';
import { PageHeader } from '@/components/admin/page-header';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Step 1: download the template for the teams to fill in. Step 2: upload the filled-in file, which opens its preview.
 */
export default function ProjectImportsCreate({ max_rows, max_kilobytes, has_teams }: { max_rows: number; max_kilobytes: number; has_teams: boolean }) {
    const t = useTranslation();
    const form = useForm<{ file: File | null }>({ file: null });

    return (
        <>
            <PageHeader
                title={t('Import project')}
                description={t('Các team điền template, admin tải file lên, xem trước rồi xác nhận để tạo project và service hàng loạt.')}
                breadcrumbs={[{ label: t('Projects'), href: index.url() }, { label: t('Import project') }]}
            />
            {!has_teams && (
                <Callout
                    className="mb-6"
                    icon={<TriangleAlertIcon />}
                    title={t('Chưa có team nào')}
                    action={
                        <Button variant="secondary" size="sm" asChild>
                            <Link href={createTeam.url()}>{t('Tạo team')}</Link>
                        </Button>
                    }
                >
                    <CalloutText>{t('Template chỉ cho chọn các team đã có, nên cần tạo team trước.')}</CalloutText>
                </Callout>
            )}
            <Card className="mb-6">
                <CardHeader>
                    <CardTitle>{t('1. Tải template')}</CardTitle>
                </CardHeader>
                <CardBody className="flex flex-wrap items-center justify-between gap-4">
                    <p className="max-w-2xl text-muted-foreground">
                        {t('Mỗi dòng là một service, tối đa :max dòng. Slug do hệ thống tự tạo từ tên. Sheet "Hướng dẫn" trong file giải thích từng cột.', { max: max_rows })}
                    </p>
                    <Button variant="secondary" asChild>
                        <a href={template.url()} download>
                            <DownloadIcon className="size-4" aria-hidden />
                            {t('Tải template (.xlsx)')}
                        </a>
                    </Button>
                </CardBody>
            </Card>
            <form
                className="rounded-lg border border-border bg-surface"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.submit(store());
                }}
                noValidate
            >
                <CardHeader>
                    <CardTitle>{t('2. Tải file đã điền lên')}</CardTitle>
                </CardHeader>
                <CardBody>
                    <Field error={form.errors.file} help={t('File .xlsx tối đa :size KB. Chưa có gì được tạo cho tới khi bạn xác nhận ở bước xem trước.', { size: max_kilobytes })}>
                        <FilePicker file={form.data.file} isInvalid={form.errors.file !== undefined} onChange={(file) => form.setData('file', file)} />
                    </Field>
                    <FormActions>
                        <Button type="submit" loading={form.processing} disabled={form.data.file === null}>
                            {form.progress ? t('Đang tải lên :percent%', { percent: form.progress.percentage ?? 0 }) : t('Tải lên và xem trước')}
                        </Button>
                        <Button variant="secondary" asChild>
                            <Link href={index.url()}>{t('Hủy')}</Link>
                        </Button>
                    </FormActions>
                </CardBody>
            </form>
        </>
    );
}

function FilePicker({ file, isInvalid, onChange }: { file: File | null; isInvalid: boolean; onChange: (file: File | null) => void }) {
    const t = useTranslation();
    const inputRef = useRef<HTMLInputElement>(null);
    const [isDragging, setIsDragging] = useState(false);

    if (file !== null) {
        return (
            <div className={cn('flex flex-wrap items-center gap-2 rounded-md border border-border px-3 py-2', isInvalid && 'border-danger')}>
                <FileSpreadsheetIcon className="size-4 text-faint" aria-hidden />
                <span className="font-medium">{file.name}</span>
                <span className="text-sm text-faint">{t(':size KB', { size: Math.ceil(file.size / 1024) })}</span>
                <Button type="button" variant="ghost" size="icon-sm" className="ml-auto" aria-label={t('Bỏ file')} onClick={() => onChange(null)}>
                    <XIcon className="size-4" aria-hidden />
                </Button>
            </div>
        );
    }

    return (
        <div
            onDragOver={(event) => {
                event.preventDefault();
                setIsDragging(true);
            }}
            onDragLeave={() => setIsDragging(false)}
            onDrop={(event) => {
                event.preventDefault();
                setIsDragging(false);
                onChange(event.dataTransfer.files[0] ?? null);
            }}
            className={cn(
                'flex flex-wrap items-center justify-center gap-2 rounded-lg border border-dashed border-border-strong bg-surface px-4 py-8 text-center text-muted-foreground',
                isDragging && 'border-primary bg-primary-soft',
            )}
        >
            <UploadIcon className="size-4 text-faint" aria-hidden />
            <span>{t('Kéo thả file .xlsx vào đây hoặc')}</span>
            <button type="button" className="font-medium text-link hover:underline hover:underline-offset-3" onClick={() => inputRef.current?.click()}>
                {t('chọn file')}
            </button>
            <input
                ref={inputRef}
                type="file"
                accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                className="sr-only"
                tabIndex={-1}
                onChange={(event) => {
                    onChange(event.target.files?.[0] ?? null);
                    event.target.value = '';
                }}
            />
        </div>
    );
}
