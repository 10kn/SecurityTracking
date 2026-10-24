import type { InertiaFormProps } from '@inertiajs/react';

import { Field, FormFields, FormSection } from '@/components/admin/form-section';
import { Input } from '@/components/ui/input';
import { useSlugFromName } from '@/hooks/use-slug-from-name';
import { useTranslation } from '@/lib/i18n';

export interface TeamFormData {
    name: string;
    slug: string;
}

export function TeamGeneralFields({ form, isCreating }: { form: InertiaFormProps<TeamFormData>; isCreating: boolean }) {
    const t = useTranslation();
    const slug = useSlugFromName(isCreating, (value) => form.setData('slug', value));

    return (
        <FormSection title={t('Thông tin chung')} description={t('Slug dùng trong URL. Đổi slug làm hỏng link cũ trong tin Chat.')}>
            <FormFields columns={2}>
                <Field label={t('Tên team')} htmlFor="name" required error={form.errors.name}>
                    <Input
                        id="name"
                        placeholder="Payments"
                        value={form.data.name}
                        onChange={(event) => {
                            form.setData('name', event.target.value);
                            slug.onNameChange(event.target.value);
                        }}
                        aria-invalid={form.errors.name !== undefined}
                        autoFocus={isCreating}
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
            </FormFields>
        </FormSection>
    );
}
