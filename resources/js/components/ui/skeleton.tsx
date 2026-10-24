import { cn } from '@/lib/utils';

function Skeleton({ className, ...props }: React.ComponentProps<'span'>) {
    return <span data-slot="skeleton" className={cn('block h-2.5 animate-pulse rounded-sm bg-surface-2', className)} {...props} />;
}

export { Skeleton };
