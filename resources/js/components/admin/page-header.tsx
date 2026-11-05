import { Head, Link } from '@inertiajs/react';
import { Fragment, type ReactNode } from 'react';

export interface Breadcrumb {
    label: string;
    href?: string;
}

/**
 * Breadcrumbs, the page title with a short description, and the page actions on the right. Also sets the document title.
 */
export function PageHeader({
    title,
    description,
    titleIcon,
    breadcrumbs = [],
    actions,
}: {
    title: string;
    /** Shown before the title, e.g. the technology's logo; the document title stays text only. */
    titleIcon?: ReactNode;
    description?: ReactNode;
    breadcrumbs?: Breadcrumb[];
    actions?: ReactNode;
}) {
    return (
        <>
            <Head title={title} />
            {breadcrumbs.length > 0 && (
                <nav className="mb-1.5 flex flex-wrap gap-1.5 text-sm text-faint" aria-label="Breadcrumb">
                    {breadcrumbs.map((breadcrumb, index) => (
                        <Fragment key={index}>
                            {index > 0 && <span aria-hidden>/</span>}
                            {breadcrumb.href ? (
                                <Link href={breadcrumb.href} className="text-muted-foreground hover:text-foreground">
                                    {breadcrumb.label}
                                </Link>
                            ) : (
                                <span aria-current="page">{breadcrumb.label}</span>
                            )}
                        </Fragment>
                    ))}
                </nav>
            )}
            <header className="mb-6 flex flex-wrap items-end justify-between gap-4 max-sm:flex-col max-sm:items-start">
                <div className="min-w-0">
                    <h1 className="flex items-center gap-2.5 text-h1 font-semibold tracking-[-0.02em]">
                        {titleIcon}
                        {title}
                    </h1>
                    {description && <p className="mt-1 text-muted-foreground">{description}</p>}
                </div>
                {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
            </header>
        </>
    );
}
