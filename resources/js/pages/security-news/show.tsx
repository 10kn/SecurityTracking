import { ExternalLinkIcon } from 'lucide-react';

import { index } from '@/actions/App/Http/Controllers/Admin/SecurityNewsController';
import { Card, CardBody } from '@/components/admin/card';
import { PageHeader } from '@/components/admin/page-header';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';

import type { SecurityNewsArticle } from './index';

export default function SecurityNewsShow({ article }: { article: SecurityNewsArticle }) {
    const t = useTranslation();

    return (
        <>
            <PageHeader
                title={article.title}
                description={<>JPCERT/CC · {article.section} · {article.published_on}</>}
                breadcrumbs={[{ label: t('Tin bảo mật'), href: index.url() }]}
                actions={
                    <Button asChild variant="secondary">
                        <a href={article.url} target="_blank" rel="noreferrer">
                            {t('Mở nguồn tin')}
                            <ExternalLinkIcon />
                        </a>
                    </Button>
                }
            />
            <Card className="max-w-4xl">
                <CardBody className="flex flex-col gap-5">
                    <section>
                        <h2 className="mb-1.5 font-semibold">{t('Tóm tắt')}</h2>
                        <p className="text-muted-foreground">{article.summary}</p>
                    </section>
                    <dl className="flex flex-col gap-4">
                        <div>
                            <dt className="mb-1 font-semibold">{t('Tag theo dõi')}</dt>
                            <dd>{article.matched_tags.join(' · ')}</dd>
                        </div>
                        <div>
                            <dt className="mb-1 font-semibold">{t('Lý do nhận tin')}</dt>
                            <dd className="text-muted-foreground">{article.reason}</dd>
                        </div>
                        {article.cve_ids.length > 0 && (
                            <div>
                                <dt className="mb-1 font-semibold">CVE</dt>
                                <dd>{article.cve_ids.join(' · ')}</dd>
                            </div>
                        )}
                    </dl>
                </CardBody>
            </Card>
        </>
    );
}
