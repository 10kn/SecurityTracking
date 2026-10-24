import { ChevronDownIcon } from 'lucide-react';
import * as React from 'react';

import { inputClassName } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/** The browser's own select, styled as an input. Enough for short fixed lists such as the service kind. */
function NativeSelect({ className, children, ...props }: React.ComponentProps<'select'>) {
    return (
        <div className="relative">
            <select data-slot="native-select" className={cn(inputClassName, 'appearance-none pr-8', className)} {...props}>
                {children}
            </select>
            <ChevronDownIcon className="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-faint" aria-hidden />
        </div>
    );
}

export { NativeSelect };
