import { router, useForm } from '@inertiajs/react';

import { destroy, index, update } from '@/actions/App/Http/Controllers/Admin/TechnologyController';
import { Card, CardBody } from '@/components/admin/card';
import { ConfirmButton } from '@/components/admin/confirm-button';
import { FormActions, FormSection } from '@/components/admin/form-section';
import { PageHeader } from '@/components/admin/page-header';
import { TechnologyFields, type TechnologyFormData } from '@/components/admin/technologies/technology-fields';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import type { Option } from '@/types';

interface Props {
    technology: TechnologyFormData & { id: number; is_used: boolean };
    releaseSources: Option<string>[];
}

export default function TechnologiesEdit({ technology, releaseSources }: Props) {
    const t = useTranslation();
    const form = useForm<TechnologyFormData>({
        endoflife_product: technology.endoflife_product,
        name: technology.name,
        slug: technology.slug,
        release_source: technology.release_source,
    });

    return (
        <>
            <PageHeader title={technology.name} breadcrumbs={[{ label: t('Công nghệ'), href: index.url() }, { label: technology.name }]} />
            <form
                className="rounded-lg border border-border bg-surface"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.submit(update(technology.id), { preserveScroll: true, onSuccess: () => form.setDefaults() });
                }}
                noValidate
            >
                <CardBody>
                    <TechnologyFields form={form} releaseSources={releaseSources} />
                    <FormActions>
                        <Button type="submit" loading={form.processing}>
                            {t('Lưu thay đổi')}
                        </Button>
                        <Button type="button" variant="secondary" disabled={!form.isDirty} onClick={() => form.resetAndClearErrors()}>
                            {t('Hủy')}
                        </Button>
                    </FormActions>
                </CardBody>
            </form>
            <Card className="mt-6">
                <CardBody>
                    <FormSection title={t('Xóa công nghệ')} description={t('Chỉ xóa được khi không còn service nào dùng.')}>
                        <ConfirmButton
                            title={t('Xóa :technology?', { technology: technology.name })}
                            description={t('Danh mục version của công nghệ này cũng bị xóa.')}
                            confirmLabel={t('Xóa công nghệ')}
                            disabled={technology.is_used}
                            onConfirm={() => router.delete(destroy.url(technology.id))}
                        >
                            {t('Xóa công nghệ')}
                        </ConfirmButton>
                        {technology.is_used && <p className="mt-1.5 text-sm text-muted-foreground">{t('Còn service đang dùng công nghệ này.')}</p>}
                    </FormSection>
                </CardBody>
            </Card>
        </>
    );
}
