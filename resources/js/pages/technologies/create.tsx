import { Link, useForm } from '@inertiajs/react';

import { index, store } from '@/actions/App/Http/Controllers/Admin/TechnologyController';
import { CardBody } from '@/components/admin/card';
import { FormActions } from '@/components/admin/form-section';
import { PageHeader } from '@/components/admin/page-header';
import { TechnologyFields, type TechnologyFormData } from '@/components/admin/technologies/technology-fields';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import type { Option } from '@/types';

export default function TechnologiesCreate({ releaseSources }: { releaseSources: Option<string>[] }) {
    const t = useTranslation();
    const form = useForm<TechnologyFormData>({ endoflife_product: '', name: '', slug: '', release_source: 'endoflife' });

    return (
        <>
            <PageHeader title={t('Thêm công nghệ')} breadcrumbs={[{ label: t('Công nghệ'), href: index.url() }, { label: t('Thêm công nghệ') }]} />
            <form
                className="rounded-lg border border-border bg-surface"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.submit(store());
                }}
                noValidate
            >
                <CardBody>
                    <TechnologyFields form={form} releaseSources={releaseSources} />
                    <FormActions>
                        <Button type="submit" loading={form.processing}>
                            {t('Thêm công nghệ')}
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
