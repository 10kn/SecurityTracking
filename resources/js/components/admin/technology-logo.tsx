import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * The official logos in each technology's brand color, so a runtime is recognized at a glance. Paths and colors come
 * from Simple Icons 16.36.0 (https://simpleicons.org, slugs php, nodedotjs and ruby), which takes them from the vendors:
 * the PHP logo from php.net (CC BY-SA 4.0), the Node.js logo from nodejs.org/en/about/branding, the Ruby logo from
 * ruby-lang.org (CC BY-SA 2.5). The PHP viewBox drops the empty space above and below the oval, so it reads at text
 * size. Drawn inline, so no request and no icon package. Keys are the values of App\Enums\Technology.
 */
const LOGOS: Record<string, ReactNode> = {
    php: (
        <svg viewBox="0 5.4 24 13.2">
            <path fill="#777BB4" d="M7.01 10.207h-.944l-.515 2.648h.838c.556 0 .97-.105 1.242-.314.272-.21.455-.559.55-1.049.092-.47.05-.802-.124-.995-.175-.193-.523-.29-1.047-.29zM12 5.688C5.373 5.688 0 8.514 0 12s5.373 6.313 12 6.313S24 15.486 24 12c0-3.486-5.373-6.312-12-6.312zm-3.26 7.451c-.261.25-.575.438-.917.551-.336.108-.765.164-1.285.164H5.357l-.327 1.681H3.652l1.23-6.326h2.65c.797 0 1.378.209 1.744.628.366.418.476 1.002.33 1.752a2.836 2.836 0 0 1-.305.847c-.143.255-.33.49-.561.703zm4.024.715l.543-2.799c.063-.318.039-.536-.068-.651-.107-.116-.336-.174-.687-.174H11.46l-.704 3.625H9.388l1.23-6.327h1.367l-.327 1.682h1.218c.767 0 1.295.134 1.586.401s.378.7.263 1.299l-.572 2.944h-1.389zm7.597-2.265a2.782 2.782 0 0 1-.305.847c-.143.255-.33.49-.561.703a2.44 2.44 0 0 1-.917.551c-.336.108-.765.164-1.286.164h-1.18l-.327 1.682h-1.378l1.23-6.326h2.649c.797 0 1.378.209 1.744.628.366.417.477 1.001.331 1.751zM17.766 10.207h-.943l-.516 2.648h.838c.557 0 .971-.105 1.242-.314.272-.21.455-.559.551-1.049.092-.47.049-.802-.125-.995s-.524-.29-1.047-.29z" />
        </svg>
    ),
    nodejs: (
        <svg viewBox="0 0 24 24">
            <path fill="#5FA04E" d="M11.998,24c-0.321,0-0.641-0.084-0.922-0.247l-2.936-1.737c-0.438-0.245-0.224-0.332-0.08-0.383 c0.585-0.203,0.703-0.25,1.328-0.604c0.065-0.037,0.151-0.023,0.218,0.017l2.256,1.339c0.082,0.045,0.197,0.045,0.272,0l8.795-5.076 c0.082-0.047,0.134-0.141,0.134-0.238V6.921c0-0.099-0.053-0.192-0.137-0.242l-8.791-5.072c-0.081-0.047-0.189-0.047-0.271,0 L3.075,6.68C2.99,6.729,2.936,6.825,2.936,6.921v10.15c0,0.097,0.054,0.189,0.139,0.235l2.409,1.392 c1.307,0.654,2.108-0.116,2.108-0.89V7.787c0-0.142,0.114-0.253,0.256-0.253h1.115c0.139,0,0.255,0.112,0.255,0.253v10.021 c0,1.745-0.95,2.745-2.604,2.745c-0.508,0-0.909,0-2.026-0.551L2.28,18.675c-0.57-0.329-0.922-0.945-0.922-1.604V6.921 c0-0.659,0.353-1.275,0.922-1.603l8.795-5.082c0.557-0.315,1.296-0.315,1.848,0l8.794,5.082c0.57,0.329,0.924,0.944,0.924,1.603 v10.15c0,0.659-0.354,1.273-0.924,1.604l-8.794,5.078C12.643,23.916,12.324,24,11.998,24z M19.099,13.993 c0-1.9-1.284-2.406-3.987-2.763c-2.731-0.361-3.009-0.548-3.009-1.187c0-0.528,0.235-1.233,2.258-1.233 c1.807,0,2.473,0.389,2.747,1.607c0.024,0.115,0.129,0.199,0.247,0.199h1.141c0.071,0,0.138-0.031,0.186-0.081 c0.048-0.054,0.074-0.123,0.067-0.196c-0.177-2.098-1.571-3.076-4.388-3.076c-2.508,0-4.004,1.058-4.004,2.833 c0,1.925,1.488,2.457,3.895,2.695c2.88,0.282,3.103,0.703,3.103,1.269c0,0.983-0.789,1.402-2.642,1.402 c-2.327,0-2.839-0.584-3.011-1.742c-0.02-0.124-0.126-0.215-0.253-0.215h-1.137c-0.141,0-0.254,0.112-0.254,0.253 c0,1.482,0.806,3.248,4.655,3.248C17.501,17.007,19.099,15.91,19.099,13.993z" />
        </svg>
    ),
    ruby: (
        <svg viewBox="0 0 24 24">
            <path fill="#CC342D" d="M20.156.083c3.033.525 3.893 2.598 3.829 4.77L24 4.822 22.635 22.71 4.89 23.926h.016C3.433 23.864.15 23.729 0 19.139l1.645-3 2.819 6.586.503 1.172 2.805-9.144-.03.007.016-.03 9.255 2.956-1.396-5.431-.99-3.9 8.82-.569-.615-.51L16.5 2.114 20.159.073l-.003.01zM0 19.089zM5.13 5.073c3.561-3.533 8.157-5.621 9.922-3.84 1.762 1.777-.105 6.105-3.673 9.636-3.563 3.532-8.103 5.734-9.864 3.957-1.766-1.777.045-6.217 3.612-9.75l.003-.003z" />
        </svg>
    ),
};

/**
 * The logo alone, a little taller than the text so it follows the text size, in a box as wide as the PHP oval so names
 * listed one under another line up; nothing for a technology without one.
 */
export function TechnologyLogo({ technology, className }: { technology: string | null; className?: string }) {
    const logo = technology === null ? undefined : LOGOS[technology];

    if (logo === undefined) {
        return null;
    }

    return (
        <span className={cn('inline-flex h-[1.25em] w-[1.75em] shrink-0 items-center justify-center [&>svg]:max-h-full [&>svg]:max-w-full', className)} aria-hidden>
            {logo}
        </span>
    );
}

/** The logo followed by the name, e.g. the PHP logo and "PHP", or "PHP 8.3.12" when the version is part of the label. */
export function TechnologyName({ technology, label, className }: { technology: string | null; label: ReactNode; className?: string }) {
    return (
        <span className={cn('inline-flex items-center gap-1.5 align-middle', className)}>
            <TechnologyLogo technology={technology} />
            <span className="min-w-0">{label}</span>
        </span>
    );
}

/**
 * A translated sentence with the technology's name in it, e.g. "#12 · PHP · 3 tin", with the logo put before the name
 * wherever the translation placed it.
 */
export function withTechnologyName(text: string, technology: { value: string; label: string }): ReactNode {
    const at = text.indexOf(technology.label);

    if (at === -1) {
        return text;
    }

    return (
        <>
            {text.slice(0, at)}
            <TechnologyName technology={technology.value} label={technology.label} />
            {text.slice(at + technology.label.length)}
        </>
    );
}
