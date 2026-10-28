import { Link, router } from '@inertiajs/react';
import { CircleAlertIcon, MessageSquareWarningIcon } from 'lucide-react';
import { Fragment, useState, type ReactNode } from 'react';

import { index as findingsIndex } from '@/actions/App/Http/Controllers/Admin/FindingController';
import { index as incidentsIndex, show as showIncident } from '@/actions/App/Http/Controllers/Admin/IncidentController';
import { index as servicesIndex } from '@/actions/App/Http/Controllers/Admin/ServiceController';
import { edit as editTeam, index as teamsIndex } from '@/actions/App/Http/Controllers/Admin/TeamController';
import { store as syncCatalog } from '@/actions/App/Http/Controllers/Admin/TechnologyCatalogSyncController';
import { Callout, CalloutDetails, CalloutList, CalloutListItem, CalloutText, UnknownIcon } from '@/components/admin/callout';
import { Card, CardFooter, CardHeader } from '@/components/admin/card';
import { PageHeader } from '@/components/admin/page-header';
import { SeverityBadge } from '@/components/admin/severity-badge';
import { StatusDot } from '@/components/admin/status-dot';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface Source {
    source: string;
    name: string;
    is_stale: boolean;
    last_success: string | null;
    threshold: string;
    error: string | null;
    last_failure: string | null;
    consequence: string | null;
    /** The technology whose catalog this is, which can be synced again from here. */
    technology: string | null;
}

/** A Google Chat destination messages do not reach; the admin space has no team. */
interface Destination {
    team: { id: number; name: string } | null;
    has_webhook: boolean;
    count: number;
    since: string | null;
    error: string | null;
    stuck_count: number;
    resend_ids: number[];
    resend_hidden_count: number;
}

interface Delivery {
    destinations: Destination[];
    hidden_count: number;
    last_sent: string | null;
}

interface PriorityFinding {
    id: number;
    severity: { value: string; label: string };
    title: string;
    service: string;
    team: string;
    is_known_exploited: boolean;
    is_read: boolean;
    deadline: { label: string; is_overdue: boolean } | null;
}

interface AttentionIncident {
    id: number;
    title: string;
    severity: { value: string; label: string };
    first_published: string | null;
    reasons: string[];
}

interface WorkList<TItem> {
    count: number;
    items: TItem[];
}

/** A block the server could not read is null: it is shown as such, never as nothing to report. */
interface Props {
    checked: { date: string; time: string };
    sources: Source[] | null;
    delivery: Delivery | null;
    coverage: { count: number } | null;
    findings: WorkList<PriorityFinding> | null;
    incidents: WorkList<AttentionIncident> | null;
}

const reload = () => router.reload();

/**
 * The admin home: monitoring problems on top, only when there are some, then where work starts today, then the
 * evidence that "no alert" means checked rather than not known.
 */
export default function Overview({ checked, sources, delivery, coverage, findings, incidents }: Props) {
    const t = useTranslation();

    return (
        <>
            <PageHeader title={t('Tổng quan')} description={t(':date · kiểm tra lúc :time', checked)} />
            <MonitoringCallouts sources={sources} delivery={delivery} coverage={coverage} />
            <div className="grid gap-6 min-[1081px]:grid-cols-2">
                <WorkCard
                    title={t('Finding ưu tiên')}
                    description={t('CISA KEV, quá hạn, hoặc Critical / High chưa đọc')}
                    list={findings}
                    href={findingsIndex.url({ query: { priority: 1 } })}
                    more={(count) => t('+:count finding khác', { count })}
                    error={{ title: t('Không tải được finding ưu tiên'), description: t('Số đếm không được coi là 0. Các phần tải được vẫn hiển thị.') }}
                    empty={{ title: t('Không có finding ưu tiên'), description: t('Finding đang mở khác vẫn có ở màn Findings.'), action: t('Mở Findings'), href: findingsIndex.url() }}
                    renderItem={(finding) => (
                        <WorkItem
                            key={finding.id}
                            severity={finding.severity}
                            title={finding.title}
                            href={findingsIndex.url({ query: { ids: finding.id } })}
                            sub={
                                <>
                                    {finding.service} · {finding.team} ·{' '}
                                    {finding.is_known_exploited ? <span className="font-medium text-danger-text">CISA KEV</span> : finding.is_read ? t('Đã đọc') : t('Chưa đọc')}
                                </>
                            }
                            aside={finding.deadline ? <Deadline label={finding.deadline.label} isOverdue={finding.deadline.is_overdue} /> : null}
                        />
                    )}
                />
                <WorkCard
                    title={t('Incident cần xem')}
                    description={t('Cần đánh giá hoặc đánh giá lại ảnh hưởng, hoặc có gợi ý gộp đang chờ')}
                    list={incidents}
                    href={incidentsIndex.url({ query: { attention: 1 } })}
                    more={(count) => t('+:count incident khác', { count })}
                    error={{ title: t('Không tải được incident cần xem'), description: t('Số đếm không được coi là 0. Các phần tải được vẫn hiển thị.') }}
                    empty={{ title: t('Không có incident cần xem'), description: t('Incident khác vẫn có ở màn Incidents.'), action: t('Mở Incidents'), href: incidentsIndex.url() }}
                    renderItem={(incident) => (
                        <WorkItem
                            key={incident.id}
                            severity={incident.severity}
                            title={incident.title}
                            href={showIncident.url(incident.id)}
                            sub={incident.reasons.join(' · ')}
                            aside={incident.first_published && <Deadline label={incident.first_published} isOverdue={false} />}
                        />
                    )}
                />
            </div>
            <Evidence checked={checked} sources={sources} delivery={delivery} />
        </>
    );
}

/** At most three callouts: detection (sources), delivery (Google Chat), coverage (services). Hidden when all is well. */
function MonitoringCallouts({ sources, delivery, coverage }: Pick<Props, 'sources' | 'delivery' | 'coverage'>) {
    const t = useTranslation();
    const retry = (
        <Button variant="secondary" size="sm" onClick={reload}>
            {t('Thử lại')}
        </Button>
    );
    const callouts: ReactNode[] = [];

    if (sources === null && delivery === null) {
        callouts.push(
            <Callout key="monitoring" tone="danger" icon={<CircleAlertIcon strokeWidth={1.8} />} title={t('Không kiểm tra được sự cố giám sát')} action={retry}>
                <CalloutText>{t('Chưa biết nguồn dữ liệu và việc gửi Google Chat có đang lỗi hay không.')}</CalloutText>
            </Callout>,
        );
    } else {
        callouts.push(<SourcesCallout key="sources" sources={sources} retry={retry} />);
        callouts.push(<DeliveryCallout key="delivery" delivery={delivery} retry={retry} />);
    }

    if (coverage === null) {
        callouts.push(
            <Callout key="coverage" icon={<UnknownIcon />} title={t('Không đếm được service chưa được đánh giá đầy đủ')} action={retry}>
                <CalloutText>{t('Không tính các service này là Đạt cho tới khi đếm được.')}</CalloutText>
            </Callout>,
        );
    } else if (coverage.count > 0) {
        callouts.push(
            <Callout
                key="coverage"
                icon={<UnknownIcon />}
                title={t(':count service chưa được đánh giá đầy đủ', { count: coverage.count })}
                action={
                    <Link href={servicesIndex.url({ query: { attention: 1 } })} className="font-medium whitespace-nowrap text-link hover:underline hover:underline-offset-3">
                        {t('Xem :count service →', { count: coverage.count })}
                    </Link>
                }
            >
                <CalloutText>{t('Runtime chưa rõ trạng thái hoặc lockfile không đọc được. Không tính là Đạt.')}</CalloutText>
            </Callout>,
        );
    }

    // A callout with nothing to report renders nothing, which leaves the section empty and hidden.
    return (
        <section aria-label={t('Sự cố giám sát')} className="mb-6 grid gap-2 empty:hidden">
            {callouts}
        </section>
    );
}

function SourcesCallout({ sources, retry }: { sources: Source[] | null; retry: ReactNode }) {
    const t = useTranslation();
    const icon = <CircleAlertIcon strokeWidth={1.8} />;

    if (sources === null) {
        return (
            <Callout tone="danger" icon={icon} title={t('Không đọc được thời điểm cập nhật của nguồn')} action={retry}>
                <CalloutText>{t('Chưa biết nguồn dữ liệu có đang lỗi hay không.')}</CalloutText>
            </Callout>
        );
    }

    if (sources.length === 0) {
        return (
            <Callout tone="danger" icon={icon} title={t('Chưa ghi nhận nguồn dữ liệu nào')}>
                <CalloutText>{t('Chưa có lần đồng bộ nào được ghi lại, nên chưa phát hiện được gì. Kiểm tra cron schedule:run và queue worker.')}</CalloutText>
            </Callout>
        );
    }

    const stale = sources.filter((source) => source.is_stale);

    if (stale.length === 0) {
        return null;
    }

    if (stale.length === 1) {
        const [source] = stale;

        return (
            <Callout tone="danger" icon={icon} title={<StaleSentence source={source} />} action={source.technology && <SyncButton technology={source.technology} variant="secondary" />}>
                {source.consequence && <CalloutText>{source.consequence}</CalloutText>}
                <SourceError source={source} />
            </Callout>
        );
    }

    return (
        <Callout tone="danger" icon={icon} title={t(':count nguồn dữ liệu quá ngưỡng', { count: stale.length })}>
            <CalloutList>
                {stale.map((source) => (
                    <CalloutListItem key={source.source}>
                        <span>
                            <StaleSentence source={source} />
                        </span>
                        {source.technology && <SyncButton technology={source.technology} variant="link" />}
                        <SourceError source={source} withConsequence />
                    </CalloutListItem>
                ))}
            </CalloutList>
        </Callout>
    );
}

function StaleSentence({ source }: { source: Source }) {
    const t = useTranslation();

    return source.last_success
        ? t(':source thành công lần cuối :time (ngưỡng :threshold)', { source: source.name, time: source.last_success, threshold: source.threshold })
        : t(':source chưa thành công lần nào (ngưỡng :threshold)', { source: source.name, threshold: source.threshold });
}

/** "Xem lỗi": the last error the source recorded, opened in place since only catalogs can be run again from here. */
function SourceError({ source, withConsequence = false }: { source: Source; withConsequence?: boolean }) {
    const t = useTranslation();

    return (
        <CalloutDetails summary={t('Xem lỗi')}>
            {withConsequence && source.consequence && <p>{source.consequence}</p>}
            {source.error ? (
                <p className="font-mono">{source.last_failure ? t(':error · lần thử lỗi gần nhất :time', { error: source.error, time: source.last_failure }) : source.error}</p>
            ) : (
                <p>{t('Chưa ghi nhận lần thử lỗi nào. Kiểm tra cron schedule:run và queue worker.')}</p>
            )}
        </CalloutDetails>
    );
}

/** "Chạy lại": queues a catalog sync, the one source an admin can run from the admin pages. */
function SyncButton({ technology, variant }: { technology: string; variant: 'secondary' | 'link' }) {
    const t = useTranslation();
    const [isSyncing, setIsSyncing] = useState(false);

    return (
        <Button
            variant={variant}
            size="sm"
            className={cn(variant === 'link' && 'h-auto')}
            loading={isSyncing}
            onClick={() => router.post(syncCatalog.url(technology), {}, { preserveScroll: true, onStart: () => setIsSyncing(true), onFinish: () => setIsSyncing(false) })}
        >
            {t('Chạy lại')}
        </Button>
    );
}

function DeliveryCallout({ delivery, retry }: { delivery: Delivery | null; retry: ReactNode }) {
    const t = useTranslation();
    const icon = <MessageSquareWarningIcon strokeWidth={1.8} />;

    if (delivery === null) {
        return (
            <Callout tone="danger" icon={icon} title={t('Không đọc được lịch sử gửi Google Chat')} action={retry}>
                <CalloutText>{t('Chưa biết tin Google Chat có đang gửi được hay không.')}</CalloutText>
            </Callout>
        );
    }

    if (delivery.destinations.length === 0) {
        return null;
    }

    return (
        <Callout tone="danger" icon={icon} title={t('Tin Google Chat chưa gửi được')}>
            <CalloutList>
                {delivery.destinations.map((destination) => (
                    <DestinationItem key={destination.team?.id ?? 'admin'} destination={destination} />
                ))}
                {delivery.hidden_count > 0 && (
                    <CalloutListItem>
                        <Link href={teamsIndex.url()} className="font-medium whitespace-nowrap text-link hover:underline hover:underline-offset-3">
                            {t('+:count nơi nhận khác', { count: delivery.hidden_count })}
                        </Link>
                    </CalloutListItem>
                )}
            </CalloutList>
        </Callout>
    );
}

function DestinationItem({ destination }: { destination: Destination }) {
    const t = useTranslation();
    const { team } = destination;
    const text =
        team === null
            ? destination.count > 0
                ? t('Space admin: :count tin chưa gửi từ :time — cảnh báo nguồn lỗi chưa tới admin', { count: destination.count, time: destination.since ?? '—' })
                : t('Space admin: chưa có webhook — cảnh báo nguồn lỗi không tới được admin')
            : destination.count > 0
              ? t(':team: :count tin chưa gửi từ :time', { team: team.name, count: destination.count, time: destination.since ?? '—' })
              : t(':team: chưa có webhook', { team: team.name });
    const commands = destination.resend_ids.map((id) => `php artisan psm:resend-notification ${id}`);

    return (
        <CalloutListItem>
            <span>{text}</span>
            {team !== null && (
                <Link href={editTeam.url(team.id)} className="font-medium whitespace-nowrap text-link hover:underline hover:underline-offset-3">
                    {destination.has_webhook ? t('Sửa team') : t('Thêm webhook')}
                </Link>
            )}
            {(team === null || destination.count > 0) && (
                <CalloutDetails summary={t('Xem lỗi')}>
                    {destination.error && (
                        <p>
                            {t('Lỗi gần nhất:')} {destination.error}
                        </p>
                    )}
                    {destination.stuck_count > 0 && <p>{t(':count tin chờ trong hàng đợi quá lâu. Kiểm tra queue worker (php artisan queue:work).', { count: destination.stuck_count })}</p>}
                    {team === null && (
                        <p>
                            {t('Cấu hình:')} <span className="font-mono">PSM_ADMIN_CHAT_WEBHOOK</span>
                        </p>
                    )}
                    {commands.length > 0 && (
                        <p>
                            {t('Gửi lại sau khi sửa:')}{' '}
                            {commands.map((command, index) => (
                                <Fragment key={command}>
                                    {index > 0 && ', '}
                                    <span className="font-mono">{command}</span>
                                </Fragment>
                            ))}
                            {destination.resend_hidden_count > 0 && ` ${t('… và :count mục khác', { count: destination.resend_hidden_count })}`}
                        </p>
                    )}
                </CalloutDetails>
            )}
        </CalloutListItem>
    );
}

/** A short list of where work starts, its count opening the whole filtered list; a zero still links to it. */
function WorkCard<TItem>({
    title,
    description,
    list,
    href,
    more,
    error,
    empty,
    renderItem,
}: {
    title: string;
    description: string;
    list: WorkList<TItem> | null;
    href: string;
    more: (count: number) => string;
    error: { title: string; description: string };
    empty: { title: string; description: string; action: string; href: string };
    renderItem: (item: TItem) => ReactNode;
}) {
    const t = useTranslation();

    return (
        <Card className="flex flex-col">
            <CardHeader className="flex-nowrap items-start">
                <div className="min-w-0">
                    <h2 className="text-base font-semibold">{title}</h2>
                    <p className="mt-px text-sm text-muted-foreground">{description}</p>
                </div>
                {list === null ? (
                    <span className="text-h1 leading-[1.2] font-semibold text-faint" aria-label={t('Không tải được')}>
                        —
                    </span>
                ) : (
                    <Link href={href} className="text-h1 leading-[1.2] font-semibold tabular-nums hover:underline hover:underline-offset-4">
                        {list.count}
                    </Link>
                )}
            </CardHeader>
            {list === null ? (
                <CompactEmpty
                    title={error.title}
                    description={error.description}
                    action={
                        <Button variant="secondary" size="sm" onClick={reload}>
                            {t('Thử lại')}
                        </Button>
                    }
                />
            ) : list.count === 0 ? (
                <CompactEmpty
                    title={empty.title}
                    description={empty.description}
                    action={
                        <Button variant="secondary" size="sm" asChild>
                            <Link href={empty.href}>{empty.action}</Link>
                        </Button>
                    }
                />
            ) : (
                <>
                    <ul className="flex-1">{list.items.map(renderItem)}</ul>
                    {list.count > list.items.length && (
                        <CardFooter>
                            <Link href={href} className="font-medium text-link hover:underline hover:underline-offset-3">
                                {more(list.count - list.items.length)}
                            </Link>
                        </CardFooter>
                    )}
                </>
            )}
        </Card>
    );
}

/** Under 640px the deadline moves next to the badge and the title takes the whole row. */
function WorkItem({ severity, title, href, sub, aside }: { severity: { value: string; label: string }; title: string; href: string; sub: ReactNode; aside: ReactNode }) {
    return (
        <li className="grid grid-cols-[92px_minmax(0,1fr)_auto] items-baseline gap-x-3 gap-y-1 border-b border-border px-5 py-3 last:border-b-0 max-sm:grid-cols-[minmax(0,1fr)_auto] max-sm:px-3.5">
            <span className="justify-self-start">
                <SeverityBadge severity={severity} />
            </span>
            <div className="min-w-0 max-sm:col-span-full">
                <Link href={href} className="font-medium [overflow-wrap:anywhere] hover:underline hover:underline-offset-3">
                    {title}
                </Link>
                <span className="mt-0.5 block text-sm text-muted-foreground">{sub}</span>
            </div>
            <span className="max-sm:col-start-2 max-sm:row-start-1">{aside}</span>
        </li>
    );
}

function Deadline({ label, isOverdue }: { label: string; isOverdue: boolean }) {
    return <span className={cn('text-sm whitespace-nowrap tabular-nums', isOverdue ? 'font-medium text-danger-text' : 'text-muted-foreground')}>{label}</span>;
}

function CompactEmpty({ title, description, action }: { title: string; description: string; action: ReactNode }) {
    return (
        <div className="grid flex-1 content-center justify-items-center gap-1.5 px-5 py-8 text-center text-muted-foreground">
            <strong className="font-semibold text-foreground">{title}</strong>
            <p>{description}</p>
            <div className="mt-2.5">{action}</div>
        </div>
    );
}

/**
 * Grey line at the bottom: when the page was checked, whether a recorded source is over its threshold, and the last
 * Google Chat message recorded as delivered, so "no alert" is never read as "not checked".
 */
function Evidence({ checked, sources, delivery }: Pick<Props, 'checked' | 'sources' | 'delivery'>) {
    const t = useTranslation();
    const staleCount = sources?.filter((source) => source.is_stale).length ?? 0;
    const items: { text: string; isAlert: boolean }[] = [
        { text: t('Kiểm tra lúc :time', { time: checked.time }), isAlert: false },
        sources === null
            ? { text: t('Không đọc được thời điểm cập nhật của nguồn'), isAlert: true }
            : sources.length === 0
              ? { text: t('Chưa ghi nhận nguồn dữ liệu nào'), isAlert: true }
              : staleCount > 0
                ? { text: t(':count nguồn quá ngưỡng', { count: staleCount }), isAlert: true }
                : { text: t('Không có nguồn đã ghi nhận nào quá ngưỡng'), isAlert: false },
        delivery === null
            ? { text: t('Không đọc được lịch sử gửi Google Chat'), isAlert: true }
            : delivery.last_sent
              ? { text: t('Ghi nhận gửi Google Chat gần nhất: :time', { time: delivery.last_sent }), isAlert: false }
              : { text: t('Chưa ghi nhận tin Google Chat nào được gửi'), isAlert: false },
    ];

    return (
        <footer className="mt-5 text-sm text-faint">
            <p className="flex flex-wrap gap-y-0.5 max-sm:flex-col">
                {items.map((item, index) => (
                    <span key={item.text} className={cn(item.isAlert && 'font-medium text-danger-text')}>
                        {index > 0 && (
                            <span className="mx-2 font-normal text-faint max-sm:hidden" aria-hidden>
                                ·
                            </span>
                        )}
                        {item.text}
                    </span>
                ))}
            </p>
            {sources !== null && sources.length > 0 && (
                <details>
                    <summary className="mt-1.5 w-fit cursor-pointer text-muted-foreground">{t(':count nguồn đã ghi nhận', { count: sources.length })}</summary>
                    <ul className="mt-2 max-w-[560px] rounded-lg border border-border bg-surface">
                        {sources.map((source) => (
                            <li key={source.source} className="flex items-center justify-between gap-3 border-b border-border px-4 py-2 last:border-b-0 max-sm:flex-wrap max-sm:gap-y-0">
                                <StatusDot status={{ label: source.name, tone: source.is_stale ? 'danger' : 'success' }} />
                                <span className={cn('text-sm whitespace-nowrap tabular-nums', source.is_stale ? 'font-medium text-danger-text' : 'text-muted-foreground')}>
                                    {source.last_success
                                        ? t(':time · ngưỡng :threshold', { time: source.last_success, threshold: source.threshold })
                                        : t('Chưa thành công lần nào · ngưỡng :threshold', { threshold: source.threshold })}
                                </span>
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </footer>
    );
}
