import { router, useForm } from '@inertiajs/react';

import { show as showProject } from '@/actions/App/Http/Controllers/Admin/ProjectController';
import { destroy, index, update } from '@/actions/App/Http/Controllers/Admin/ServiceController';
import { Card, CardBody } from '@/components/admin/card';
import { ConfirmButton } from '@/components/admin/confirm-button';
import { FormActions, FormSection } from '@/components/admin/form-section';
import { PageHeader } from '@/components/admin/page-header';
import { newRuntimeRow, ServiceFields, serviceRequestData, type ServiceFormData, type ServiceFormOptions } from '@/components/admin/services/service-form';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';

interface Props extends ServiceFormOptions {
    service: {
        id: number;
        project_id: number;
        name: string;
        slug: string;
        kind: string;
        exposure: string;
        repository_path: string;
        project: { id: number; name: string; team: string };
        runtimes: { technology_id: number; raw_version: string }[];
        lockfiles: { path: string; uploaded_at: string }[];
    };
}

export default function ServicesEdit({ service, ...options }: Props) {
    const t = useTranslation();
    const form = useForm<ServiceFormData>({
        project_id: service.project_id,
        name: service.name,
        slug: service.slug,
        kind: service.kind,
        exposure: service.exposure,
        repository_path: service.repository_path,
        runtimes: service.runtimes.map((runtime) => newRuntimeRow(runtime.technology_id, runtime.raw_version)),
        lockfile_uploads: [],
        removed_lockfiles: [],
    });

    // Files cannot be sent with PUT in PHP, so the update is a POST that Laravel reads as PUT.
    form.transform((data) => ({ ...serviceRequestData(data), _method: 'put' }));

    return (
        <>
            <PageHeader
                title={service.name}
                description={t('Project :project · team :team', { project: service.project.name, team: service.project.team })}
                breadcrumbs={[{ label: t('Services'), href: index.url() }, { label: service.project.name, href: showProject.url(service.project.id) }, { label: service.name }]}
            />
            <form
                className="rounded-lg border border-border bg-surface"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(update.url(service.id), {
                        preserveScroll: true,
                        onSuccess: () => {
                            form.setDefaults({ ...form.data, lockfile_uploads: [], removed_lockfiles: [] });
                            form.reset('lockfile_uploads', 'removed_lockfiles');
                        },
                    });
                }}
                noValidate
            >
                <CardBody>
                    <ServiceFields form={form} options={options} isCreating={false} existingLockfiles={service.lockfiles} />
                    <FormActions>
                        <Button type="submit" loading={form.processing}>
                            {form.progress ? t('Đang tải lên :percent%', { percent: form.progress.percentage ?? 0 }) : t('Lưu thay đổi')}
                        </Button>
                        <Button type="button" variant="secondary" disabled={!form.isDirty} onClick={() => form.resetAndClearErrors()}>
                            {t('Hủy')}
                        </Button>
                    </FormActions>
                </CardBody>
            </form>
            <Card className="mt-6">
                <CardBody>
                    <FormSection title={t('Xóa service')} description={t('Xóa service cũng xóa version runtime, lockfile và finding của service.')}>
                        <ConfirmButton
                            title={t('Xóa service :service?', { service: service.name })}
                            description={t('Xóa service cũng xóa version runtime, lockfile và finding của service.')}
                            confirmLabel={t('Xóa service')}
                            onConfirm={() => router.delete(destroy.url(service.id))}
                        >
                            {t('Xóa service')}
                        </ConfirmButton>
                    </FormSection>
                </CardBody>
            </Card>
        </>
    );
}
