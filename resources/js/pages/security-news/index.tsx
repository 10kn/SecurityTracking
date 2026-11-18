import { Link } from '@inertiajs/react';

import { show } from '@/actions/App/Http/Controllers/Admin/SecurityNewsController';
import { Card, CardHeader } from '@/components/admin/card';
import { EmptyState, TablePagination, TableSearch } from '@/components/admin/data-table';
import { PageHeader } from '@/components/admin/page-header';
import { useTableQuery } from '@/hooks/use-table-query';
import { useTranslation } from '@/lib/i18n';
import type { Paginated, TableState } from '@/types';

export interface SecurityNewsArticle {
    id: number;
    title: string;
    section: string;
    published_on: string;
    url: string;
    summary: string | null;
    reason: string | null;
    matched_tags: string[];
    cve_ids: string[];
}

export default function SecurityNewsIndex({ articles, table }: { articles: Paginated<SecurityNewsArticle>; table: TableState }) {
    const t = useTranslation();
    const query = useTableQuery(table, ['articles']);

    return (
        <>
            <PageHeader title={t('Tin bảo mật')} description={t('Tin từ JPCERT/CC khớp tag theo dõi.')} />
            <Card>
                <CardHeader>
                    <TableSearch value={table.search} placeholder={t('Tìm tin bảo mật')} onSearch={(search) => query({ search })} />
                </CardHeader>
                {articles.data.length === 0 ? (
                    <EmptyState title={t(table.search ? 'Không có tin phù hợp' : 'Chưa có tin bảo mật')} />
                ) : (
                    <ul className="divide-y divide-border">
                        {articles.data.map((article) => (
                            <li key={article.id} className="flex flex-col gap-2 px-5 py-4 max-sm:px-3.5">
                                <p className="text-sm text-muted-foreground">
                                    {article.section} · {article.published_on}
                                </p>
                                <h2 className="text-base font-semibold">
                                    <Link href={show.url(article.id)} className="text-link hover:underline">
                                        {article.title}
                                    </Link>
                                </h2>
                                <p className="line-clamp-2 text-sm text-muted-foreground">{article.summary}</p>
                                <p className="text-sm">{article.matched_tags.join(' · ')}</p>
                            </li>
                        ))}
                    </ul>
                )}
                <TablePagination meta={articles.meta} table={table} onChange={query} />
            </Card>
        </>
    );
}
