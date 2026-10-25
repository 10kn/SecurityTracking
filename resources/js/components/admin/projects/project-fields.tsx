import type { InertiaFormProps } from '@inertiajs/react';

import { Combobox } from '@/components/admin/combobox';
import { Field, FormFields, FormSection } from '@/components/admin/form-section';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { useSlugFromName } from '@/hooks/use-slug-from-name';
import { useTranslation } from '@/lib/i18n';
import type { Option } from '@/types';

export interface ProjectFormData {
    team_id: number | null;
    name: string;
    slug: string;
    repository_url: string;
    repository_branch: string;
    syncs_lockfiles: boolean;
}

export function ProjectFields({
    form,
    teams,
    isCreating,
    gitHubConfigured,
}: {
    form: InertiaFormProps<ProjectFormData>;
    teams: Option<number>[];
    isCreating: boolean;
    gitHubConfigured: boolean;
}) {
    const t = useTranslation();
    const slug = useSlugFromName(isCreating, (value) => form.setData('slug', value));

    return (
        <>
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
                            placeholder="https://github.com/org/project"
                            value={form.data.repository_url}
                            onChange={(event) => form.setData('repository_url', event.target.value)}
                            aria-invalid={form.errors.repository_url !== undefined}
                        />
                    </Field>
                </FormFields>
            </FormSection>
            <FormSection
                title={t('Lockfile từ GitHub')}
                description={t('Mỗi ngày hệ thống đọc composer.lock, Gemfile.lock và package-lock.json trong thư mục của từng service, tại commit mới nhất của branch. GitHub App chỉ có quyền đọc.')}
            >
                <FormFields>
                    <Field
                        error={form.errors.syncs_lockfiles}
                        help={gitHubConfigured ? t('Bật lên thì lockfile do GitHub quản lý, không tải lên tay được.') : t('Server chưa cấu hình GitHub App (PSM_GITHUB_* trong .env).')}
                    >
                        <label className="flex items-start gap-2.5 text-base">
                            <Checkbox
                                className="mt-[3px]"
                                checked={form.data.syncs_lockfiles}
                                disabled={!gitHubConfigured && !form.data.syncs_lockfiles}
                                onCheckedChange={(checked) => form.setData('syncs_lockfiles', checked === true)}
                                aria-invalid={form.errors.syncs_lockfiles !== undefined}
                            />
                            {t('Tự lấy lockfile từ GitHub mỗi ngày')}
                        </label>
                    </Field>
                    <Field label={t('Branch')} htmlFor="repository_branch" error={form.errors.repository_branch} help={t('Để trống để dùng branch mặc định của repository.')}>
                        <Input
                            id="repository_branch"
                            className="font-mono text-[calc(var(--fs-sm)-0.5px)]"
                            placeholder="main"
                            value={form.data.repository_branch}
                            onChange={(event) => form.setData('repository_branch', event.target.value)}
                            aria-invalid={form.errors.repository_branch !== undefined}
                        />
                    </Field>
                </FormFields>
            </FormSection>
        </>
    );
}
