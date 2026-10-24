import type { InertiaFormProps } from '@inertiajs/react';

import { show as endoflifeProduct } from '@/actions/App/Http/Controllers/Admin/EndoflifeProductController';
import { Field, FormFields, FormSection } from '@/components/admin/form-section';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { getJson } from '@/lib/http';
import { useTranslation } from '@/lib/i18n';
import { slugify } from '@/lib/slug';
import type { Option } from '@/types';

/** Mirrors Technology::ENDOFLIFE_PRODUCT_PATTERN, e.g. "php", "nodejs", "amazon-linux". */
const PRODUCT_PATTERN = /^[a-z0-9][a-z0-9.+-]*$/;

export interface TechnologyFormData {
    endoflife_product: string;
    name: string;
    slug: string;
    release_source: string;
}

export function TechnologyFields({ form, releaseSources }: { form: InertiaFormProps<TechnologyFormData>; releaseSources: Option<string>[] }) {
    const t = useTranslation();

    /** Suggests the slug and, from endoflife.date, the display name (e.g. "Node.js" for "nodejs") without overwriting what was typed. */
    const suggestFromProduct = (product: string) => {
        if (!PRODUCT_PATTERN.test(product)) {
            return;
        }

        if (form.data.slug === '') {
            form.setData('slug', slugify(product));
        }

        if (form.data.name === '') {
            getJson<{ label: string | null }>(endoflifeProduct.url(product))
                .then((response) => response.label && form.setData((data) => (data.name === '' ? { ...data, name: response.label ?? '' } : data)))
                .catch(() => undefined);
        }
    };

    return (
        <FormSection title={t('Công nghệ')} description={t('Version và ngày hết hỗ trợ lấy từ endoflife.date, tự cập nhật mỗi giờ.')}>
            <FormFields columns={2}>
                <Field
                    label={t('Mã sản phẩm trên endoflife.date')}
                    htmlFor="endoflife_product"
                    required
                    error={form.errors.endoflife_product}
                    help={t('Ví dụ: python, go, mysql, postgresql, nginx, laravel, rails.')}
                    className="sm:col-span-2"
                >
                    <Input
                        id="endoflife_product"
                        placeholder="python"
                        className="font-mono text-[calc(var(--fs-sm)-0.5px)]"
                        value={form.data.endoflife_product}
                        onChange={(event) => form.setData('endoflife_product', event.target.value)}
                        onBlur={(event) => suggestFromProduct(event.target.value.trim())}
                        aria-invalid={form.errors.endoflife_product !== undefined}
                    />
                </Field>
                <Field label={t('Tên hiển thị')} htmlFor="name" required error={form.errors.name}>
                    <Input id="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} aria-invalid={form.errors.name !== undefined} />
                </Field>
                <Field label={t('Slug')} htmlFor="slug" required error={form.errors.slug}>
                    <Input
                        id="slug"
                        className="font-mono text-[calc(var(--fs-sm)-0.5px)]"
                        value={form.data.slug}
                        onChange={(event) => form.setData('slug', event.target.value)}
                        aria-invalid={form.errors.slug !== undefined}
                    />
                </Field>
                <Field label={t('Nguồn danh sách bản phát hành')} required error={form.errors.release_source} className="sm:col-span-2">
                    <RadioGroup value={form.data.release_source} onValueChange={(value) => form.setData('release_source', value)}>
                        {releaseSources.map((source) => (
                            <label key={source.value} className="flex cursor-pointer items-start gap-2.5">
                                <RadioGroupItem value={source.value} />
                                <span>
                                    <span className="block font-medium">{source.label}</span>
                                    {source.description && <span className="block text-sm text-muted-foreground">{source.description}</span>}
                                </span>
                            </label>
                        ))}
                    </RadioGroup>
                </Field>
            </FormFields>
        </FormSection>
    );
}
