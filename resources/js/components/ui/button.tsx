import { cva, type VariantProps } from 'class-variance-authority';
import { Slot } from 'radix-ui';
import * as React from 'react';

import { cn } from '@/lib/utils';

/** Buttons of prototype v2: solid primary, bordered secondary, ghost, danger and danger-ghost. */
const buttonVariants = cva(
    "inline-flex shrink-0 items-center justify-center gap-1.5 rounded-md border border-transparent font-medium whitespace-nowrap transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-45 aria-busy:pointer-events-none aria-busy:opacity-70 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-[15px]",
    {
        variants: {
            variant: {
                default: 'bg-primary text-primary-foreground hover:bg-primary-hover',
                secondary: 'border-border bg-surface text-foreground hover:border-border-strong hover:bg-surface-2',
                ghost: 'bg-transparent text-muted-foreground hover:bg-surface-2 hover:text-foreground',
                danger: 'bg-danger text-white hover:opacity-90',
                'danger-ghost': 'bg-transparent text-danger-text hover:bg-danger-soft',
                link: 'h-auto px-0 text-link hover:underline hover:underline-offset-3',
            },
            size: {
                default: 'h-9 px-3.5 text-base',
                sm: 'h-[30px] px-[9px] text-sm',
                icon: 'size-9',
                'icon-sm': 'size-[30px]',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);

function Spinner() {
    return <span aria-hidden className="size-3 animate-spin rounded-full border-[1.5px] border-current border-r-transparent" />;
}

function Button({
    className,
    variant = 'default',
    size = 'default',
    asChild = false,
    loading = false,
    children,
    ...props
}: React.ComponentProps<'button'> &
    VariantProps<typeof buttonVariants> & {
        asChild?: boolean;
        /** Shows a spinner and ignores clicks while a request runs. */
        loading?: boolean;
    }) {
    const Comp = asChild ? Slot.Root : 'button';

    return (
        <Comp
            data-slot="button"
            data-variant={variant}
            data-size={size}
            aria-busy={loading || undefined}
            className={cn(buttonVariants({ variant, size, className }))}
            {...props}
        >
            {asChild ? (
                children
            ) : (
                <>
                    {loading && <Spinner />}
                    {children}
                </>
            )}
        </Comp>
    );
}

export { Button, buttonVariants };
