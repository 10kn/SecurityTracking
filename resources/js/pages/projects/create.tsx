import { Link, useForm } from '@inertiajs/react';

import { index, store } from '@/actions/App/Http/Controllers/Admin/ProjectController';
import { CardBody } from '@/components/admin/card';
import { FormActions } from '@/components/admin/form-section';
import { PageHeader } from '@/components/admin/page-header';
import { ProjectFields, type ProjectFormData } from '@/components/admin/projects/project-fields';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import type { Option } from '@/types';

export default function ProjectsCreate({ teams, defaultTeamId, gitHubConfigured }: { teams: Option<number>[]; defaultTeamId: number | null; gitHubConfigured: boolean }) {
    const t = useTranslation();
    const form = useForm<ProjectFormData>({ team_id: defaultTeamId, name: '', slug: '', repository_url: '', repository_branch: '', syncs_lockfiles: false });

    return (
        <>
            <PageHeader title={t('Tạo project')} breadcrumbs={[{ label: t('Projects'), href: index.url() }, { label: t('Tạo project') }]} />
            <form
                className="rounded-lg border border-border bg-surface"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.submit(store());
                }}
                noValidate
            >
                <CardBody>
                    <ProjectFields form={form} teams={teams} isCreating gitHubConfigured={gitHubConfigured} />
                    <FormActions>
                        <Button type="submit" loading={form.processing}>
                            {t('Tạo project')}
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
