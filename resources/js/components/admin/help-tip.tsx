import { CircleHelpIcon } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * A "?" beside a label that explains it, shown on hover, keyboard focus or a click, so a tap opens it on a touch screen
 * too (a tooltip otherwise closes on click). `label` names what it explains for screen readers.
 */
export function HelpTip({ label, children, className }: { label: string; children: ReactNode; className?: string }) {
    const t = useTranslation();
    const [isOpen, setIsOpen] = useState(false);

    return (
        <Tooltip open={isOpen} onOpenChange={setIsOpen}>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    aria-label={t('Giải thích: :label', { label })}
                    onClick={(event) => {
                        event.preventDefault();
                        setIsOpen(true);
                    }}
                    className={cn(
                        'inline-flex size-4 shrink-0 items-center justify-center rounded-full align-[-3px] text-faint transition-colors outline-none hover:text-muted-foreground focus-visible:ring-[3px] focus-visible:ring-ring',
                        className,
                    )}
                >
                    <CircleHelpIcon className="size-3.5" aria-hidden />
                </button>
            </TooltipTrigger>
            <TooltipContent className="text-sm leading-snug font-normal whitespace-normal">{children}</TooltipContent>
        </Tooltip>
    );
}
