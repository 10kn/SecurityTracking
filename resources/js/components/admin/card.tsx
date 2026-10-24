import { cn } from '@/lib/utils';

/** A bordered surface with an optional header and footer (prototype v2 `.card`). */
export function Card({ className, ...props }: React.ComponentProps<'section'>) {
    return <section className={cn('rounded-lg border border-border bg-surface', className)} {...props} />;
}

export function CardHeader({ className, ...props }: React.ComponentProps<'header'>) {
    return <header className={cn('flex min-h-[52px] flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-3 max-sm:px-3.5', className)} {...props} />;
}

export function CardTitle({ className, ...props }: React.ComponentProps<'h2'>) {
    return <h2 className={cn('text-base font-semibold', className)} {...props} />;
}

export function CardBody({ className, ...props }: React.ComponentProps<'div'>) {
    return <div className={cn('p-5 max-sm:px-3.5', className)} {...props} />;
}

export function CardFooter({ className, ...props }: React.ComponentProps<'footer'>) {
    return (
        <footer
            className={cn('flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-3 text-sm text-muted-foreground max-sm:px-3.5', className)}
            {...props}
        />
    );
}
