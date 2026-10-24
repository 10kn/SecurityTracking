import { Link, useForm } from '@inertiajs/react';

import { index, store } from '@/actions/App/Http/Controllers/Admin/TeamController';
import { CardBody } from '@/components/admin/card';
import { FormActions } from '@/components/admin/form-section';
import { PageHeader } from '@/components/admin/page-header';
import { TeamGeneralFields, type TeamFormData } from '@/components/admin/teams/team-general-fields';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';

export default function TeamsCreate() {
    const t = useTranslation();
    const form = useForm<TeamFormData>({ name: '', slug: '' });

    return (
        <>
            <PageHeader title={t('Tạo team')} description={t('Webhook Google Chat thêm sau khi tạo.')} breadcrumbs={[{ label: t('Teams'), href: index.url() }, { label: t('Tạo team') }]} />
            <form
                className="rounded-lg border border-border bg-surface"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.submit(store());
                }}
                noValidate
            >
                <CardBody>
                    <TeamGeneralFields form={form} isCreating />
                    <FormActions>
                        <Button type="submit" loading={form.processing}>
                            {t('Tạo team')}
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
