import { useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Field } from '@/components/admin/form-section';
import { Button } from '@/components/ui/button';
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/lib/i18n';
import type { RouteDefinition } from '@/wayfinder';

export const GOOGLE_CHAT_WEBHOOK_PREFIX = 'https://chat.googleapis.com/v1/spaces/';

/**
 * Adds or replaces a webhook: a team's, or one of the admin's spaces on the settings screen. `action` saves it; the
 * server sends a test message to the new URL and saves it only if Google Chat accepts it.
 */
export function WebhookDialog({ action, hasWebhook, title }: { action: RouteDefinition<'put'>; hasWebhook: boolean; title?: string }) {
    const t = useTranslation();
    const [isOpen, setIsOpen] = useState(false);
    const form = useForm({ google_chat_webhook_url: '' });

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(open) => {
                setIsOpen(open);

                if (!open) {
                    form.resetAndClearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button type="button" variant={hasWebhook ? 'ghost' : 'secondary'}>
                    {hasWebhook ? t('Thay') : t('Thêm webhook')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        // The dialog sits inside a form; React bubbles portal events, so without this the team form also submits and cancels this visit.
                        event.stopPropagation();
                        form.submit(action, { preserveScroll: true, onSuccess: () => setIsOpen(false) });
                    }}
                    noValidate
                >
                    <DialogHeader>
                        <DialogTitle>{title ?? t('Webhook Google Chat')}</DialogTitle>
                        <DialogDescription>{t('Trong Google Chat: Space → Apps & integrations → Webhooks.')}</DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        <Field
                            label={t('Webhook URL')}
                            htmlFor="google_chat_webhook_url"
                            required
                            error={form.errors.google_chat_webhook_url}
                            help={t('Hệ thống gửi một tin thử trước khi lưu. Sau đó URL chỉ hiện một phần.')}
                        >
                            <Input
                                id="google_chat_webhook_url"
                                type="url"
                                className="font-mono text-[calc(var(--fs-sm)-0.5px)]"
                                placeholder={`${GOOGLE_CHAT_WEBHOOK_PREFIX}…`}
                                value={form.data.google_chat_webhook_url}
                                onChange={(event) => form.setData('google_chat_webhook_url', event.target.value)}
                                aria-invalid={form.errors.google_chat_webhook_url !== undefined}
                                autoFocus
                            />
                        </Field>
                    </DialogBody>
                    <DialogFooter>
                        <Button type="button" variant="secondary" onClick={() => setIsOpen(false)}>
                            {t('Hủy')}
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            {t('Gửi thử & lưu')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
