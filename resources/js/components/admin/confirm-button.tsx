import { useState, type ReactNode } from 'react';

import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';

/** A danger-ghost button that asks before running `onConfirm`, e.g. deleting a record; `variant` and `size` draw it otherwise, e.g. for a merge. */
export function ConfirmButton({
    children,
    title,
    description,
    confirmLabel,
    onConfirm,
    disabled = false,
    variant = 'danger-ghost',
    size = 'default',
}: {
    children: ReactNode;
    title: string;
    description: ReactNode;
    confirmLabel: string;
    onConfirm: () => void;
    disabled?: boolean;
    variant?: 'danger-ghost' | 'secondary' | 'ghost' | 'default';
    size?: 'default' | 'sm';
}) {
    const t = useTranslation();
    const [isOpen, setIsOpen] = useState(false);

    return (
        <AlertDialog open={isOpen} onOpenChange={setIsOpen}>
            <AlertDialogTrigger asChild>
                <Button type="button" variant={variant} size={size} disabled={disabled}>
                    {children}
                </Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription>{description}</AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>{t('Hủy')}</AlertDialogCancel>
                    <AlertDialogAction
                        onClick={() => {
                            setIsOpen(false);
                            onConfirm();
                        }}
                    >
                        {confirmLabel}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
