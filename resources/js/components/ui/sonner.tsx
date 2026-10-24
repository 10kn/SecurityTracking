import { CircleAlertIcon, CircleCheckIcon } from 'lucide-react';
import { Toaster as Sonner, type ToasterProps } from 'sonner';

/** Toasts of prototype v2: top right, surface background, a green check or a red alert icon. */
function Toaster(props: ToasterProps) {
    return (
        <Sonner
            position="top-right"
            gap={8}
            icons={{
                success: <CircleCheckIcon className="size-4 text-success" />,
                error: <CircleAlertIcon className="size-4 text-danger" />,
            }}
            toastOptions={{
                unstyled: true,
                classNames: {
                    toast: 'flex w-[min(360px,calc(100vw-32px))] gap-2.5 rounded-lg border border-border bg-surface px-3.5 py-3 text-base text-foreground shadow-toast',
                    icon: 'mt-0.5',
                    title: 'font-medium',
                    description: 'mt-0.5 text-sm text-muted-foreground',
                },
            }}
            {...props}
        />
    );
}

export { Toaster };
