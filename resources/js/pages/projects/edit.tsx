import { Link, router, useForm } from '@inertiajs/react';

import { destroy, index, show, update } from '@/actions/App/Http/Controllers/Admin/ProjectController';
import { Card, CardBody } from '@/components/admin/card';
import { ConfirmButton } from '@/components/admin/confirm-button';
import { FormActions, FormSection } from '@/components/admin/form-section';
import { PageHeader } from '@/components/admin/page-header';
import { ProjectFields, type ProjectFormData } from '@/components/admin/projects/project-fields';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import type { Option } from '@/types';

interface Props {
    project: { id: number; team_id: number; name: string; slug: string; repository_url: string | null };
    teams: Option<number>[];
}

export default function ProjectsEdit({ project, teams }: Props) {
    const t = useTranslation();
    const form = useForm<ProjectFormData>({
        team_id: project.team_id,
        name: project.name,
        slug: project.slug,
        repository_url: project.repository_url ?? '',
    });

    return (
        <>
            <PageHeader
                title={t('Sửa :project', { project: project.name })}
                breadcrumbs={[{ label: t('Projects'), href: index.url() }, { label: project.name, href: show.url(project.id) }, { label: t('Sửa') }]}
            />
            <form
                className="rounded-lg border border-border bg-surface"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.submit(update(project.id));
                }}
                noValidate
            >
                <CardBody>
                    <ProjectFields form={form} teams={teams} isCreating={false} />
                    <FormActions>
                        <Button type="submit" loading={form.processing}>
                            {t('Lưu thay đổi')}
                        </Button>
                        <Button variant="secondary" asChild>
                            <Link href={show.url(project.id)}>{t('Hủy')}</Link>
                        </Button>
                    </FormActions>
                </CardBody>
            </form>
            <Card className="mt-6">
                <CardBody>
                    <FormSection title={t('Xóa project')} description={t('Xóa project cũng xóa mọi service, version runtime và lockfile của project.')}>
                        <ConfirmButton
                            title={t('Xóa project :project?', { project: project.name })}
                            description={t('Xóa project cũng xóa mọi service, version runtime và lockfile của project.')}
                            confirmLabel={t('Xóa project')}
                            onConfirm={() => router.delete(destroy.url(project.id))}
                        >
                            {t('Xóa project')}
                        </ConfirmButton>
                    </FormSection>
                </CardBody>
            </Card>
        </>
    );
}
