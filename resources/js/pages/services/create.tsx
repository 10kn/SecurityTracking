import { Link, useForm } from '@inertiajs/react';

import { index, store } from '@/actions/App/Http/Controllers/Admin/ServiceController';
import { CardBody } from '@/components/admin/card';
import { FormActions } from '@/components/admin/form-section';
import { PageHeader } from '@/components/admin/page-header';
import { ServiceFields, serviceRequestData, type ServiceFormData, type ServiceFormOptions } from '@/components/admin/services/service-form';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';

export default function ServicesCreate({ defaultProjectId, ...options }: ServiceFormOptions & { defaultProjectId: number | null }) {
    const t = useTranslation();
    const form = useForm<ServiceFormData>({
        project_id: defaultProjectId,
        name: '',
        slug: '',
        kind: options.kinds[0]?.value ?? '',
        exposure: options.exposures.find((exposure) => exposure.value === 'internal')?.value ?? options.exposures[0]?.value ?? '',
        repository_path: '/',
        repository: null,
        repository_branch: '',
        runtimes: [],
        lockfile_uploads: [],
        removed_lockfiles: [],
    });

    form.transform(serviceRequestData);

    return (
        <>
            <PageHeader title={t('Thêm service')} breadcrumbs={[{ label: t('Services'), href: index.url() }, { label: t('Thêm service') }]} />
            <form
                className="rounded-lg border border-border bg-surface"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.submit(store());
                }}
                noValidate
            >
                <CardBody>
                    <ServiceFields form={form} options={options} isCreating />
                    <FormActions>
                        <Button type="submit" loading={form.processing}>
                            {form.progress ? t('Đang tải lên :percent%', { percent: form.progress.percentage ?? 0 }) : t('Tạo service')}
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
