import { router } from '@inertiajs/react';
import { useState } from 'react';

import { destroy, update } from '@/actions/App/Http/Controllers/Admin/AdminWebhookController';
import { store as sendTestMessage } from '@/actions/App/Http/Controllers/Admin/AdminWebhookTestMessageController';
import { ConfirmButton } from '@/components/admin/confirm-button';
import { Field } from '@/components/admin/form-section';
import { WebhookDialog } from '@/components/admin/teams/webhook-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/lib/i18n';

export interface AdminWebhookData {
    masked_url: string | null;
    /** "settings" when set on the settings screen, "env" when only the .env sets it. */
    source: 'settings' | 'env' | null;
    changed: { by: string | null; at: string | null } | null;
}

/**
 * One of the admin's Google Chat spaces: the masked URL in use, a test message, and replacing it. One set on the screen
 * can be removed to use the .env one again.
 */
export function AdminWebhookField({ webhook, label, data, emptyText }: { webhook: 'admin' | 'news'; label: string; data: AdminWebhookData; emptyText: string }) {
    const t = useTranslation();
    const [isSendingTest, setIsSendingTest] = useState(false);
    const id = `${webhook}-webhook-url`;
    const source = data.source === 'env' ? t('Đang dùng webhook trong .env.') : data.source === 'settings' ? t('Đặt trên màn này') : null;

    return (
        <Field
            label={label}
            htmlFor={data.masked_url ? id : undefined}
            help={
                source && (
                    <>
                        {source}
                        {data.changed && <span> · {[data.changed.by, data.changed.at].filter(Boolean).join(', ')}</span>}
                    </>
                )
            }
        >
            {data.masked_url ? (
                <div className="flex gap-2 max-sm:flex-wrap">
                    <Input id={id} readOnly value={data.masked_url} className="flex-1 font-mono text-[calc(var(--fs-sm)-0.5px)] max-sm:basis-full" />
                    <Button
                        type="button"
                        variant="secondary"
                        loading={isSendingTest}
                        onClick={() =>
                            router.post(sendTestMessage.url(webhook), {}, {
                                preserveScroll: true,
                                preserveState: true,
                                onStart: () => setIsSendingTest(true),
                                onFinish: () => setIsSendingTest(false),
                            })
                        }
                    >
                        {t('Gửi thử')}
                    </Button>
                    <WebhookDialog action={update(webhook)} hasWebhook title={label} />
                    {data.source === 'settings' && (
                        <ConfirmButton
                            variant="ghost"
                            title={t('Dùng lại webhook trong .env?')}
                            description={t('Webhook đặt trên màn này sẽ bị xóa. Nếu .env không có webhook, kênh này sẽ không nhận tin.')}
                            confirmLabel={t('Dùng lại .env')}
                            onConfirm={() => router.delete(destroy.url(webhook), { preserveScroll: true, preserveState: true })}
                        >
                            {t('Dùng lại .env')}
                        </ConfirmButton>
                    )}
                </div>
            ) : (
                <div>
                    <p className="mb-3 text-muted-foreground">{emptyText}</p>
                    <WebhookDialog action={update(webhook)} hasWebhook={false} title={label} />
                </div>
            )}
        </Field>
    );
}
