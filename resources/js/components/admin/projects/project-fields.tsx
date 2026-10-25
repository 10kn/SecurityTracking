import type { InertiaFormProps } from '@inertiajs/react';

import { Combobox } from '@/components/admin/combobox';
import { Field, FormFields, FormSection } from '@/components/admin/form-section';
import { Input } from '@/components/ui/input';
import { useSlugFromName } from '@/hooks/use-slug-from-name';
import { useTranslation } from '@/lib/i18n';
import type { Option } from '@/types';

export interface ProjectFormData {
    team_id: number | null;
    name: string;
    slug: string;
    repository_url: string;
}

export function ProjectFields({ form, teams, isCreating }: { form: InertiaFormProps<ProjectFormData>; teams: Option<number>[]; isCreating: boolean }) {
    const t = useTranslation();
    const slug = useSlugFromName(isCreating, (value) => form.setData('slug', value));

    return (
        <FormSection title={t('Thông tin chung')} description={t('Finding của mọi service trong project được gửi tới team sở hữu.')}>
            <FormFields columns={2}>
                <Field label={t('Team sở hữu')} htmlFor="team_id" required error={form.errors.team_id} className="sm:col-span-2">
                    <Combobox
                        id="team_id"
                        value={form.data.team_id}
                        options={teams}
                        onChange={(teamId) => form.setData('team_id', teamId)}
                        placeholder={t('Chọn team')}
                        searchPlaceholder={t('Tìm team')}
                        invalid={form.errors.team_id !== undefined}
                    />
                </Field>
                <Field label={t('Tên project')} htmlFor="name" required error={form.errors.name}>
                    <Input
                        id="name"
                        value={form.data.name}
                        onChange={(event) => {
                            form.setData('name', event.target.value);
                            slug.onNameChange(event.target.value);
                        }}
                        aria-invalid={form.errors.name !== undefined}
                    />
                </Field>
                <Field label={t('Slug')} htmlFor="slug" required error={form.errors.slug}>
                    <Input
                        id="slug"
                        className="font-mono text-[calc(var(--fs-sm)-0.5px)]"
                        value={form.data.slug}
                        onChange={(event) => slug.onSlugChange(event.target.value)}
                        aria-invalid={form.errors.slug !== undefined}
                    />
                </Field>
                <Field label={t('Repository URL')} htmlFor="repository_url" error={form.errors.repository_url} className="sm:col-span-2">
                    <Input
                        id="repository_url"
                        className="font-mono text-[calc(var(--fs-sm)-0.5px)]"
                        placeholder="https://git.example.com/team/project"
                        value={form.data.repository_url}
                        onChange={(event) => form.setData('repository_url', event.target.value)}
                        aria-invalid={form.errors.repository_url !== undefined}
                    />
                </Field>
            </FormFields>
        </FormSection>
    );
}
