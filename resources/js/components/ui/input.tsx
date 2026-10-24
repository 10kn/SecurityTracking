import * as React from 'react';

import { cn } from '@/lib/utils';

/** A 38px text input with the primary color as focus ring; `aria-invalid` turns the border red. */
const inputClassName =
    'h-[38px] w-full min-w-0 rounded-md border border-border-strong bg-surface px-3 text-base text-foreground outline-none placeholder:text-faint [&[readonly]]:bg-surface-2 [&[readonly]]:text-muted-foreground focus:border-primary focus:shadow-[0_0_0_3px_var(--focus)] disabled:cursor-not-allowed disabled:opacity-60 aria-invalid:border-danger';

function Input({ className, type, ...props }: React.ComponentProps<'input'>) {
    return <input type={type} data-slot="input" className={cn(inputClassName, className)} {...props} />;
}

export { Input, inputClassName };
