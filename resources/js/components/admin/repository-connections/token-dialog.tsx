import { useForm } from '@inertiajs/react';
import { useState } from 'react';

import { TokenField } from '@/components/admin/repository-connections/token-field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { useTranslation } from '@/lib/i18n';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Replaces a connection's token. `action` saves it; the server asks GitHub about the new token first and keeps the old
 * one when GitHub turns the new one down.
 */
export function TokenDialog({ action }: { action: RouteDefinition<'put'> }) {
    const t = useTranslation();
    const [isOpen, setIsOpen] = useState(false);
    const form = useForm({ token: '' });

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
                <Button type="button" variant="secondary">
                    {t('Thay token')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        // The dialog sits inside a form; React bubbles portal events, so without this the outer form also submits.
                        event.stopPropagation();
                        form.submit(action, { preserveScroll: true, onSuccess: () => setIsOpen(false) });
                    }}
                    noValidate
                >
                    <DialogHeader>
                        <DialogTitle>{t('Thay token')}</DialogTitle>
                        <DialogDescription>{t('Token cũ vẫn được dùng cho tới khi GitHub nhận token mới.')}</DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        <TokenField value={form.data.token} error={form.errors.token} onChange={(token) => form.setData('token', token)} autoFocus />
                    </DialogBody>
                    <DialogFooter>
                        <Button type="button" variant="secondary" onClick={() => setIsOpen(false)}>
                            {t('Hủy')}
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            {t('Kiểm tra & lưu')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
