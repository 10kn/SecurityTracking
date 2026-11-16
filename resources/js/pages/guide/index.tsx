import { Link } from '@inertiajs/react';
import {
    ArrowRightIcon,
    BellRingIcon,
    CalendarClockIcon,
    ChevronDownIcon,
    CircleHelpIcon,
    ClockIcon,
    CpuIcon,
    FileSpreadsheetIcon,
    FolderIcon,
    GitBranchIcon,
    LayoutDashboardIcon,
    NewspaperIcon,
    SearchCheckIcon,
    ServerIcon,
    ShieldAlertIcon,
    SlidersHorizontalIcon,
    UsersIcon,
    ZapIcon,
} from 'lucide-react';
import { Fragment, useId, useState, type ReactNode } from 'react';

import { index as findingsIndex } from '@/actions/App/Http/Controllers/Admin/FindingController';
import { index as incidentsIndex } from '@/actions/App/Http/Controllers/Admin/IncidentController';
import OverviewController from '@/actions/App/Http/Controllers/Admin/OverviewController';
import { index as projectsIndex } from '@/actions/App/Http/Controllers/Admin/ProjectController';
import { index as repositoryConnectionsIndex } from '@/actions/App/Http/Controllers/Admin/RepositoryConnectionController';
import { create as createImport } from '@/actions/App/Http/Controllers/Admin/ProjectImportController';
import { index as servicesIndex } from '@/actions/App/Http/Controllers/Admin/ServiceController';
import { edit as settingsEdit } from '@/actions/App/Http/Controllers/Admin/SystemSettingController';
import { index as teamsIndex } from '@/actions/App/Http/Controllers/Admin/TeamController';
import { index as technologiesIndex } from '@/actions/App/Http/Controllers/Admin/TechnologyController';
import { buttonVariants } from '@/components/ui/button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/admin/card';
import type { VendorNewsSource } from '@/components/admin/guide/workflow-model';
import { WorkflowGuide } from '@/components/admin/guide/workflow';
import { PageHeader } from '@/components/admin/page-header';
import { SeverityBadge } from '@/components/admin/severity-badge';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type ScheduleKey = 'notices' | 'catalog' | 'kev' | 'packages' | 'end_of_life' | 'runner_images' | 'repository_connections' | 'lockfiles' | 'digest' | 'sources';

interface ScheduleRow {
    key: ScheduleKey;
    /** Runs all day at this interval; null for runs at fixed times of day. */
    every_minutes: number | null;
    /** Minutes past the hour an hourly run starts. */
    minute: number;
    /** "HH:MM" in the reader's timezone, for runs at fixed times of day. */
    times: string[];
}

interface SeverityData {
    value: string;
    label: string;
}

interface GuideProps {
    vendor_sources: VendorNewsSource[];
    timezone: string;
    schedule: ScheduleRow[];
    digest: { days: number[]; weekly_day: number };
    policy: {
        immediate_severities: SeverityData[];
        deadlines: { severity: SeverityData; days: number }[];
        lockfile_summary_threshold: number;
        patch_hold_hours: number;
        notice_relevant_days: number;
    };
}

const MINUTES_PER_DAY = 1440;
const AXIS_HOURS = [0, 3, 6, 9, 12, 15, 18, 21, 24];

function minutesOf(time: string): number {
    const [hours, minutes] = time.split(':').map(Number);

    return hours * 60 + minutes;
}

function Section({ id, title, description, children }: { id: string; title: string; description?: string; children: ReactNode }) {
    return (
        <Card id={id} className="mt-10 scroll-mt-20">
            <CardHeader className="gap-3 px-6 py-5 max-sm:px-4">
                <div>
                    <CardTitle className="text-md">{title}</CardTitle>
                    {description && <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>}
                </div>
            </CardHeader>
            <CardBody className="p-6 max-sm:p-4">{children}</CardBody>
        </Card>
    );
}

function StageItem({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div>
            <p className="font-medium text-foreground">{title}</p>
            <p className="mt-0.5">{children}</p>
        </div>
    );
}

/** A day from 00:00 to 24:00 with a mark at each run: a band for runs every few minutes, dots for the others. */
function Track({ row }: { row: ScheduleRow }) {
    const isDigest = row.key === 'digest';

    return (
        <div className="px-6" aria-hidden>
            <div className="relative h-10 border-r border-border">
                {AXIS_HOURS.slice(0, -1).map((hour) => (
                    <span key={hour} className="absolute inset-y-0 border-l border-border" style={{ left: `${(hour / 24) * 100}%` }} />
                ))}
                {row.every_minutes !== null && row.every_minutes < 60 && <span className="absolute inset-x-0 top-3 h-1.5 -translate-y-1/2 rounded-full bg-foreground/25" />}
                {row.every_minutes === 60 &&
                    Array.from({ length: 24 }, (_, hour) => (
                        <span
                            key={hour}
                            className="absolute top-3 size-1.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-foreground/50"
                            style={{ left: `${((hour * 60 + row.minute) / MINUTES_PER_DAY) * 100}%` }}
                        />
                    ))}
                {row.times.map((time) => (
                    <span
                        key={time}
                        className="absolute top-3 flex -translate-x-1/2 -translate-y-1/2 flex-col items-center"
                        style={{ left: `${(minutesOf(time) / MINUTES_PER_DAY) * 100}%` }}
                    >
                        <span className={cn('mt-[18px] size-2.5 rounded-full', isDigest ? 'bg-primary ring-4 ring-primary/15' : 'bg-foreground')} />
                        <span className={cn('mt-1 text-xs tabular-nums', isDigest ? 'font-semibold text-foreground' : 'text-muted-foreground')}>{time}</span>
                    </span>
                ))}
            </div>
        </div>
    );
}

export default function GuideIndex({ vendor_sources = [], timezone, schedule, digest, policy }: GuideProps) {
    const t = useTranslation();
    const [activeStep, setActiveStep] = useState(0);
    const setupId = useId();

    const scheduleText: Record<ScheduleKey, { title: string; description: string }> = {
        notices: { title: t('Tin bảo mật từ vendor'), description: t('php.net, nodejs.org, ruby-lang.org. AI đọc và phân loại từng tin.') },
        catalog: { title: t('Danh mục version'), description: t('endoflife.date và trang phát hành chính thức: bản mới, ngày hết hỗ trợ.') },
        kev: { title: t('CISA KEV'), description: t('Danh sách lỗ hổng đang bị khai thác thực tế.') },
        packages: { title: t('Quét lỗ hổng package'), description: t('Package trong lockfile, đối chiếu với Packagist, RubySec và OSV.') },
        end_of_life: { title: t('Đánh giá lại runtime'), description: t('Phát hiện version vừa hết hỗ trợ khi sang ngày mới.') },
        runner_images: { title: t('Runner GitHub Actions'), description: t('Runner image sắp bị GitHub gỡ.') },
        repository_connections: { title: t('Kiểm tra kết nối repository'), description: t('Token của account dev còn dùng được không, còn bao lâu hết hạn.') },
        lockfiles: { title: t('Lockfile từ GitHub'), description: t('Lockfile và workflow tại commit mới nhất của branch.') },
        digest: { title: t('Bản tin ngày'), description: t('Gửi tới Google Chat của từng team.') },
        sources: { title: t('Kiểm tra nguồn dữ liệu'), description: t('Báo admin khi một nguồn lỗi quá ngưỡng hoặc tin gửi bị kẹt.') },
    };

    const frequency = (row: ScheduleRow): string => {
        if (row.every_minutes === null) {
            return t('Mỗi ngày lúc :times', { times: row.times.join(' · ') });
        }

        if (row.every_minutes !== 60) {
            return t('Mỗi :count phút', { count: row.every_minutes });
        }

        return row.minute === 0 ? t('Mỗi giờ') : t('Mỗi giờ, vào phút :minute', { minute: row.minute });
    };

    const dayLabels = [t('Chủ nhật'), t('Thứ hai'), t('Thứ ba'), t('Thứ tư'), t('Thứ năm'), t('Thứ sáu'), t('Thứ bảy')];
    const digestTime = schedule.find((row) => row.key === 'digest')?.times[0] ?? '';
    const digestDays = digest.days.length === 7 ? t('mỗi ngày') : digest.days.map((day) => dayLabels[day]).join(', ');
    const immediate = policy.immediate_severities;

    const steps = [
        {
            icon: UsersIcon,
            title: t('Tạo team'),
            text: t('Gắn webhook Google Chat của space mà team muốn nhận cảnh báo, rồi gửi tin thử.'),
            link: t('Mở Teams'),
            href: teamsIndex.url(),
        },
        {
            icon: FolderIcon,
            title: t('Tạo project'),
            text: t('Mỗi project thuộc một team. Có nhiều project thì nhập hàng loạt bằng file Excel.'),
            link: t('Mở Projects'),
            href: projectsIndex.url(),
        },
        {
            icon: ServerIcon,
            title: t('Thêm service'),
            text: t('Chọn công nghệ, nhập version đang chạy trên production, rồi chọn repository GitHub hoặc tải lockfile lên.'),
            link: t('Mở Services'),
            href: servicesIndex.url(),
        },
        {
            icon: SearchCheckIcon,
            title: t('Theo dõi kết quả'),
            text: t('Lưu xong, service được đánh giá trong vài giây. Kết quả nằm ở Findings và Sự kiện bảo mật.'),
            link: t('Mở Findings'),
            href: findingsIndex.url(),
        },
    ];

    const routes: { title: string; icon: typeof ZapIcon; mode: string; when: ReactNode; then: string; thenDetail: string; after: string }[] = [
        {
            title: t('Finding theo mức cấu hình'),
            icon: ZapIcon,
            mode: t('Gửi ngay'),
            when: (
                <>
                    <span>{t('Finding mới ở mức')}</span>
                    <span className="mt-1.5 flex flex-wrap gap-1.5">
                        {immediate.length === 0 && <span>{t('Không có mức gửi ngay được cấu hình.')}</span>}
                        {immediate.map((severity) => (
                            <SeverityBadge key={severity.value} severity={severity} />
                        ))}
                    </span>
                </>
            ),
            then: t('Gửi ngay cho team'),
            thenDetail: t('Một tin cho mỗi team và mỗi vấn đề, dù ảnh hưởng nhiều service.'),
            after: t('Nhắc lại trong bản tin mỗi ngày cho tới khi admin đánh dấu Đã đọc.'),
        },
        {
            title: t('Các mức còn lại'),
            icon: CalendarClockIcon,
            mode: t('Bản tin ngày'),
            when: <span>{t('Finding mới ở các mức còn lại')}</span>,
            then: t('Chỉ có trong bản tin ngày'),
            thenDetail: t('Lúc :time :timezone, cùng các finding mới khác của team.', { time: digestTime, timezone }),
            after: t('Không nhắc hằng ngày; vẫn nằm trong bản tổng hợp tuần cho tới khi đóng.'),
        },
        {
            title: t('CISA KEV'),
            icon: ShieldAlertIcon,
            mode: t('Gửi ngay'),
            when: (
                <>
                    <span>{t('Lỗ hổng có trong CISA KEV')}</span>
                    <span className="mt-0.5 block">{t('Đang bị khai thác thực tế.')}</span>
                </>
            ),
            then: t('Nâng mức độ và gửi ngay'),
            thenDetail: t('Finding liên quan được nâng mức và luôn có tin riêng.'),
            after: t('Hạn xử lý được kéo về sớm hơn nếu CISA đặt hạn sớm hơn.'),
        },
        {
            title: t('Tin bảo mật từ vendor'),
            icon: NewspaperIcon,
            mode: t('Gửi ngay'),
            when: (
                <>
                    <span>{t('Tin bảo mật sớm từ vendor')}</span>
                    <span className="mt-0.5 block">{t('Critical, High hoặc chưa rõ mức, đăng trong :count ngày gần đây.', { count: policy.notice_relevant_days })}</span>
                </>
            ),
            then: t('Gửi ngay cho mọi team chạy công nghệ đó'),
            thenDetail: t('Các tin của cùng một lỗ hổng gom thành một sự kiện, mỗi team một tin.'),
            after: t('Cập nhật và tin "Đã có bản vá" vào cùng thread của sự kiện.'),
        },
    ];

    const notes = [
        t('Finding tự đóng khi service được nâng cấp hoặc lockfile không còn package lỗi; không đóng tay được.'),
        t('Khi quá hạn xử lý, finding được nhắc thêm một lần, kể cả đã đánh dấu Đã đọc.'),
        t('Team không có gì mới thì không nhận bản tin.'),
        t('Project đang Ngừng theo dõi không gửi tin nào cho team; service vẫn được quét.'),
        ...(policy.lockfile_summary_threshold > 0
            ? [t('Một service có từ :count package lỗi trong một lần quét chỉ nhận một tin tóm tắt thay vì từng tin.', { count: policy.lockfile_summary_threshold })]
            : []),
        t('Trong lúc chờ vendor xác nhận bản vá, tin "thiếu bản vá" được giữ tối đa :count giờ.', { count: policy.patch_hold_hours }),
    ];

    const dailyWork = [
        { group: 'monitor', icon: LayoutDashboardIcon, title: t('Tổng quan'), text: t('Xem nguồn dữ liệu có lỗi không và việc cần làm hôm nay.'), href: OverviewController.url() },
        {
            group: 'monitor',
            icon: SearchCheckIcon,
            title: t('Findings'),
            text: t('Đánh dấu Đã đọc để dừng nhắc hằng ngày. Finding vẫn mở tới khi service được nâng cấp.'),
            href: findingsIndex.url(),
        },
        {
            group: 'monitor',
            icon: ShieldAlertIcon,
            title: t('Sự kiện bảo mật'),
            text: t('Đánh giá mức ảnh hưởng cho từng team, gộp hoặc tách tin của cùng một lỗ hổng.'),
            href: incidentsIndex.url(),
        },
        { group: 'manage', icon: CpuIcon, title: t('Công nghệ'), text: t('Xem danh mục version của từng công nghệ và đồng bộ ngay khi cần.'), href: technologiesIndex.url() },
        {
            group: 'manage',
            icon: GitBranchIcon,
            title: t('Kết nối repository'),
            text: t('Kết nối GitHub để lấy lockfile và workflow.'),
            detail: t('Repository ở GitHub của khách: tạo token classic (scope repo) từ account dev được khách mời, Authorize SSO nếu cần, rồi thêm kết nối.'),
            href: repositoryConnectionsIndex.url(),
        },
        {
            group: 'manage',
            icon: FileSpreadsheetIcon,
            title: t('Nhập project từ Excel'),
            text: t('Các team điền template, admin tải lên, xem trước rồi xác nhận.'),
            href: createImport.url(),
        },
        {
            group: 'settings',
            icon: SlidersHorizontalIcon,
            title: t('Hệ thống'),
            text: t('Đổi giờ gửi bản tin, mức gửi ngay, hạn xử lý và kênh của admin.'),
            href: settingsEdit.url(),
        },
    ];

    return (
        <>
            <PageHeader title={t('Hướng dẫn sử dụng')} description={t('Hệ thống hoạt động thế nào, khi nào có dữ liệu và khi nào team nhận tin.')} />

            <WorkflowGuide
                vendorSources={vendor_sources}
                schedule={schedule}
                timezone={timezone}
                digestDays={digestDays}
                immediateSeverities={immediate.map((severity) => severity.label)}
                noticeRelevantDays={policy.notice_relevant_days}
            />

            <nav className="mt-6 flex flex-wrap items-center gap-x-6 gap-y-3 border-y border-border py-4 text-sm" aria-label={t('Các phần hướng dẫn')}>
                <span className="text-faint">{t('Đọc theo nhu cầu')}</span>
                {[
                    ['guide-setup', t('Bắt đầu sử dụng')],
                    ['guide-schedule', t('Khi nào có dữ liệu')],
                    ['guide-notifications', t('Khi nào team nhận tin')],
                    ['guide-admin', t('Việc của admin')],
                ].map(([id, label]) => (
                    <a key={id} href={`#${id}`} className="font-medium text-muted-foreground hover:text-link hover:underline">
                        {label}
                    </a>
                ))}
            </nav>

            <Section id="guide-setup" title={t('Bắt đầu sử dụng')} description={t('Thiết lập một lần theo thứ tự này. Chọn bước để xem việc cần làm.')}>
                <div className="grid grid-cols-[minmax(220px,1fr)_minmax(0,2fr)] gap-8 max-lg:grid-cols-1 max-lg:gap-5">
                    <div
                        className="grid content-start gap-2"
                        role="tablist"
                        aria-orientation="vertical"
                        aria-label={t('Các bước thiết lập')}
                        onKeyDown={(event) => {
                            const next =
                                event.key === 'Home'
                                    ? 0
                                    : event.key === 'End'
                                      ? steps.length - 1
                                      : ['ArrowDown', 'ArrowRight'].includes(event.key)
                                        ? (activeStep + 1) % steps.length
                                        : ['ArrowUp', 'ArrowLeft'].includes(event.key)
                                          ? (activeStep + steps.length - 1) % steps.length
                                          : null;
                            if (next !== null) {
                                event.preventDefault();
                                setActiveStep(next);
                                document.getElementById(`${setupId}-tab-${next}`)?.focus();
                            }
                        }}
                    >
                        {steps.map((step, index) => (
                            <button
                                key={step.href}
                                type="button"
                                role="tab"
                                id={`${setupId}-tab-${index}`}
                                aria-controls={`${setupId}-panel-${index}`}
                                aria-selected={activeStep === index}
                                tabIndex={activeStep === index ? 0 : -1}
                                className={cn(
                                    'flex items-center gap-3 rounded-md px-4 py-3 text-left text-muted-foreground hover:bg-surface-2',
                                    activeStep === index && 'bg-primary-soft font-semibold text-link',
                                )}
                                onClick={() => setActiveStep(index)}
                            >
                                <span
                                    className={cn(
                                        'grid size-7 shrink-0 place-items-center rounded-full border border-border text-xs tabular-nums',
                                        activeStep === index && 'border-primary bg-primary text-primary-foreground',
                                    )}
                                >
                                    {index + 1}
                                </span>
                                <span className="flex-1">{step.title}</span>
                                <ArrowRightIcon className="size-4" aria-hidden />
                            </button>
                        ))}
                    </div>
                    {steps.map((step, index) => (
                        <div
                            hidden={activeStep !== index}
                            key={step.href}
                            id={`${setupId}-panel-${index}`}
                            role="tabpanel"
                            aria-labelledby={`${setupId}-tab-${index}`}
                            tabIndex={0}
                            className={cn(
                                'min-h-56 flex-col items-start justify-center gap-5 border-l border-border pl-8 max-lg:border-t max-lg:border-l-0 max-lg:pt-6 max-lg:pl-0',
                                activeStep === index ? 'flex' : 'hidden',
                            )}
                        >
                            <div className="flex items-center gap-3 text-link">
                                <step.icon className="size-7" strokeWidth={1.5} aria-hidden />
                                <span className="text-xs font-medium uppercase tracking-wide">{t('Bước :current / :total', { current: index + 1, total: steps.length })}</span>
                            </div>
                            <div className="max-w-xl">
                                <h3 className="text-xl font-semibold">{step.title}</h3>
                                <p className="mt-3 leading-relaxed text-muted-foreground">{step.text}</p>
                            </div>
                            <div className="flex flex-wrap items-center gap-4">
                                <Link href={step.href} className={buttonVariants({ variant: 'default' })}>
                                    {step.link}
                                    <ArrowRightIcon aria-hidden />
                                </Link>
                                {index < steps.length - 1 && (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setActiveStep(index + 1);
                                            document.getElementById(`${setupId}-tab-${index + 1}`)?.focus();
                                        }}
                                        className="text-sm text-muted-foreground hover:text-foreground"
                                    >
                                        {t('Xem bước tiếp theo')} →
                                    </button>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </Section>

            <Section id="guide-schedule" title={t('Khi nào có dữ liệu')} description={t('Thu thập và gửi tin có lịch riêng. Các mốc dưới đây dùng giờ :timezone.', { timezone })}>
                <div className="grid grid-cols-3 gap-7 max-lg:grid-cols-1">
                    {(['notices', 'packages', 'digest'] as ScheduleKey[]).map((key) => {
                        const row = schedule.find((item) => item.key === key);
                        const Icon = key === 'notices' ? NewspaperIcon : key === 'packages' ? SearchCheckIcon : BellRingIcon;
                        return (
                            row && (
                                <div key={key} className="grid content-start gap-3 border-b border-border pb-6 lg:border-r lg:border-b-0 lg:pr-7 lg:last:border-r-0 lg:last:pr-0">
                                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                        <Icon className="size-4" strokeWidth={1.5} aria-hidden />
                                        {scheduleText[key].title}
                                    </div>
                                    <div className="text-xl font-semibold leading-snug tabular-nums">
                                        {key === 'digest' ? digestTime : row.every_minutes !== null ? frequency(row) : row.times.join(' · ')}
                                    </div>
                                    <p className="text-sm text-faint">
                                        {key === 'digest'
                                            ? t('Gửi theo lịch: :days.', { days: digestDays })
                                            : key === 'notices'
                                              ? t('Đọc tin mới từ nguồn chính thức.')
                                              : t('Đối chiếu package trong lockfile.')}
                                    </p>
                                </div>
                            )
                        );
                    })}
                </div>
                <details className="group/schedule mt-7 rounded-md border border-border">
                    <summary className="flex cursor-pointer list-none items-center justify-between gap-4 px-4 py-4 font-medium [&::-webkit-details-marker]:hidden">
                        <span className="flex flex-wrap items-center gap-2">
                            <ClockIcon className="size-4 text-faint" aria-hidden />
                            {t('Xem toàn bộ lịch chạy')}
                            <span className="text-xs font-normal text-faint">{t(':count tác vụ', { count: schedule.length })}</span>
                        </span>
                        <ChevronDownIcon className="size-4 shrink-0 text-faint transition-transform group-open/schedule:rotate-180" aria-hidden />
                    </summary>
                    <div className="border-t border-border px-4 pb-4">
                        <div className="grid grid-cols-[minmax(0,260px)_minmax(0,1fr)_minmax(0,190px)] items-center gap-x-4 max-lg:grid-cols-1">
                            <span className="max-lg:hidden" />
                            <div className="px-6 max-lg:hidden" aria-hidden>
                                <div className="relative h-6 text-xs text-faint tabular-nums">
                                    {AXIS_HOURS.map((hour) => (
                                        <span key={hour} className="absolute -translate-x-1/2" style={{ left: `${(hour / 24) * 100}%` }}>
                                            {String(hour).padStart(2, '0')}
                                        </span>
                                    ))}
                                </div>
                            </div>
                            <span className="max-lg:hidden" />
                            {schedule.map((row) => (
                                <Fragment key={row.key}>
                                    <div className="border-t border-border py-3 max-lg:pb-1">
                                        <p className="font-medium">{scheduleText[row.key].title}</p>
                                        <p className="mt-0.5 text-sm text-muted-foreground">{scheduleText[row.key].description}</p>
                                    </div>
                                    <div className="border-border py-3 lg:border-t max-lg:py-1">
                                        <Track row={row} />
                                    </div>
                                    <p className="border-border py-3 text-sm text-muted-foreground tabular-nums lg:border-t max-lg:pt-0">{frequency(row)}</p>
                                </Fragment>
                            ))}
                        </div>
                    </div>
                </details>
                <div className="mt-7 grid grid-cols-3 gap-7 text-sm text-muted-foreground max-lg:grid-cols-1">
                    <StageItem title={t('Ngay khi lưu service')}>{t('Đổi version hoặc lockfile thì service đó được đánh giá lại trong vài giây, không chờ lịch.')}</StageItem>
                    <StageItem title={t('Đồng bộ thủ công')}>{t('Nút "Lấy lockfile ngay" ở trang service và "Đồng bộ ngay" ở trang Công nghệ.')}</StageItem>
                    <StageItem title={t('Khi một nguồn lỗi')}>
                        {t('Runtime và package phụ thuộc nguồn đó hiện "Thiếu dữ liệu", không bao giờ hiện Đạt. Admin được báo và trang Tổng quan nêu nguồn lỗi.')}
                    </StageItem>
                </div>
            </Section>

            <Section id="guide-notifications" title={t('Khi nào team nhận tin')} description={t('Tìm loại tin của bạn, rồi xem cách gửi và quy tắc theo dõi.')}>
                <div className="grid grid-cols-2 gap-5 max-lg:grid-cols-1">
                    {routes.map((route) => (
                        <div key={route.title} className="rounded-lg border border-border p-5">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <span className="flex items-center gap-2 font-semibold">
                                    <route.icon className="size-4 text-muted-foreground" strokeWidth={1.5} aria-hidden />
                                    {route.title}
                                </span>
                                <span className="rounded bg-surface-2 px-2 py-1 text-xs font-medium text-link">{route.mode}</span>
                            </div>
                            <div className="mt-4 text-sm text-muted-foreground">{route.when}</div>
                            <p className="mt-4 font-medium">{route.then}</p>
                            <details className="group/rule mt-4 border-t border-border pt-3">
                                <summary className="flex cursor-pointer list-none items-center justify-between gap-3 text-sm text-faint [&::-webkit-details-marker]:hidden">
                                    {t('Chi tiết & theo dõi')}
                                    <ChevronDownIcon className="size-3.5 transition-transform group-open/rule:rotate-180" aria-hidden />
                                </summary>
                                <div className="grid gap-3 pt-4 text-sm leading-relaxed text-muted-foreground">
                                    <p>{route.thenDetail}</p>
                                    <p>{route.after}</p>
                                </div>
                            </details>
                        </div>
                    ))}
                </div>
                <div className="mt-8 border-t border-border pt-6">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 className="font-semibold">{t('Hạn xử lý')}</h3>
                        <p className="text-sm text-faint">{t('Tính từ lúc finding được phát hiện.')}</p>
                    </div>
                    <dl className="mt-5 grid grid-cols-4 gap-6 max-sm:grid-cols-2">
                        {policy.deadlines.map((deadline) => (
                            <div key={deadline.severity.value} className="grid gap-3">
                                <dt>
                                    <SeverityBadge severity={deadline.severity} />
                                </dt>
                                <dd className="flex items-baseline gap-2">
                                    <span className="text-2xl font-semibold tabular-nums">{deadline.days}</span>
                                    <span className="text-sm text-faint">{t('ngày')}</span>
                                </dd>
                            </div>
                        ))}
                    </dl>
                </div>
                <details className="group/notes mt-7 rounded-md bg-surface-2 px-4 py-4">
                    <summary className="flex cursor-pointer list-none items-center justify-between gap-3 font-medium [&::-webkit-details-marker]:hidden">
                        <span className="flex items-center gap-2">
                            <CircleHelpIcon className="size-4 text-faint" aria-hidden />
                            {t('Quy tắc nhắc lại & ngoại lệ')}
                        </span>
                        <ChevronDownIcon className="size-4 shrink-0 text-faint transition-transform group-open/notes:rotate-180" aria-hidden />
                    </summary>
                    <div className="pt-4">
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            {t('Bản tin ngày gửi lúc :time :timezone, :days. :weekday là bản tổng hợp tuần: mọi thứ còn mở và những gì đã đóng trong 7 ngày qua.', {
                                time: digestTime,
                                timezone,
                                days: digestDays,
                                weekday: dayLabels[digest.weekly_day] ?? '',
                            })}
                        </p>
                        <ul className="mt-3 grid list-disc gap-1.5 pl-5 text-sm text-muted-foreground">
                            {notes.map((note) => (
                                <li key={note}>{note}</li>
                            ))}
                        </ul>
                    </div>
                </details>
            </Section>

            <Section id="guide-admin" title={t('Việc của admin')} description={t('Chọn trang theo việc bạn cần làm.')}>
                <div className="grid grid-cols-3 gap-8 max-lg:grid-cols-1">
                    {[
                        { key: 'monitor', title: t('Theo dõi mỗi ngày'), icon: LayoutDashboardIcon },
                        { key: 'manage', title: t('Quản lý danh mục'), icon: FolderIcon },
                        { key: 'settings', title: t('Cấu hình hệ thống'), icon: SlidersHorizontalIcon },
                    ].map((group) => (
                        <div key={group.key}>
                            <h3 className="mb-2 flex items-center gap-2 text-sm font-semibold text-faint">
                                <group.icon className="size-4" strokeWidth={1.5} aria-hidden />
                                {group.title}
                            </h3>
                            <ul className="divide-y divide-border">
                                {dailyWork
                                    .filter((item) => item.group === group.key)
                                    .map((item) => (
                                        <li key={item.href} className="py-4">
                                            <Link href={item.href} className="group flex items-start gap-3">
                                                <item.icon className="mt-0.5 size-4 shrink-0 text-faint" strokeWidth={1.5} aria-hidden />
                                                <span className="min-w-0 flex-1">
                                                    <span className="font-medium group-hover:text-link group-hover:underline">{item.title}</span>
                                                    <span className="mt-2 block text-sm leading-relaxed text-muted-foreground">{item.text}</span>
                                                </span>
                                                <ArrowRightIcon className="mt-1 size-3.5 shrink-0 text-faint" aria-hidden />
                                            </Link>
                                            {item.detail && (
                                                <details className="group/repository mt-3 pl-7 text-sm">
                                                    <summary className="flex cursor-pointer list-none items-center justify-between gap-2 text-faint [&::-webkit-details-marker]:hidden">
                                                        {t('Lưu ý khi kết nối GitHub')}
                                                        <ChevronDownIcon className="size-3.5 shrink-0 transition-transform group-open/repository:rotate-180" aria-hidden />
                                                    </summary>
                                                    <p className="pt-3 leading-relaxed text-muted-foreground">{item.detail}</p>
                                                </details>
                                            )}
                                        </li>
                                    ))}
                            </ul>
                        </div>
                    ))}
                </div>
            </Section>
        </>
    );
}
