import {
    CheckIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    ClockIcon,
    FilesIcon,
    FunnelIcon,
    GitForkIcon,
    MessageSquareTextIcon,
    NewspaperIcon,
    PauseIcon,
    PlayIcon,
    RotateCcwIcon,
    SearchCheckIcon,
    ShieldCheckIcon,
    TagsIcon,
    UnplugIcon,
    XIcon,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useId, useMemo, useRef, useState } from 'react';

import { Card, CardBody, CardHeader, CardTitle } from '@/components/admin/card';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { buildScenes, nodeDetails, pointOnRoute, positions, routes, type NodeId, type OutputState, type Question, type Scenario } from './workflow-model';
import { VendorSources } from './vendor-sources';
import type { VendorNewsSource } from './workflow-model';
import './workflow.css';

interface WorkflowProps {
    vendorSources: VendorNewsSource[];
    schedule: { key: string; every_minutes: number | null; minute: number; times: string[] }[];
    timezone: string;
    digestDays: string;
    immediateSeverities: string[];
    noticeRelevantDays: number;
}

const sceneDuration = 2200;
const icons: Record<NodeId, LucideIcon> = {
    source: NewspaperIcon,
    collect: FunnelIcon,
    classify: TagsIcon,
    assess: SearchCheckIcon,
    immediate: GitForkIcon,
    digest: FilesIcon,
    webhook: UnplugIcon,
    team: MessageSquareTextIcon,
    admin: ShieldCheckIcon,
};
const scenarioIcons: Record<Scenario, LucideIcon> = { vendor: NewspaperIcon, package: SearchCheckIcon, digest: FilesIcon, kev: ShieldCheckIcon, failure: UnplugIcon };

function Packet({ id, point, bundle = false }: { id: string; point: [number, number]; bundle?: boolean }) {
    return (
        <g className="guide-packet" transform={`translate(${point[0]} ${point[1]})`}>
            {bundle && <rect className="guide-packet-back" x="-26" y="-18" width="60" height="26" rx="4" />}
            <rect x="-31" y="-13" width="62" height="26" rx="4" />
            <circle cx="-20" r="3.5" />
            <text x="-11" y="5">
                {id}
            </text>
        </g>
    );
}

export function WorkflowGuide({ vendorSources, schedule, timezone, digestDays, immediateSeverities, noticeRelevantDays }: WorkflowProps) {
    const t = useTranslation();
    const markerId = useId();
    const titleId = useId();
    const [context, setContext] = useState<{ scenario: Scenario; question: Question | null }>({ scenario: 'vendor', question: null });
    const [playhead, setPlayhead] = useState({ index: 0, progress: 0 });
    const cursor = useRef(playhead);
    const [isPlaying, setIsPlaying] = useState(false);
    const [isContinuous, setIsContinuous] = useState(true);
    const [speed, setSpeed] = useState(1);
    const [reducedMotion, setReducedMotion] = useState(false);
    const [selectedNode, setSelectedNode] = useState<NodeId | null>(null);
    const [openNode, setOpenNode] = useState<NodeId | null>(null);
    const [hoverNode, setHoverNode] = useState<NodeId | null>(null);
    const [preview, setPreview] = useState<'admin' | 'team'>('team');
    const { scenario, question } = context;
    const hasTeam = question !== 'no-team';
    const sourceKey = scenario === 'package' || scenario === 'digest' ? 'packages' : scenario === 'kev' ? 'kev' : scenario === 'failure' ? 'sources' : 'notices';
    const collection = schedule.find((row) => row.key === sourceKey);
    const frequency =
        collection?.every_minutes == null
            ? collection?.times.length
                ? `${t('Mỗi ngày lúc :times', { times: collection.times.join(' · ') })} ${timezone}`
                : t('Theo lịch cấu hình')
            : collection.every_minutes === 60
              ? t('Mỗi giờ, vào phút :minute', { minute: collection.minute })
              : t('Mỗi :count phút', { count: collection.every_minutes });
    const options = useMemo(
        () => ({
            scenario,
            hasTeam,
            retry: question === 'retry',
            immediateSeverities,
            collectionFrequency: frequency,
            digestTime: schedule.find((row) => row.key === 'digest')?.times[0] || t('Theo cấu hình'),
            digestDays,
            timezone,
            noticeRelevantDays,
        }),
        [scenario, hasTeam, question, immediateSeverities, frequency, schedule, digestDays, timezone, noticeRelevantDays, t],
    );
    const scenes = useMemo(() => buildScenes(options, t), [options, t]);
    const current = scenes[playhead.index];
    const arrived = playhead.progress >= 0.62;
    const complete = playhead.progress >= 0.99;
    const atEnd = playhead.index === scenes.length - 1 && complete;
    const detailNode = selectedNode ?? current.node;
    const detail = nodeDetails(detailNode, options, t);
    const pathSet = new Set(scenes.map((scene) => scene.path));
    const relevantNodes = new Set(scenes.map((scene) => scene.node));
    const previous = scenes[Math.max(0, playhead.index - 1)];
    const outputs: Record<'admin' | 'team', OutputState> = {
        admin: complete ? current.admin : current.path === 'news-admin' || current.path === 'source-failed' ? 'sending' : previous.admin,
        team: complete
            ? current.team
            : current.path === 'deliver'
              ? 'sending'
              : current.path === 'retry'
                ? current.team === 'retry'
                    ? 'retry'
                    : 'sending'
                : current.node === 'digest'
                  ? 'waiting'
                  : previous.team,
    };
    const labels: Record<NodeId, string> = {
        source: scenario === 'vendor' ? t('Tin vendor') : scenario === 'kev' ? 'CISA KEV' : scenario === 'failure' ? t('Nguồn dữ liệu') : t('Lỗ hổng package'),
        collect: t('Thu thập'),
        classify: scenario === 'vendor' ? t('Phân loại') : t('Chuẩn hoá'),
        assess: t('Tìm team'),
        immediate: t('Gửi ngay'),
        digest: t('Gom bản tin'),
        webhook: t('Gửi webhook'),
        team: t('Space của team'),
        admin: t('Kênh admin'),
    };
    const subtitles: Record<NodeId, string> = {
        source:
            scenario === 'vendor'
                ? 'PHP / Node.js / Ruby'
                : scenario === 'kev'
                  ? t('CVE bị khai thác')
                  : scenario === 'failure'
                    ? t('Feed hoặc API lỗi')
                    : 'Packagist / RubySec / OSV',
        collect: frequency,
        classify: scenario === 'vendor' ? t('AI đọc tin vendor') : t('Dữ liệu để đối chiếu'),
        assess: hasTeam ? t('Service liên quan') : t('Không có service khớp'),
        immediate: t('Tin đủ điều kiện'),
        digest: t('Theo lịch cấu hình'),
        webhook: 'Queue → HTTP',
        team: t('Trạng thái riêng'),
        admin: t('Không chờ khớp team'),
    };
    const sourceNames =
        scenario === 'vendor'
            ? ['PHP', 'Node.js', 'Ruby']
            : scenario === 'kev'
              ? ['', 'CISA KEV', '']
              : scenario === 'failure'
                ? [t('Feed'), 'API', t('Vendor')]
                : ['OSV', 'Packagist', 'RubySec'];
    const scenarioLabels: Record<Scenario, string> = {
        vendor: t('Tin vendor'),
        package: t('Lỗ hổng package'),
        digest: t('Bản tin ngày'),
        kev: 'CISA KEV',
        failure: t('Lỗi nguồn'),
    };
    const stateLabels: Record<OutputState, string> = {
        inactive: t('Không thuộc tình huống'),
        new: t('Chưa tạo đầu ra'),
        ready: t('Đủ điều kiện gửi'),
        waiting: t('Chờ lịch bản tin'),
        queued: t('Đang trong queue'),
        sending: t('Đang gửi · minh hoạ'),
        sent: t('Đã gửi · minh hoạ'),
        skipped: t('Không có team phù hợp'),
        failed: t('Lỗi tạm thời · minh hoạ'),
        retry: t('Đang thử lại · minh hoạ'),
    };
    const isDigest = scenes.some((scene) => scene.node === 'digest');
    const outputId = isDigest ? 'D01' : 'T01';
    const questionLabels: Record<Question, string> = {
        recipients: t('Ai sẽ nhận tin này?'),
        polling: t('Vì sao chưa nhận được?'),
        digest: t('Đang chờ bản tin?'),
        'no-team': t('Nếu không có team phù hợp?'),
        retry: t('Nếu webhook gửi lỗi?'),
    };
    const answers: Record<Question, string> = {
        recipients:
            scenario === 'vendor'
                ? t('A01 dành cho kênh admin. T01 chỉ được tạo khi có team phù hợp và đủ điều kiện gửi.')
                : scenario === 'failure'
                  ? t('Admin vận hành nhận cảnh báo nguồn; không gửi cảnh báo này cho mọi team.')
                  : t('Team có service liên quan nhận qua webhook của team mình.'),
        polling: t('Tin có ở nguồn nhưng PSM chưa tới lượt thu thập kế tiếp.'),
        digest: t('PSM đã có dữ liệu nhưng đang gom nội dung để chờ lịch bản tin của team.'),
        'no-team': t('Không tạo T01. A01 của admin vẫn giữ trạng thái đã gửi trong ví dụ này.'),
        retry: t('T01 chưa gửi thành công. Trạng thái đã gửi của A01 không thay đổi.'),
    };

    function seek(index: number, progress = 0) {
        const next = { index, progress };
        cursor.current = next;
        setPlayhead(next);
    }

    function reset() {
        setIsPlaying(false);
        setOpenNode(null);
        setSelectedNode(null);
        seek(0);
    }

    function selectScenario(next: Scenario) {
        reset();
        setContext({ scenario: next, question: null });
        setPreview(next === 'failure' ? 'admin' : 'team');
    }

    function ask(next: Question) {
        const nextScenario: Scenario = next === 'no-team' || next === 'retry' ? 'vendor' : next === 'digest' ? 'digest' : scenario;
        const nextScenes = buildScenes({ ...options, scenario: nextScenario, hasTeam: next !== 'no-team', retry: next === 'retry' }, t);
        const index =
            next === 'recipients'
                ? nextScenes.length - 1
                : next === 'no-team'
                  ? nextScenes.findIndex((scene) => scene.node === 'assess')
                  : next === 'retry'
                    ? nextScenes.findIndex((scene) => scene.team === 'failed')
                    : next === 'digest'
                      ? nextScenes.findIndex((scene) => scene.node === 'digest')
                      : 0;
        reset();
        setContext({ scenario: nextScenario, question: next });
        setPreview(next === 'no-team' || nextScenario === 'failure' ? 'admin' : 'team');
        seek(index, next === 'recipients' || next === 'digest' ? 1 : 0);
        if (next === 'no-team' || next === 'retry') {
            setIsContinuous(false);
            setIsPlaying(true);
        }
    }

    function play() {
        setOpenNode(null);
        setSelectedNode(null);
        if (atEnd) seek(0);
        else if (complete) seek(playhead.index + 1);
        setIsContinuous(true);
        setIsPlaying(true);
    }

    useEffect(() => {
        const media = matchMedia('(prefers-reduced-motion: reduce)');
        const update = () => setReducedMotion(media.matches);
        update();
        media.addEventListener('change', update);
        const stop = () => {
            if (document.hidden) setIsPlaying(false);
        };
        document.addEventListener('visibilitychange', stop);
        return () => {
            media.removeEventListener('change', update);
            document.removeEventListener('visibilitychange', stop);
        };
    }, []);

    useEffect(() => {
        if (!isPlaying) return;
        let frame = 0;
        let last: number | null = null;
        const tick = (time: number) => {
            const delta = last === null ? 0 : ((time - last) * speed) / sceneDuration;
            last = time;
            let next = { ...cursor.current, progress: Math.min(cursor.current.progress + delta, 1) };
            if (next.progress >= 1 && next.index < scenes.length - 1 && isContinuous) next = { index: next.index + 1, progress: 0 };
            cursor.current = next;
            setPlayhead(next);
            if (next.progress >= 1) {
                setIsPlaying(false);
                return;
            }
            frame = requestAnimationFrame(tick);
        };
        frame = requestAnimationFrame(tick);
        return () => cancelAnimationFrame(frame);
    }, [isPlaying, isContinuous, scenes.length, speed]);

    function renderNode(node: NodeId) {
        if (node === 'source' && scenario === 'vendor' && vendorSources.length > 0) {
            return (
                <VendorSources
                    key="source"
                    sources={vendorSources}
                    title={labels.source}
                    subtitle={subtitles.source}
                    frequency={frequency}
                    onInspect={() => {
                        setOpenNode(null);
                        setSelectedNode('source');
                    }}
                    onHover={(hovering) => setHoverNode(hovering ? 'source' : null)}
                />
            );
        }
        const [x, y] = positions[node];
        const Icon = icons[node];
        const info = nodeDetails(node, options, t);
        const isCurrent = current.node === node;
        const isProcessed = scenes.slice(0, playhead.index).some((scene) => scene.node === node) || (isCurrent && complete);
        return (
            <Popover
                key={node}
                open={openNode === node}
                onOpenChange={(open) => {
                    setOpenNode(open ? node : null);
                    if (open) setSelectedNode(node);
                }}
            >
                <PopoverTrigger asChild>
                    <button
                        type="button"
                        className={cn(
                            'guide-node',
                            node === 'source' && 'guide-source',
                            !relevantNodes.has(node) && 'is-off',
                            isCurrent && 'is-current',
                            isCurrent && arrived && !complete && 'is-processing',
                        )}
                        style={node === 'source' ? undefined : { left: `${(x / 1120) * 100}%`, top: `${(y / 648) * 100}%` }}
                        onMouseEnter={() => setHoverNode(node)}
                        onMouseLeave={() => setHoverNode(null)}
                        onFocus={() => setHoverNode(node)}
                        onBlur={() => setHoverNode(null)}
                        aria-label={t('Giải thích: :label', { label: labels[node] })}
                    >
                        {node === 'source' ? (
                            <>
                                <span className="guide-node-label">{labels[node]}</span>
                                <span className="guide-node-sub">{subtitles[node]}</span>
                                <span className="guide-feeds">
                                    {sourceNames.map((name, i) => (
                                        <span key={i} className={cn('guide-feed', !name && 'invisible')}>
                                            <span>{name || '—'}</span>
                                            <span className="guide-source-circle">
                                                <NewspaperIcon aria-hidden />
                                            </span>
                                        </span>
                                    ))}
                                </span>
                            </>
                        ) : (
                            <>
                                <span className="guide-node-circle">
                                    <Icon className={cn('guide-mechanism', `guide-${node}-icon`)} aria-hidden />
                                    {node === 'collect' && (
                                        <span className="guide-grains" aria-hidden>
                                            <i />
                                            <i />
                                            <i />
                                        </span>
                                    )}
                                    {node === 'assess' && <span className="guide-scan" aria-hidden />}
                                    {isProcessed && (
                                        <span className="guide-check">
                                            <CheckIcon aria-hidden />
                                        </span>
                                    )}
                                </span>
                                <span className="guide-node-label">{labels[node]}</span>
                                <span className="guide-node-sub">{subtitles[node]}</span>
                            </>
                        )}
                    </button>
                </PopoverTrigger>
                <PopoverContent
                    side="top"
                    align="center"
                    sideOffset={12}
                    collisionPadding={12}
                    className="max-h-[min(480px,70dvh)] w-[360px] max-w-[calc(100vw-24px)] overflow-y-auto p-4"
                    aria-label={info.title}
                >
                    <div className="flex items-start justify-between gap-3">
                        <h3 className="font-semibold">{info.title}</h3>
                        <button type="button" className="shrink-0 rounded p-1 text-faint hover:bg-surface-2" onClick={() => setOpenNode(null)} aria-label={t('Đóng giải thích')}>
                            <XIcon className="size-4" aria-hidden />
                        </button>
                    </div>
                    <p className="mt-2 text-sm text-muted-foreground">{info.summary}</p>
                    <dl className="mt-3 grid gap-3 text-sm">
                        {info.facts.map(([label, value]) => (
                            <div key={label} className="grid grid-cols-[76px_minmax(0,1fr)] gap-3">
                                <dt className="text-faint">{label}</dt>
                                <dd>{value}</dd>
                            </div>
                        ))}
                    </dl>
                    <p className="mt-3 border-t border-border pt-3 text-xs text-faint">{t('Bấm step khác để khám phá · Esc để đóng')}</p>
                </PopoverContent>
            </Popover>
        );
    }

    return (
        <Card className="mt-6 overflow-hidden" aria-labelledby={titleId}>
            <CardHeader>
                <div>
                    <CardTitle id={titleId}>{t('Một tin mới đi đến team như thế nào?')}</CardTitle>
                    <p className="mt-1 text-sm text-muted-foreground">{t('Theo dấu một tin, khám phá từng đầu ra và hiểu vì sao thông báo được gửi.')}</p>
                </div>
                <span className="text-xs text-faint">{t('Mô phỏng hướng dẫn')}</span>
            </CardHeader>
            <CardBody className="grid min-w-0 gap-4">
                <div className="flex flex-wrap gap-2" role="group" aria-label={t('Chọn tình huống')}>
                    {(Object.keys(scenarioLabels) as Scenario[]).map((key) => {
                        const Icon = scenarioIcons[key];
                        return (
                            <Button
                                key={key}
                                type="button"
                                size="sm"
                                variant={scenario === key ? 'default' : 'secondary'}
                                aria-pressed={scenario === key}
                                onClick={() => selectScenario(key)}
                            >
                                <Icon aria-hidden />
                                {scenarioLabels[key]}
                            </Button>
                        );
                    })}
                </div>
                <div className="flex flex-wrap items-center gap-2" role="group" aria-label={t('Khám phá bằng câu hỏi')}>
                    <span className="mr-1 text-xs text-faint">{t('Bạn muốn biết?')}</span>
                    {(Object.keys(questionLabels) as Question[]).map((key) => (
                        <Button
                            key={key}
                            type="button"
                            size="sm"
                            variant="ghost"
                            className={cn('border-border text-xs', question === key && 'bg-surface-2 text-foreground')}
                            aria-pressed={question === key}
                            onClick={() => ask(key)}
                        >
                            {questionLabels[key]}
                        </Button>
                    ))}
                </div>
                {question && (
                    <p className="rounded-md bg-surface-2 px-3 py-2 text-sm" role="status">
                        {answers[question]}
                    </p>
                )}
                <div className="flex flex-wrap items-center justify-between gap-3 border-y border-border py-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="button" size="sm" onClick={() => (isPlaying ? setIsPlaying(false) : play())}>
                            {isPlaying ? <PauseIcon aria-hidden /> : <PlayIcon aria-hidden />}
                            {isPlaying ? t('Tạm dừng') : atEnd ? t('Phát lại') : t('Chạy mô phỏng')}
                        </Button>
                        <Button
                            type="button"
                            variant="secondary"
                            size="icon-sm"
                            disabled={playhead.index === 0}
                            aria-label={t('Cảnh trước')}
                            onClick={() => {
                                setIsPlaying(false);
                                setOpenNode(null);
                                setSelectedNode(null);
                                seek(playhead.index - 1, 1);
                            }}
                        >
                            <ChevronLeftIcon aria-hidden />
                        </Button>
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            disabled={playhead.index === scenes.length - 1}
                            onClick={() => {
                                setOpenNode(null);
                                setSelectedNode(null);
                                setIsContinuous(false);
                                seek(playhead.index + 1);
                                setIsPlaying(true);
                            }}
                        >
                            {t('Cảnh tiếp')}
                            <ChevronRightIcon aria-hidden />
                        </Button>
                        <Button type="button" variant="secondary" size="icon-sm" aria-label={t('Đặt lại mô phỏng')} onClick={reset}>
                            <RotateCcwIcon aria-hidden />
                        </Button>
                        <Button type="button" variant="ghost" size="sm" aria-label={t('Đổi tốc độ mô phỏng')} onClick={() => setSpeed(speed === 1 ? 2 : 1)}>
                            {speed}×
                        </Button>
                    </div>
                    <span className="text-xs text-faint tabular-nums" aria-live="polite">
                        {t('Cảnh :current / :total', { current: playhead.index + 1, total: scenes.length })}
                    </span>
                </div>
                <figure className="min-w-0">
                    <div className="min-w-0 overflow-x-auto" tabIndex={0} role="region" aria-label={t('Sơ đồ tương tác; cuộn ngang trên màn hình nhỏ')}>
                        <div className="guide-diagram" data-playing={isPlaying} data-reduced-motion={reducedMotion}>
                            <svg viewBox="0 0 1120 648" className="guide-connections" aria-hidden>
                                <defs>
                                    <marker id={markerId} viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto">
                                        <path d="M0 1 9 5 0 9" fill="none" stroke="context-stroke" strokeWidth="1.5" />
                                    </marker>
                                </defs>
                                {sourceNames[0] && <path className="guide-edge" d="M60 122V162Q60 174 72 174H140" />}
                                {sourceNames[2] && <path className="guide-edge" d="M220 122V162Q220 174 208 174H140" />}
                                {Object.entries(routes).map(([id, route]) =>
                                    id === 'retry' && question !== 'retry' ? null : (
                                        <g key={id} className={cn(!pathSet.has(id as keyof typeof routes) && 'guide-unused')}>
                                            <path className={cn('guide-edge', current.path === id && 'is-active')} d={route.line} markerEnd={`url(#${markerId})`} />
                                            <text x={route.labelAt[0]} y={route.labelAt[1]} className="guide-edge-label">
                                                {t(route.label)}
                                            </text>
                                        </g>
                                    ),
                                )}
                                {playhead.index === 0 && <Packet id="N01" point={[140, 160]} />}
                                {current.path && current.path !== 'collect' && playhead.progress < 0.62 && !reducedMotion && (
                                    <Packet id={current.packet} point={pointOnRoute(routes[current.path].points, playhead.progress / 0.62)} bundle={current.packet === 'D01'} />
                                )}
                                {current.path === 'collect' &&
                                    !reducedMotion &&
                                    sourceNames.map((name, i) => {
                                        const progress = (playhead.progress - i * 0.08) / (0.6 - i * 0.08);
                                        if (!name || progress < 0 || progress >= 1) return null;
                                        const source: [number, number][] =
                                            i === 1
                                                ? [
                                                      [140, 122],
                                                      [140, 260],
                                                  ]
                                                : [
                                                      [60 + i * 80, 122],
                                                      [60 + i * 80, 174],
                                                      [140, 174],
                                                      [140, 260],
                                                  ];
                                        const [x, y] = pointOnRoute(source, progress);
                                        return <circle key={i} className="guide-source-packet" cx={x} cy={y} r="5.5" />;
                                    })}
                                {isDigest && current.node === 'digest' && arrived && <Packet id="K02" point={[658, 466]} bundle />}
                            </svg>
                            {Object.keys(positions).map((node) => renderNode(node as NodeId))}
                            <span className="guide-cron">
                                <ClockIcon aria-hidden />
                                <span>cron</span>
                            </span>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-x-5 gap-y-2 border-t border-border py-3 text-xs text-faint">
                        <span className="inline-flex items-center gap-2">
                            <i className="w-6 border-t border-dashed border-faint" />
                            {t('Hướng kết nối')}
                        </span>
                        <span className="inline-flex items-center gap-2">
                            <i className="size-1.5 rounded-full bg-orange" />
                            {t('Gói dữ liệu minh hoạ')}
                        </span>
                        <span>{t('Icon hoạt động khi xử lý')}</span>
                    </div>
                    <div className="min-h-10 border-t border-border py-2 text-sm text-muted-foreground">
                        {hoverNode ? nodeDetails(hoverNode, options, t).summary : t('Hover đọc nhanh · bấm icon để mở giải thích chi tiết.')}
                    </div>
                    <div className="grid gap-1 border-y border-border bg-surface-2 px-4 py-3" aria-live="polite">
                        <p className="font-medium">{current.title}</p>
                        <p className="text-sm text-muted-foreground">{current.caption}</p>
                        <span className="mt-1 inline-flex items-center gap-1.5 text-xs text-faint">
                            <ClockIcon className="size-3.5" aria-hidden />
                            {current.time}
                        </span>
                    </div>
                    <ol className="grid grid-cols-4 gap-3 py-4 text-xs max-sm:grid-cols-2">
                        {[t('Tin có ở nguồn'), t('Thu thập theo lịch'), t('Xét từng đầu ra'), t('Gửi theo chính sách')].map((text, i) => (
                            <li
                                key={text}
                                className={cn(
                                    'border-t-2 border-border pt-2 text-faint',
                                    i <= (playhead.index === 0 ? 0 : playhead.index === 1 ? 1 : current.node === 'team' ? 3 : 2) && 'border-link text-foreground',
                                )}
                            >
                                {text}
                            </li>
                        ))}
                    </ol>
                    <figcaption className="text-xs text-faint">
                        {t('Mô phỏng hướng dẫn · Thứ tự kể không phải thứ tự hoàn thành; khoảng chờ không thể hiện độ trễ thật.')}
                    </figcaption>
                </figure>
                <div className="grid grid-cols-2 gap-6 border-t border-border pt-4 max-lg:grid-cols-1">
                    <div>
                        <div className="flex items-center justify-between gap-3">
                            <h3 className="font-semibold">{detail.title}</h3>
                            {selectedNode && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => {
                                        setSelectedNode(null);
                                        setOpenNode(null);
                                    }}
                                >
                                    {t('Theo cảnh')}
                                </Button>
                            )}
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">{detail.summary}</p>
                        <dl className="mt-3 grid gap-2 text-sm">
                            {detail.facts.map(([label, value]) => (
                                <div key={label} className="grid grid-cols-[90px_minmax(0,1fr)] gap-3">
                                    <dt className="text-faint">{label}</dt>
                                    <dd>{value}</dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                    <div>
                        <h3 className="font-semibold">{t('Mỗi đầu ra có trạng thái riêng')}</h3>
                        <div className="mt-3 grid grid-cols-2 gap-3" aria-live="polite">
                            {(['admin', 'team'] as const).map((branch) => (
                                <div key={branch} className="rounded-md border border-border p-3">
                                    <p className="font-mono text-xs">{branch === 'admin' ? 'A01' : outputId}</p>
                                    <p className="mt-1 text-sm font-medium">
                                        {branch === 'admin' ? t('Kênh admin') : hasTeam ? t('Team Backend · ví dụ') : t('Team · không có người nhận')}
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">{stateLabels[outputs[branch]]}</p>
                                </div>
                            ))}
                        </div>
                        <div className="mt-4 flex gap-2" role="group" aria-label={t('Xem từng đầu ra')}>
                            {(['team', 'admin'] as const).map((branch) => (
                                <Button
                                    key={branch}
                                    type="button"
                                    size="sm"
                                    variant={preview === branch ? 'secondary' : 'ghost'}
                                    aria-pressed={preview === branch}
                                    onClick={() => setPreview(branch)}
                                >
                                    {branch === 'admin' ? t('Kênh admin') : t('Space của team')}
                                </Button>
                            ))}
                        </div>
                        <div className={cn('mt-2 rounded-md border border-border p-3', outputs[preview] !== 'sent' && 'opacity-55')}>
                            <div className="flex items-center gap-2 text-sm font-medium">
                                <MessageSquareTextIcon className="size-4" aria-hidden />
                                PSM Security<span className="ml-auto text-xs font-normal text-faint">{preview === 'admin' ? 'A01' : outputId}</span>
                            </div>
                            <p className="mt-2 text-sm">
                                {preview === 'admin'
                                    ? scenario === 'failure'
                                        ? t('Một nguồn dữ liệu cần được kiểm tra')
                                        : t('Vendor công bố một vấn đề bảo mật')
                                    : isDigest
                                      ? t('Tổng hợp các vấn đề cần theo dõi')
                                      : t('Một vấn đề bảo mật liên quan tới service của team')}
                            </p>
                            <p className="mt-2 text-xs text-faint">
                                {stateLabels[outputs[preview]]} · {t('Ví dụ minh hoạ · không gửi thông báo thật')}
                            </p>
                        </div>
                    </div>
                </div>
            </CardBody>
        </Card>
    );
}
