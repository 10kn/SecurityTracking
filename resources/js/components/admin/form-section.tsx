import type { ReactNode } from 'react';

import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

/**
 * A form section of prototype v2: title and description on the left, fields on the right. Sections are separated by a
 * line; the first one has none.
 */
export function FormSection({ title, description, children, className }: { title: string; description?: ReactNode; children: ReactNode; className?: string }) {
    return (
        <div className={cn('grid grid-cols-[280px_minmax(0,1fr)] gap-8 border-t border-border py-7 first:border-t-0 first:pt-0 max-lg:grid-cols-1 max-lg:gap-4', className)}>
            <div>
                <h2 className="text-base font-semibold">{title}</h2>
                {description && <p className="mt-1 text-sm text-muted-foreground">{description}</p>}
            </div>
            <div className="min-w-0">{children}</div>
        </div>
    );
}

/** Fields in one column, or two from small screens up with `columns={2}`. */
export function FormFields({ columns = 1, className, children }: { columns?: 1 | 2; className?: string; children: ReactNode }) {
    return <div className={cn('grid gap-[18px]', columns === 2 && 'sm:grid-cols-2', className)}>{children}</div>;
}

/** A label, the control, then the error (red) or the help text below it. */
export function Field({
    label,
    htmlFor,
    required = false,
    error,
    help,
    className,
    children,
}: {
    label?: string;
    htmlFor?: string;
    required?: boolean;
    error?: string;
    help?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={cn('min-w-0', className)}>
            {label && (
                <Label htmlFor={htmlFor} className="mb-1.5">
                    {label}
                    {required && <span className="text-danger-text"> *</span>}
                </Label>
            )}
            {children}
            {error ? <p className="mt-1.5 text-sm text-danger-text">{error}</p> : help && <div className="mt-1.5 text-sm text-muted-foreground">{help}</div>}
        </div>
    );
}

/** The save and cancel buttons under a form. */
export function FormActions({ children }: { children: ReactNode }) {
    return <div className="flex flex-wrap items-center gap-2 border-t border-border pt-6">{children}</div>;
}
