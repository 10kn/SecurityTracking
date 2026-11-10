import { Link, router, useForm } from '@inertiajs/react';
import { CheckIcon, ExternalLinkIcon, GitMergeIcon, SendIcon } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

import { index as findingsIndex } from '@/actions/App/Http/Controllers/Admin/FindingController';
import { store as acknowledge } from '@/actions/App/Http/Controllers/Admin/IncidentAcknowledgementController';
import { index, show } from '@/actions/App/Http/Controllers/Admin/IncidentController';
import { store as merge } from '@/actions/App/Http/Controllers/Admin/IncidentMergeController';
import { store as decide } from '@/actions/App/Http/Controllers/Admin/IncidentSuggestionDecisionController';
import { store as sendUpdate } from '@/actions/App/Http/Controllers/Admin/IncidentUpdateController';
import { store as split } from '@/actions/App/Http/Controllers/Admin/NoticeSplitController';
import { Card, CardBody, CardFooter, CardHeader, CardTitle } from '@/components/admin/card';
import { ConfirmButton } from '@/components/admin/confirm-button';
import { EmptyState, Table, Td, Th, Tr } from '@/components/admin/data-table';
import { Field } from '@/components/admin/form-section';
import { HelpTip } from '@/components/admin/help-tip';
import { FixStatus, type IncidentSummary } from '@/components/admin/incidents/fix-status';
import { PageHeader } from '@/components/admin/page-header';
import { SeverityBadge } from '@/components/admin/severity-badge';
import { StatusDot } from '@/components/admin/status-dot';
import { withTechnologyName } from '@/components/admin/technology-logo';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input, inputClassName } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Option, StatusDotData } from '@/types';

type Severity = { value: string; label: string };

interface Notice {
    id: number;
    title: string;
    url: string | null;
    severity: Severity;
    published: string | null;
    affected_branches: string[];
    unaffected_branches: string[];
    patch_versions: string[];
    is_exploited: boolean;
    exploited_excerpt: string | null;
    known_exploited: KnownExploited[];
    is_dismissed: boolean;
    is_classified: boolean;
    basis: string | null;
    severity_basis: string | null;
}

interface KnownExploited {
    cve: string;
    added: string;
    due: string | null;
    listed: boolean;
}

interface Suggestion {
    id: number;
    reason: string;
    is_incoming: boolean;
    notice: { id: number; title: string; url: string | null; severity: Severity; published: string | null };
    source: { id: number; title: string } | null;
    target: { id: number; title: string; is_current: boolean };
    created: string | null;
}

type MessageStatus = StatusDotData & { at: string | null };

interface Delivery {
    opener: MessageStatus | null;
    latest: MessageStatus | null;
    last_sent: string | null;
    failures_count: number;
    pending_count: number;
    failures: { id: number; error: string; failed: string | null; command: string | null }[];
    limit: string | null;
}

interface TeamRow {
    id: number;
    name: string;
    services: { name: string; branch: string; versions: string[]; scope: string; scope_hint: string; is_not_affected: boolean; patch: string | null; is_patched: boolean }[];
    told_severity: Severity | null;
    is_told: boolean;
    is_actionable: boolean;
    needs_acknowledgement: boolean;
    thread: { status: StatusDotData; url: string | null; is_old: boolean };
    delivery: Delivery | null;
    assessment: { impact: { value: string; label: string }; note: string | null; by: string | null; at: string | null; is_valid: boolean } | null;
    exposure_hash: string;
    revision: number;
    history: { id: number; type: string; detail: string; by: string | null; at: string | null }[];
}

interface RelatedFinding {
    id: number;
    title: string;
    rule: string;
    severity: Severity;
    service: string | null;
    team: string | null;
    state: StatusDotData;
    recorded_version: string | null;
    recorded_at: string | null;
    target_version: string | null;
    incident_fix: string | null;
    is_resolved: boolean;
    resolved: string | null;
}

interface Props {
    incident: IncidentSummary & {
        planned_release: string | null;
        affected_branches: string[];
        unaffected_branches: string[];
        severity_basis: string | null;
        is_exploited: boolean;
        known_exploited: KnownExploited[];
        merged_from: { id: number; title: string }[];
        not_told_reason: string | null;
        lead_notice: {
            summary: { text: string; is_ai: boolean } | null;
            url: string | null;
            classifier: StatusDotData | null;
        } | null;
    };
    notices: Notice[];
    suggestions: Suggestion[];
    teams: TeamRow[];
    findings: { items: RelatedFinding[]; total: number; open_ids: number[]; open_count: number };
    focusTeam: number | null;
    mergeTargets: Option<number>[];
    update: { request_id: string; recipients: Option<number>[] };
}

type Draft = { impact: string; note: string };

export default function IncidentsShow({ incident, notices, suggestions, teams, findings, focusTeam, mergeTargets, update }: Props) {
    const t = useTranslation();
    const title = incident.lead ?? incident.title;

    return (
        <>
            <PageHeader
                title={title}
                breadcrumbs={[{ label: t('Sự kiện bảo mật'), href: index.url() }, { label: `#${incident.id}` }]}
                description={withTechnologyName(t('#:id · :technology · :count tin', { id: incident.id, technology: incident.technology.label, count: incident.notices_count }), incident.technology)}
                actions={<MergeDialog incidentId={incident.id} targets={mergeTargets} />}
            />
            <Card className="mb-6">
                <CardBody>
                    {incident.lead_notice && <LeadNotice notice={incident.lead_notice} isDismissed={incident.is_dismissed} />}
                    <dl className="grid grid-cols-3 gap-x-6 gap-y-5 max-lg:grid-cols-2 max-sm:grid-cols-1">
                        {incident.is_dismissed ? <Detail label={t('Đăng lúc')}>{incident.first_published ?? '—'}</Detail> : <SecurityDetails incident={incident} />}
                        {incident.merged_from.length > 0 && (
                            <Detail label={t('Đã gộp vào đây')}>{incident.merged_from.map((merged) => `#${merged.id} ${merged.title}`).join(' · ')}</Detail>
                        )}
                    </dl>
                </CardBody>
            </Card>
            <Teams incidentId={incident.id} teams={teams} focusTeam={focusTeam} notToldReason={incident.not_told_reason} />
            <RelatedFindings findings={findings} />
            <Notices incidentId={incident.id} notices={notices} />
            {suggestions.length > 0 && <Suggestions incidentId={incident.id} suggestions={suggestions} />}
            <UrgentUpdate incidentId={incident.id} update={update} />
        </>
    );
}

/**
 * What a security incident is about: severity, fix, branches and planned release. An incident whose every pre-notice
 * is not security news has none of them, so its page leaves them out rather than showing them empty.
 */
function SecurityDetails({ incident }: { incident: Props['incident'] }) {
    const t = useTranslation();

    return (
        <>
            <Detail
                label={t('Mức cảnh báo')}
                hint={t(
                    'Mức cao nhất trong các tin của sự kiện, không tính tin được xếp là “Không phải tin bảo mật”. Mỗi tin lấy mức theo thứ tự ưu tiên: CISA KEV, rồi điểm CVSS hãng ghi trong tin, rồi phân loại bởi AI. Nguồn của mức ghi ngay bên cạnh.',
                )}
            >
                <SeverityBadge severity={incident.severity} />
                {incident.severity_basis && <span className="ml-2 text-sm text-muted-foreground">{incident.severity_basis}</span>}
                {incident.is_exploited && <StatusDot status={{ label: t('Đang bị khai thác'), tone: 'danger' }} className="ml-2" />}
            </Detail>
            <Detail
                label={t('Bản vá')}
                hint={t(
                    'Bản vá hãng xác nhận cho từng nhánh. Nhánh team đang chạy mà chưa có: “chờ bản vá” khi hãng ghi rõ nhánh bị ảnh hưởng, “chưa rõ” khi hãng không nêu nhánh đó, nên có thể không bị ảnh hưởng. Nhánh đã chạy bản bảo mật ra từ lúc sự kiện bắt đầu trở đi (hoặc mới hơn) không còn chờ.',
                )}
            >
                <FixStatus incident={incident} />
            </Detail>
            <Detail label={t('Đăng lúc')}>{incident.first_published ?? '—'}</Detail>
            <Detail
                label={t('Nhánh bị ảnh hưởng')}
                hint={t('Các nhánh mà tin trên trang chính thức ghi rõ là bị ảnh hưởng. Trống khi tin không nêu nhánh nào; danh sách bản vá không được tính là danh sách nhánh.')}
            >
                <span className="font-mono">{incident.affected_branches.join(', ') || '—'}</span>
            </Detail>
            <Detail
                label={t('Nhánh không ảnh hưởng')}
                hint={t('Các nhánh mà tin trên trang chính thức ghi rõ là không bị ảnh hưởng. Nhánh đã hết hỗ trợ (EOL) không bao giờ được loại trừ.')}
            >
                <span className="font-mono">{incident.unaffected_branches.join(', ') || '—'}</span>
            </Detail>
            <Detail label={t('Dự kiến phát hành')}>{incident.planned_release ?? '—'}</Detail>
            {incident.known_exploited.length > 0 && (
                <Detail
                    label="CISA KEV"
                    hint={t('Danh sách lỗ hổng đang bị khai thác thực tế do CISA (Mỹ) công bố. CVE CISA đã gỡ khỏi danh sách vẫn được giữ lại ở đây.')}
                >
                    <KnownExploitedList entries={incident.known_exploited} />
                </Detail>
            )}
        </>
    );
}

/**
 * The lead pre-notice explained: the AI summary or the source's, labelled as such, its source, and the classifier when
 * it has not read the pre-notice or failed to. An incident that is not security news says so.
 */
function LeadNotice({ notice, isDismissed }: { notice: NonNullable<Props['incident']['lead_notice']>; isDismissed: boolean }) {
    const t = useTranslation();

    return (
        <div className="mb-5 border-b border-border pb-5">
            <p className="text-sm text-muted-foreground">{notice.summary === null ? t('Chưa có tóm tắt') : notice.summary.is_ai ? t('Tóm tắt do AI tạo') : t('Tóm tắt của nguồn')}</p>
            {notice.summary && <p className="mt-1 max-w-[80ch] whitespace-pre-line">{notice.summary.text}</p>}
            {(isDismissed || notice.classifier || notice.url) && (
                <p className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                    {isDismissed && (
                        <span className="inline-flex items-center gap-1">
                            <span className="inline-flex h-6 items-center rounded-[5px] bg-neutral-soft px-2 text-xs font-medium whitespace-nowrap">{t('Tin tức, không phải tin bảo mật')}</span>
                            <HelpTip label={t('Tin tức, không phải tin bảo mật')}>
                                {t('Mọi tin của sự kiện này đều được xếp là không phải tin bảo mật, nên không có mức độ, nhánh bị ảnh hưởng hay bản vá, và không còn được báo cho team. Thẻ đã gửi trước khi tin được xếp lại vẫn còn trong Google Chat.')}
                            </HelpTip>
                        </span>
                    )}
                    {notice.classifier && <StatusDot status={notice.classifier} />}
                    {notice.url && <ExternalLink href={notice.url}>{t('Xem nguồn')}</ExternalLink>}
                </p>
            )}
        </div>
    );
}

function Detail({ label, hint, children }: { label: string; hint?: string; children: ReactNode }) {
    return (
        <div>
            <dt className="flex items-center gap-1 text-sm text-muted-foreground">
                {label}
                {hint && <HelpTip label={label}>{hint}</HelpTip>}
            </dt>
            <dd className="mt-1">{children}</dd>
        </div>
    );
}

function ExternalLink({ href, children }: { href: string; children: ReactNode }) {
    return (
        <a href={href} target="_blank" rel="noreferrer noopener" className="inline-flex items-center gap-1 font-medium text-link hover:underline hover:underline-offset-3">
            {children}
            <ExternalLinkIcon className="size-3.5" aria-hidden />
        </a>
    );
}

/** Merges this incident into another current one of the same technology, after asking which. */
function MergeDialog({ incidentId, targets }: { incidentId: number; targets: Option<number>[] }) {
    const t = useTranslation();
    const [isOpen, setIsOpen] = useState(false);
    const form = useForm<{ into_id: string }>({ into_id: '' });

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(open) => {
                setIsOpen(open);

                if (!open) {
                    form.resetAndClearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button variant="secondary" disabled={targets.length === 0}>
                    <GitMergeIcon />
                    {t('Gộp vào sự kiện khác')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.submit(merge(incidentId), { preserveScroll: true, onSuccess: () => setIsOpen(false) });
                    }}
                    noValidate
                >
                    <DialogHeader>
                        <DialogTitle>{t('Gộp vào sự kiện khác')}</DialogTitle>
                        <DialogDescription>{t('Mọi tin của sự kiện này chuyển sang sự kiện được chọn. Team đã nhận tin không bị báo lại.')}</DialogDescription>
                    </DialogHeader>
                    <DialogBody>
                        <Field label={t('Sự kiện giữ lại')} htmlFor="into_id" required error={form.errors.into_id}>
                            <NativeSelect id="into_id" value={form.data.into_id} onChange={(event) => form.setData('into_id', event.target.value)} aria-invalid={form.errors.into_id !== undefined}>
                                <option value="">{t('Chọn sự kiện')}</option>
                                {targets.map((target) => (
                                    <option key={target.value} value={target.value}>
                                        {target.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                    </DialogBody>
                    <DialogFooter>
                        <Button type="button" variant="secondary" onClick={() => setIsOpen(false)}>
                            {t('Hủy')}
                        </Button>
                        <Button type="submit" loading={form.processing} disabled={form.data.into_id === ''}>
                            {t('Gộp')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Pending suggestions, collapsed below what the incident asks of the teams: accepting moves only the suggested
 * pre-notice, from the incident it is shown in.
 */
function Suggestions({ incidentId, suggestions }: { incidentId: number; suggestions: Suggestion[] }) {
    const t = useTranslation();
    const [isOpen, setIsOpen] = useState(false);

    return (
        <Card className="mb-6">
            <CardHeader className={cn(!isOpen && 'border-b-0')}>
                <CardTitle>
                    {t('Gợi ý gộp')} <span className="ml-1 text-sm font-normal text-faint">{suggestions.length}</span>
                </CardTitle>
                <Button variant="ghost" size="sm" aria-expanded={isOpen} onClick={() => setIsOpen((open) => !open)}>
                    {isOpen ? t('Ẩn') : t('Xem gợi ý')}
                </Button>
            </CardHeader>
            {isOpen && (
                <Table className="min-w-[860px]">
                    <thead>
                        <tr>
                            <Th>{t('Tin')}</Th>
                            <Th>{t('Gợi ý')}</Th>
                            <Th>{t('Lý do')}</Th>
                            <Th />
                        </tr>
                    </thead>
                    <tbody>
                        {suggestions.map((suggestion) => (
                            <Tr key={suggestion.id}>
                                <Td className="max-w-[360px]">
                                    <span className="font-medium">{suggestion.notice.title}</span>
                                    <span className="mt-0.5 block text-sm text-muted-foreground">
                                        {suggestion.notice.severity.label}
                                        {suggestion.notice.published && ` · ${suggestion.notice.published}`}
                                    </span>
                                </Td>
                                <Td className="text-sm">
                                    {suggestion.is_incoming ? (
                                        <>
                                            {t('Chuyển vào sự kiện này từ')}{' '}
                                            {suggestion.source ? (
                                                <Link href={show.url(suggestion.source.id)} className="font-medium text-link hover:underline">
                                                    #{suggestion.source.id} {suggestion.source.title}
                                                </Link>
                                            ) : (
                                                '—'
                                            )}
                                        </>
                                    ) : (
                                        <>
                                            {t('Chuyển sang')}{' '}
                                            <Link href={show.url(suggestion.target.id)} className="font-medium text-link hover:underline">
                                                #{suggestion.target.id} {suggestion.target.title}
                                            </Link>
                                        </>
                                    )}
                                </Td>
                                <Td className="text-sm text-muted-foreground">{suggestion.reason}</Td>
                                <Td align="right" className="whitespace-nowrap">
                                    <div className="inline-flex gap-1.5">
                                        <ConfirmButton
                                            variant="secondary"
                                            size="sm"
                                            title={t('Chuyển tin vào sự kiện #:id?', { id: suggestion.target.id })}
                                            description={t('Chỉ tin “:notice” được chuyển; các tin khác của sự kiện đang chứa nó giữ nguyên.', { notice: suggestion.notice.title })}
                                            confirmLabel={t('Gộp')}
                                            disabled={!suggestion.target.is_current || suggestion.source === null}
                                            onConfirm={() =>
                                                router.post(decide.url(suggestion.id), { decision: 'accept', incident_id: suggestion.source?.id ?? incidentId, target_id: suggestion.target.id }, { preserveScroll: true })
                                            }
                                        >
                                            {t('Gộp')}
                                        </ConfirmButton>
                                        <Button variant="ghost" size="sm" onClick={() => router.post(decide.url(suggestion.id), { decision: 'reject' }, { preserveScroll: true })}>
                                            {t('Bỏ qua')}
                                        </Button>
                                    </div>
                                </Td>
                            </Tr>
                        ))}
                    </tbody>
                </Table>
            )}
        </Card>
    );
}

function draftsOf(teams: TeamRow[]): Record<number, Draft> {
    return Object.fromEntries(teams.map((team) => [team.id, { impact: team.assessment?.impact.value ?? '', note: team.assessment?.note ?? '' }]));
}

/**
 * Each team told about the incident or running its technology. Teams told that still run it can be marked as read with
 * their impact, one at a time or the selected ones together; each row posts the exposure and revision it shows, so a
 * team whose exposure changed or that another admin assessed meanwhile is not saved.
 */
function Teams({ incidentId, teams, focusTeam, notToldReason }: { incidentId: number; teams: TeamRow[]; focusTeam: number | null; notToldReason: string | null }) {
    const t = useTranslation();
    const [drafts, setDrafts] = useState<Record<number, Draft>>(() => draftsOf(teams));
    const [selected, setSelected] = useState<number[]>([]);
    const [bulk, setBulk] = useState<Draft>({ impact: '', note: '' });
    const [isSaving, setIsSaving] = useState(false);
    const actionable = teams.filter((team) => team.is_actionable);
    const isAllSelected = actionable.length > 0 && actionable.every((team) => selected.includes(team.id));
    const impacts = [
        { value: 'yes', label: t('Có') },
        { value: 'no', label: t('Không') },
        { value: 'unknown', label: t('Chưa rõ') },
    ];

    // What the server sends after a save replaces the drafts, and a team no longer actionable leaves the selection.
    useEffect(() => {
        setDrafts(draftsOf(teams));
        setSelected((ids) => ids.filter((id) => teams.some((team) => team.id === id && team.is_actionable)));
    }, [teams]);

    const setDraft = (teamId: number, changes: Partial<Draft>) => setDrafts((current) => ({ ...current, [teamId]: { ...current[teamId], ...changes } }));
    // The impact and note picked for the selection go to each selected team, also one selected afterwards, which then posts them as its own.
    const applyToSelected = (changes: Partial<Draft>) => {
        setBulk((current) => ({ ...current, ...changes }));
        selected.forEach((id) => setDraft(id, changes));
    };
    const bulkValues = (): Partial<Draft> => Object.fromEntries(Object.entries(bulk).filter(([, value]) => value !== '')) as Partial<Draft>;
    const select = (ids: number[]) => {
        ids.filter((id) => !selected.includes(id)).forEach((id) => setDraft(id, bulkValues()));
        setSelected(ids);
    };
    const draftOf = (id: number, overrides: Partial<Draft>): Draft => ({ ...(drafts[id] ?? { impact: '', note: '' }), ...overrides });
    const isReady = (ids: number[], overrides: Partial<Draft> = {}) => ids.length > 0 && ids.every((id) => draftOf(id, overrides).impact !== '');

    const save = (ids: number[], overrides: Partial<Draft> = {}) =>
        router.post(
            acknowledge.url(incidentId),
            {
                teams: teams
                    .filter((team) => ids.includes(team.id))
                    .map((team) => ({ id: team.id, ...draftOf(team.id, overrides), exposure_hash: team.exposure_hash, revision: team.revision })),
            },
            {
                preserveScroll: true,
                onStart: () => setIsSaving(true),
                onFinish: () => setIsSaving(false),
                onSuccess: () => {
                    setSelected([]);
                    setBulk({ impact: '', note: '' });
                },
            },
        );

    return (
        <Card className="mb-6">
            <CardHeader>
                <CardTitle>
                    {t('Team')} <span className="ml-1 text-sm font-normal text-faint">{teams.length}</span>
                </CardTitle>
                {selected.length > 0 && (
                    <div className="flex flex-wrap items-center gap-2">
                        <NativeSelect
                            aria-label={t('Ảnh hưởng cho các team đã chọn')}
                            value={bulk.impact}
                            onChange={(event) => applyToSelected({ impact: event.target.value })}
                            className="h-[30px] border-border pl-2.5 text-sm"
                        >
                            <option value="">{t('Ảnh hưởng cho các team đã chọn')}</option>
                            {impacts.map((impact) => (
                                <option key={impact.value} value={impact.value}>
                                    {impact.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <Input
                            aria-label={t('Ghi chú cho các team đã chọn')}
                            placeholder={t('Ghi chú chung (không bắt buộc)')}
                            value={bulk.note}
                            maxLength={1000}
                            onChange={(event) => applyToSelected({ note: event.target.value })}
                            className="h-[30px] w-[220px] px-2.5 text-sm"
                        />
                        <Button size="sm" loading={isSaving} disabled={!isReady(selected, bulkValues())} onClick={() => save(selected, bulkValues())}>
                            <CheckIcon />
                            {t('Đánh dấu đã đọc (:count)', { count: selected.length })}
                        </Button>
                    </div>
                )}
            </CardHeader>
            {notToldReason && <p className="border-b border-border px-5 py-3 text-sm text-muted-foreground max-sm:px-3.5">{notToldReason}</p>}
            {teams.length === 0 ? (
                <EmptyState title={t('Chưa có team nào chạy công nghệ này hoặc được báo')} />
            ) : (
                <Table className="min-w-[1120px]">
                    <thead>
                        <tr>
                            <Th className="w-10 pr-0">
                                <Checkbox
                                    aria-label={t('Chọn mọi team đánh giá được')}
                                    checked={isAllSelected}
                                    disabled={actionable.length === 0}
                                    onCheckedChange={(checked) => select(checked === true ? actionable.map((team) => team.id) : [])}
                                />
                            </Th>
                            <Th>{t('Team')}</Th>
                            <Th>{t('Service')}</Th>
                            <Th>
                                {t('Đã báo')} <HelpTip label={t('Đã báo')}>{t('Mức độ đã báo cho team qua Google Chat.')}</HelpTip>
                            </Th>
                            <Th>
                                {t('Thread')} <HelpTip label={t('Thread')}>{t('“Đã gửi” nghĩa là Google Chat đã nhận tin, không có nghĩa là đã có người đọc.')}</HelpTip>
                            </Th>
                            <Th>
                                {t('Đánh giá')}{' '}
                                <HelpTip label={t('Đánh giá')}>
                                    {t(
                                        'Có, Không và Chưa rõ đều dừng nhắc hằng ngày. “Không” còn ngừng nhắc team về sự kiện này và bỏ nó khỏi danh sách “Đang chờ bản vá”. Đánh giá hết hiệu lực khi tin mới, phạm vi, mức độ hoặc bản vá thay đổi.',
                                    )}
                                </HelpTip>
                            </Th>
                            <Th>{t('Đánh dấu đã đọc')}</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {teams.map((team) => (
                            <Tr key={team.id} className={cn('align-top', focusTeam === team.id && 'bg-surface-2')}>
                                <Td className="pr-0 align-top">
                                    {team.is_actionable && (
                                        <Checkbox
                                            aria-label={t('Chọn :team', { team: team.name })}
                                            checked={selected.includes(team.id)}
                                            onCheckedChange={(checked) => select(checked === true ? [...selected, team.id] : selected.filter((id) => id !== team.id))}
                                        />
                                    )}
                                </Td>
                                <Td className="align-top">
                                    <span className="font-medium">{team.name}</span>
                                    {team.needs_acknowledgement && <StatusDot status={{ label: t('Chờ đánh giá, nhắc hằng ngày'), tone: 'warning' }} className="mt-0.5 flex" />}
                                </Td>
                                <Td className="align-top text-sm">
                                    {team.services.length === 0
                                        ? '—'
                                        : team.services.map((service) => (
                                              <span key={`${service.name}-${service.branch}`} className={cn('block', (service.is_not_affected || service.is_patched) && 'text-muted-foreground')}>
                                                  <span className="font-medium">{service.name}</span> ·{' '}
                                                  <span className="font-mono">{service.versions.length > 0 ? service.versions.join(', ') : service.branch}</span> ·{' '}
                                                  <span className="whitespace-nowrap">
                                                      {service.scope} <HelpTip label={service.scope}>{service.scope_hint}</HelpTip>
                                                  </span>
                                                  {service.patch && (service.is_patched ? <> · {t('đã vá')}</> : <span className="font-mono"> · {t('cần lên :version', { version: service.patch })}</span>)}
                                              </span>
                                          ))}
                                </Td>
                                <Td className="align-top text-sm">{team.told_severity ? <SeverityBadge severity={team.told_severity} /> : <span className="text-muted-foreground">{t('Chưa báo')}</span>}</Td>
                                <Td className="max-w-[300px] align-top">
                                    <StatusDot status={team.thread.status} />
                                    {team.thread.url && (
                                        <span className="mt-0.5 block text-sm">
                                            <ExternalLink href={team.thread.url}>{team.thread.is_old ? t('Mở thread cũ') : t('Mở thread')}</ExternalLink>
                                        </span>
                                    )}
                                    {team.delivery && <DeliveryStatus delivery={team.delivery} />}
                                </Td>
                                <Td className="max-w-[260px] align-top text-sm">
                                    <Assessment team={team} />
                                </Td>
                                <Td className="align-top">
                                    {team.is_actionable ? (
                                        <div className="grid w-[240px] gap-1.5">
                                            <NativeSelect
                                                aria-label={t('Ảnh hưởng tới :team', { team: team.name })}
                                                value={drafts[team.id]?.impact ?? ''}
                                                onChange={(event) => setDraft(team.id, { impact: event.target.value })}
                                                className="h-[30px] pl-2.5 text-sm"
                                            >
                                                <option value="">{t('Ảnh hưởng?')}</option>
                                                {impacts.map((impact) => (
                                                    <option key={impact.value} value={impact.value}>
                                                        {impact.label}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                            <Input
                                                aria-label={t('Ghi chú cho :team', { team: team.name })}
                                                placeholder={t('Ghi chú (không bắt buộc)')}
                                                value={drafts[team.id]?.note ?? ''}
                                                maxLength={1000}
                                                onChange={(event) => setDraft(team.id, { note: event.target.value })}
                                                className="h-[30px] px-2.5 text-sm"
                                            />
                                            <Button variant="secondary" size="sm" disabled={isSaving || !isReady([team.id])} onClick={() => save([team.id])}>
                                                {team.assessment ? t('Lưu đánh giá') : t('Đã đọc')}
                                            </Button>
                                        </div>
                                    ) : (
                                        <span className="text-sm text-muted-foreground">{team.is_told ? t('Chỉ xem') : t('Chưa được báo')}</span>
                                    )}
                                </Td>
                            </Tr>
                        ))}
                    </tbody>
                </Table>
            )}
        </Card>
    );
}

/**
 * What Google Chat accepted of the team's current thread. "Đã gửi" means Google Chat accepted the message, not that anyone
 * read it; a failure stays until that message is sent again, whatever was delivered after it.
 */
function DeliveryStatus({ delivery }: { delivery: Delivery }) {
    const t = useTranslation();

    return (
        <span className="mt-1.5 block text-sm">
            {delivery.opener && (
                <span className="block">
                    <span className="text-muted-foreground">{t('Tin mở thread')}: </span>
                    <StatusDot status={delivery.opener} />
                </span>
            )}
            {delivery.latest && (
                <span className="block">
                    <span className="text-muted-foreground">{t('Tin xếp gửi gần nhất')}: </span>
                    <StatusDot status={delivery.latest} />
                </span>
            )}
            {delivery.limit ? (
                <span className="block text-muted-foreground">{delivery.limit}</span>
            ) : (
                <span className="block text-muted-foreground">
                    {delivery.last_sent ? t('Gửi thành công gần nhất: :time', { time: delivery.last_sent }) : t('Chưa gửi thành công tin nào trong thread này')}
                </span>
            )}
            {delivery.pending_count > 0 && <span className="block text-muted-foreground">{t('Đang chờ gửi: :count', { count: delivery.pending_count })}</span>}
            {delivery.failures.length > 0 && (
                <span className="mt-1 block">
                    <span className="block text-danger-text">{t('Gửi lỗi chưa gửi lại: :count', { count: delivery.failures_count })}</span>
                    {delivery.failures.map((failure) => (
                        <span key={failure.id} className="mt-0.5 block text-muted-foreground">
                            #{failure.id}
                            {failure.failed && ` · ${failure.failed}`}
                            {failure.error && ` · ${failure.error}`}
                            {failure.command ? (
                                <code className="mt-0.5 block font-mono text-xs break-all text-foreground">{failure.command}</code>
                            ) : (
                                <span className="mt-0.5 block">{t('Không gửi lại: admin đã đánh giá Không cho team này.')}</span>
                            )}
                        </span>
                    ))}
                </span>
            )}
        </span>
    );
}

function Assessment({ team }: { team: TeamRow }) {
    const t = useTranslation();
    const [isHistoryOpen, setIsHistoryOpen] = useState(false);

    return (
        <>
            {team.assessment ? (
                <>
                    <StatusDot
                        status={{ label: team.assessment.impact.label, tone: team.assessment.is_valid ? (team.assessment.impact.value === 'no' ? 'neutral' : 'warning') : 'unknown' }}
                    />
                    {!team.assessment.is_valid && <span className="mt-0.5 block text-danger-text">{t('Hết hiệu lực: sự kiện đã đổi so với lúc đánh giá')}</span>}
                    {team.assessment.note && <span className="mt-0.5 block">{team.assessment.note}</span>}
                    <span className="mt-0.5 block text-muted-foreground">{team.assessment.by ? t(':user · :time', { user: team.assessment.by, time: team.assessment.at ?? '' }) : team.assessment.at}</span>
                </>
            ) : (
                <span className="text-muted-foreground">{t('Chưa đánh giá')}</span>
            )}
            {team.history.length > 0 && (
                <>
                    <Button variant="link" size="sm" className="mt-1 h-auto text-sm" onClick={() => setIsHistoryOpen((open) => !open)}>
                        {isHistoryOpen ? t('Ẩn lịch sử') : t('Lịch sử (:count)', { count: team.history.length })}
                    </Button>
                    {isHistoryOpen && (
                        <ul className="mt-1 grid gap-1 text-muted-foreground">
                            {team.history.map((event) => (
                                <li key={event.id}>
                                    <span className="text-foreground">{event.type}</span>
                                    {event.detail && ` · ${event.detail}`}
                                    <span className="block text-xs">{[event.by, event.at].filter(Boolean).join(' · ')}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </>
            )}
        </>
    );
}

/**
 * The findings linked to the incident, open ones first. The versions are those of the finding's last evaluation, kept
 * apart from the incident's own fix. The findings list shows open findings only, so resolved ones are only listed here.
 */
function RelatedFindings({ findings }: { findings: Props['findings'] }) {
    const t = useTranslation();
    const [isExpanded, setIsExpanded] = useState(false);
    const shown = isExpanded ? findings.items : findings.items.slice(0, 5);

    return (
        <Card className="mb-6">
            <CardHeader>
                <CardTitle>
                    {t('Finding liên quan')} <span className="ml-1 text-sm font-normal text-faint">{findings.total}</span>
                </CardTitle>
                {findings.open_ids.length > 0 && (
                    <Link href={findingsIndex.url({ query: { ids: findings.open_ids.join(',') } })} className="text-sm font-medium text-link hover:underline">
                        {findings.open_count > findings.open_ids.length
                            ? t('Mở :count trên :total finding đang mở', { count: findings.open_ids.length, total: findings.open_count })
                            : t('Mở :count finding đang mở', { count: findings.open_ids.length })}
                    </Link>
                )}
            </CardHeader>
            {findings.items.length === 0 ? (
                <EmptyState title={t('Chưa có finding nào gắn với sự kiện này')} description={t('Chỉ tính finding nhắc tới tin hoặc bản vá của sự kiện; không đoán theo công nghệ hay phiên bản.')} />
            ) : (
                <>
                    <Table className="min-w-[860px]">
                        <thead>
                            <tr>
                                <Th>{t('Service')}</Th>
                                <Th>{t('Finding')}</Th>
                                <Th>{t('Trạng thái')}</Th>
                                <Th>{t('Phiên bản')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {shown.map((finding) => (
                                <Tr key={finding.id} className="align-top">
                                    <Td className="align-top text-sm">
                                        <span className="font-medium">{finding.service ?? '—'}</span>
                                        {finding.team && <span className="mt-0.5 block text-muted-foreground">{finding.team}</span>}
                                    </Td>
                                    <Td className="max-w-[380px] align-top text-sm">
                                        <span className="font-medium">{finding.title}</span>
                                        <span className="mt-0.5 block text-muted-foreground">
                                            {finding.rule} · {finding.severity.label}
                                        </span>
                                    </Td>
                                    <Td className="align-top text-sm whitespace-nowrap">
                                        <StatusDot status={finding.state} />
                                        {finding.resolved && <span className="mt-0.5 block text-muted-foreground tabular-nums">{finding.resolved}</span>}
                                    </Td>
                                    <Td className="align-top text-sm">
                                        {finding.recorded_version && (
                                            <span className="block">
                                                <span className="font-mono">{finding.recorded_version}</span>{' '}
                                                <span className="text-muted-foreground">{t('tại lần ghi nhận :time', { time: finding.recorded_at ?? '—' })}</span>
                                            </span>
                                        )}
                                        {finding.target_version && (
                                            <span className="mt-0.5 block text-muted-foreground">
                                                {finding.is_resolved ? t('Từng yêu cầu nâng lên :version', { version: finding.target_version }) : t('Cần nâng lên :version', { version: finding.target_version })}
                                            </span>
                                        )}
                                        {finding.incident_fix && <span className="mt-0.5 block text-muted-foreground">{t('Bản vá của sự kiện: :version', { version: finding.incident_fix })}</span>}
                                        {!finding.recorded_version && !finding.target_version && !finding.incident_fix && <span className="text-muted-foreground">—</span>}
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                    {(findings.items.length > 5 || findings.total > findings.items.length) && (
                        <CardFooter>
                            {findings.items.length > 5 && (
                                <Button variant="link" size="sm" className="h-auto px-0 text-sm" onClick={() => setIsExpanded((expanded) => !expanded)}>
                                    {isExpanded ? t('Thu gọn') : t('Xem thêm (:count)', { count: findings.items.length - 5 })}
                                </Button>
                            )}
                            {findings.total > findings.items.length && <span>{t('Chỉ hiện :shown trên :total finding', { shown: findings.items.length, total: findings.total })}</span>}
                        </CardFooter>
                    )}
                </>
            )}
        </Card>
    );
}

/** The incident's pre-notices, oldest first; one that shares the incident can be split off into a new one. */
function Notices({ incidentId, notices }: { incidentId: number; notices: Notice[] }) {
    const t = useTranslation();

    return (
        <Card className="mb-6">
            <CardHeader>
                <CardTitle>
                    {t('Tin của sự kiện')} <span className="ml-1 text-sm font-normal text-faint">{notices.length}</span>
                </CardTitle>
            </CardHeader>
            <Table className="min-w-[960px]">
                <thead>
                    <tr>
                        <Th>{t('Mức')}</Th>
                        <Th>{t('Tin')}</Th>
                        <Th>{t('Đăng lúc')}</Th>
                        <Th>{t('Phạm vi')}</Th>
                        <Th>{t('Bản vá')}</Th>
                        <Th />
                    </tr>
                </thead>
                <tbody>
                    {notices.map((notice) => (
                        <Tr key={notice.id} className={cn('align-top', notice.is_dismissed && 'text-muted-foreground')}>
                            <Td className="align-top">
                                <SeverityBadge severity={notice.severity} />
                            </Td>
                            <Td className="max-w-[380px] align-top">
                                {notice.url ? <ExternalLink href={notice.url}>{notice.title}</ExternalLink> : <span className="font-medium">{notice.title}</span>}
                                <span className="mt-0.5 block text-sm text-muted-foreground">
                                    {[notice.basis, notice.severity_basis, notice.is_dismissed ? (notice.is_classified ? t('Không phải tin bảo mật') : t('AI chưa phân loại tin này')) : null].filter(Boolean).join(' · ')}
                                </span>
                                {notice.is_exploited && (
                                    <span className="mt-0.5 block text-sm">
                                        <StatusDot status={{ label: t('Đang bị khai thác'), tone: 'danger' }} />
                                        {notice.exploited_excerpt && <span className="block text-muted-foreground">“{notice.exploited_excerpt}”</span>}
                                    </span>
                                )}
                                {notice.known_exploited.length > 0 && (
                                    <span className="mt-0.5 block text-sm">
                                        <StatusDot status={{ label: 'CISA KEV', tone: 'danger' }} />
                                        <KnownExploitedList entries={notice.known_exploited} />
                                    </span>
                                )}
                            </Td>
                            <Td className="align-top text-sm whitespace-nowrap text-muted-foreground tabular-nums">{notice.published ?? '—'}</Td>
                            <Td className="align-top text-sm">
                                {notice.affected_branches.length > 0 && <span className="block">{t('Ảnh hưởng: :branches', { branches: notice.affected_branches.join(', ') })}</span>}
                                {notice.unaffected_branches.length > 0 && (
                                    <span className="block text-muted-foreground">{t('Không ảnh hưởng: :branches', { branches: notice.unaffected_branches.join(', ') })}</span>
                                )}
                                {notice.affected_branches.length === 0 && notice.unaffected_branches.length === 0 && <span className="text-muted-foreground">{t('Hãng chưa nêu')}</span>}
                            </Td>
                            <Td className="align-top font-mono text-sm">{notice.patch_versions.join(', ') || '—'}</Td>
                            <Td align="right" className="align-top">
                                {notices.length > 1 && (
                                    <ConfirmButton
                                        variant="ghost"
                                        size="sm"
                                        title={t('Tách tin thành sự kiện mới?')}
                                        description={t('Tin “:notice” thành một sự kiện riêng, chưa được đánh giá cho team nào. Team đã nhận tin sẽ nhận cập nhật sau của nó trong thread mới.', { notice: notice.title })}
                                        confirmLabel={t('Tách ra')}
                                        onConfirm={() => router.post(split.url(notice.id), { incident_id: incidentId }, { preserveScroll: true })}
                                    >
                                        {t('Tách ra')}
                                    </ConfirmButton>
                                )}
                            </Td>
                        </Tr>
                    ))}
                </tbody>
            </Table>
        </Card>
    );
}

/**
 * An urgent update for the teams told about the incident that still run its technology, all picked by default. The
 * confirmation lists the exact recipients. The request id stays the same while an update is retried, so it is never
 * sent twice; once it is sent, the form starts over with the fresh id of the reloaded page, so the next update is a new
 * request.
 */
function UrgentUpdate({ incidentId, update }: { incidentId: number; update: Props['update'] }) {
    const t = useTranslation();
    const [isConfirming, setIsConfirming] = useState(false);
    const form = useForm<{ message: string; team_ids: number[]; request_id: string }>({
        message: '',
        team_ids: update.recipients.map((recipient) => recipient.value),
        request_id: update.request_id,
    });
    const recipients = update.recipients.filter((recipient) => form.data.team_ids.includes(recipient.value));
    const defaultRecipients = update.recipients.map((recipient) => recipient.value);

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Cập nhật khẩn')}</CardTitle>
            </CardHeader>
            <CardBody>
                {update.recipients.length === 0 ? (
                    <p className="text-muted-foreground">{t('Chưa team nào được báo về sự kiện này.')}</p>
                ) : (
                    <div className="grid gap-[18px]">
                        <Field
                            label={t('Nội dung')}
                            htmlFor="update-message"
                            required
                            error={form.errors.message}
                            help={t('Gửi vào thread của sự kiện ở từng team. Không đánh dấu đã đọc cho team nào.')}
                        >
                            <textarea
                                id="update-message"
                                rows={4}
                                maxLength={2000}
                                value={form.data.message}
                                onChange={(event) => form.setData('message', event.target.value)}
                                aria-invalid={form.errors.message !== undefined}
                                className={cn(inputClassName, 'h-auto py-2')}
                            />
                        </Field>
                        <Field label={t('Gửi tới')} error={form.errors.team_ids ?? form.errors['team_ids.0']}>
                            <div className="flex flex-wrap gap-x-5 gap-y-2">
                                {update.recipients.map((recipient) => (
                                    <label key={recipient.value} className="inline-flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={form.data.team_ids.includes(recipient.value)}
                                            onCheckedChange={(checked) =>
                                                form.setData('team_ids', checked === true ? [...form.data.team_ids, recipient.value] : form.data.team_ids.filter((id) => id !== recipient.value))
                                            }
                                        />
                                        {recipient.label}
                                    </label>
                                ))}
                            </div>
                        </Field>
                        <div>
                            <Button disabled={form.data.message.trim() === '' || recipients.length === 0} loading={form.processing} onClick={() => setIsConfirming(true)}>
                                <SendIcon />
                                {t('Gửi cập nhật')}
                            </Button>
                        </div>
                    </div>
                )}
            </CardBody>
            <AlertDialog open={isConfirming} onOpenChange={setIsConfirming}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('Gửi cập nhật tới :count team?', { count: recipients.length })}</AlertDialogTitle>
                        <AlertDialogDescription>{recipients.map((recipient) => recipient.label).join(', ')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Hủy')}</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => {
                                setIsConfirming(false);
                                form.submit(sendUpdate(incidentId), {
                                    preserveScroll: true,
                                    // Only an update the server confirmed under this id starts the form over, with the fresh id of the reloaded page; any other answer keeps the draft and its id for the retry.
                                    onSuccess: (page) => {
                                        if (page.flash.incidentUpdate?.request_id === form.data.request_id) {
                                            form.setData({ message: '', team_ids: defaultRecipients, request_id: (page.props as unknown as Props).update.request_id });
                                        }
                                    },
                                });
                            }}
                        >
                            {t('Gửi')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </Card>
    );
}

// CISA saw these CVEs exploited in the wild: it says nothing about a given service, and a CVE it does not list is not safe for that.
function KnownExploitedList({ entries }: { entries: KnownExploited[] }) {
    const t = useTranslation();

    return (
        <span className="block text-sm text-muted-foreground">
            {entries.map((entry) => (
                <span key={entry.cve} className="block">
                    <span className="font-mono text-foreground">{entry.cve}</span>
                    {' · '}
                    {entry.due ? t('CISA thêm ngày :added, hạn :due', { added: entry.added, due: entry.due }) : t('CISA thêm ngày :added', { added: entry.added })}
                    {!entry.listed && ` · ${t('CISA đã gỡ khỏi danh sách')}`}
                </span>
            ))}
        </span>
    );
}
