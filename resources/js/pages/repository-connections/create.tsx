import { Link, useForm } from '@inertiajs/react';

import { index, store } from '@/actions/App/Http/Controllers/Admin/RepositoryConnectionController';
import { CardBody } from '@/components/admin/card';
import { Field, FormActions, FormFields, FormSection } from '@/components/admin/form-section';
import { PageHeader } from '@/components/admin/page-header';
import { TokenField } from '@/components/admin/repository-connections/token-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/lib/i18n';

export default function RepositoryConnectionsCreate() {
    const t = useTranslation();
    const form = useForm({ name: '', token: '' });

    return (
        <>
            <PageHeader
                title={t('Thêm kết nối')}
                description={t('Đọc repository của khách qua account dev được mời vào repository đó.')}
                breadcrumbs={[{ label: t('Kết nối repository'), href: index.url() }, { label: t('Thêm kết nối') }]}
            />
            <form
                className="rounded-lg border border-border bg-surface"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.submit(store());
                }}
                noValidate
            >
                <CardBody>
                    <FormSection
                        title={t('Kết nối')}
                        description={t('Mỗi khách một account và một kết nối: token đọc được mọi repository account đó được mời vào. Hệ thống chỉ đọc, không ghi gì lên GitHub.')}
                    >
                        <FormFields>
                            <Field label={t('Tên kết nối')} htmlFor="name" required error={form.errors.name} help={t('Ví dụ tên khách hàng, hiện cạnh repository trong form service.')}>
                                <Input
                                    id="name"
                                    placeholder={t('Khách ABC')}
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                    aria-invalid={form.errors.name !== undefined}
                                    autoFocus
                                />
                            </Field>
                            <TokenField value={form.data.token} error={form.errors.token} onChange={(token) => form.setData('token', token)} />
                        </FormFields>
                    </FormSection>
                    <FormActions>
                        <Button type="submit" loading={form.processing}>
                            {t('Kiểm tra & lưu')}
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
