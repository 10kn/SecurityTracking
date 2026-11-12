import { Link } from '@inertiajs/react';
import { ArrowRightIcon } from 'lucide-react';
import { Fragment, type ReactNode } from 'react';

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
import { Card, CardBody, CardHeader, CardTitle } from '@/components/admin/card';
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

function Section({ title, description, children }: { title: string; description?: string; children: ReactNode }) {
    return (
        <Card className="mt-6">
            <CardHeader>
                <div>
                    <CardTitle>{title}</CardTitle>
                    {description && <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>}
                </div>
            </CardHeader>
            <CardBody>{children}</CardBody>
        </Card>
    );
}

/** The arrow between two boxes of a diagram: to the right, or down once the boxes stack. */
function FlowArrow({ className }: { className?: string }) {
    return <ArrowRightIcon className={cn('size-4 shrink-0 self-center justify-self-center text-faint max-lg:rotate-90', className)} strokeWidth={1.8} aria-hidden />;
}

/** One box of the "how it works" diagram: a numbered title and what happens there. */
function Stage({ step, title, children }: { step: number; title: string; children: ReactNode }) {
    return (
        <div className="rounded-lg border border-border p-4">
            <p className="flex items-center gap-2 font-semibold">
                <span className="grid size-5 shrink-0 place-items-center rounded-full bg-surface-2 text-xs text-muted-foreground tabular-nums">{step}</span>
                {title}
            </p>
            <div className="mt-3 grid gap-3 text-sm text-muted-foreground">{children}</div>
        </div>
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
            <div
                className="relative h-10 border-r border-border"
                style={{ backgroundImage: 'linear-gradient(to right, var(--border) 1px, transparent 1px)', backgroundSize: '12.5% 100%' }}
            >
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
                    <span key={time} className="absolute top-3 flex -translate-x-1/2 -translate-y-1/2 flex-col items-center" style={{ left: `${(minutesOf(time) / MINUTES_PER_DAY) * 100}%` }}>
                        <span className={cn('mt-[18px] size-2.5 rounded-full', isDigest ? 'bg-primary ring-4 ring-primary/15' : 'bg-foreground')} />
                        <span className={cn('mt-1 text-xs tabular-nums', isDigest ? 'font-semibold text-foreground' : 'text-muted-foreground')}>{time}</span>
                    </span>
                ))}
            </div>
        </div>
    );
}

export default function GuideIndex({ timezone, schedule, digest, policy }: GuideProps) {
    const t = useTranslation();

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
        { title: t('Tạo team'), text: t('Gắn webhook Google Chat của space mà team muốn nhận cảnh báo, rồi gửi tin thử.'), link: t('Mở Teams'), href: teamsIndex.url() },
        { title: t('Tạo project'), text: t('Mỗi project thuộc một team. Có nhiều project thì nhập hàng loạt bằng file Excel.'), link: t('Mở Projects'), href: projectsIndex.url() },
        { title: t('Thêm service'), text: t('Chọn công nghệ, nhập version đang chạy trên production, rồi chọn repository GitHub hoặc tải lockfile lên.'), link: t('Mở Services'), href: servicesIndex.url() },
        { title: t('Theo dõi kết quả'), text: t('Lưu xong, service được đánh giá trong vài giây. Kết quả nằm ở Findings và Sự kiện bảo mật.'), link: t('Mở Findings'), href: findingsIndex.url() },
    ];

    const routes: { when: ReactNode; then: string; thenDetail: string; after: string }[] = [
        {
            when: (
                <>
                    <span className="font-medium text-foreground">{t('Finding mới ở mức')}</span>
                    <span className="mt-1.5 flex flex-wrap gap-1.5">
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
            when: <span className="font-medium text-foreground">{t('Finding mới ở các mức còn lại')}</span>,
            then: t('Chỉ có trong bản tin ngày'),
            thenDetail: t('Lúc :time :timezone, cùng các finding mới khác của team.', { time: digestTime, timezone }),
            after: t('Không nhắc hằng ngày; vẫn nằm trong bản tổng hợp tuần cho tới khi đóng.'),
        },
        {
            when: (
                <>
                    <span className="font-medium text-foreground">{t('Lỗ hổng có trong CISA KEV')}</span>
                    <span className="mt-0.5 block">{t('Đang bị khai thác thực tế.')}</span>
                </>
            ),
            then: t('Nâng mức độ và gửi ngay'),
            thenDetail: t('Finding liên quan được nâng mức và luôn có tin riêng.'),
            after: t('Hạn xử lý được kéo về sớm hơn nếu CISA đặt hạn sớm hơn.'),
        },
        {
            when: (
                <>
                    <span className="font-medium text-foreground">{t('Tin bảo mật sớm từ vendor')}</span>
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
        { title: t('Tổng quan'), text: t('Xem nguồn dữ liệu có lỗi không và việc cần làm hôm nay.'), href: OverviewController.url() },
        { title: t('Findings'), text: t('Đánh dấu Đã đọc để dừng nhắc hằng ngày. Finding vẫn mở tới khi service được nâng cấp.'), href: findingsIndex.url() },
        { title: t('Sự kiện bảo mật'), text: t('Đánh giá mức ảnh hưởng cho từng team, gộp hoặc tách tin của cùng một lỗ hổng.'), href: incidentsIndex.url() },
        { title: t('Công nghệ'), text: t('Xem danh mục version của từng công nghệ và đồng bộ ngay khi cần.'), href: technologiesIndex.url() },
        {
            title: t('Kết nối repository'),
            text: t('Repository ở GitHub của khách: tạo token classic (scope repo) từ account dev được khách mời, Authorize SSO nếu cần, rồi thêm kết nối.'),
            href: repositoryConnectionsIndex.url(),
        },
        { title: t('Nhập project từ Excel'), text: t('Các team điền template, admin tải lên, xem trước rồi xác nhận.'), href: createImport.url() },
        { title: t('Hệ thống'), text: t('Đổi giờ gửi bản tin, mức gửi ngay, hạn xử lý và kênh của admin.'), href: settingsEdit.url() },
    ];

    return (
        <>
            <PageHeader title={t('Hướng dẫn sử dụng')} description={t('Hệ thống hoạt động thế nào, khi nào có dữ liệu và khi nào team nhận tin.')} />

            <Section title={t('Hệ thống hoạt động thế nào')} description={t('Bạn khai báo service đang chạy gì; hệ thống tự đối chiếu với các nguồn bảo mật và báo cho team sở hữu.')}>
                <div className="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)_auto_minmax(0,1fr)_auto_minmax(0,1fr)] gap-3 max-lg:grid-cols-1">
                    <Stage step={1} title={t('Dữ liệu vào')}>
                        <StageItem title={t('Bạn khai báo')}>{t('Team và webhook Google Chat, project, service với version runtime và lockfile.')}</StageItem>
                        <StageItem title={t('Hệ thống tự lấy')}>{t('Danh mục version, tin bảo mật của vendor, cơ sở dữ liệu lỗ hổng package, CISA KEV, lockfile từ GitHub.')}</StageItem>
                    </Stage>
                    <FlowArrow />
                    <Stage step={2} title={t('Đối chiếu')}>
                        <StageItem title={t('Runtime')}>{t('Version đang chạy so với danh mục: hết hỗ trợ, thiếu bản vá bảo mật.')}</StageItem>
                        <StageItem title={t('Package')}>{t('Từng package trong lockfile so với các lỗ hổng đã công bố.')}</StageItem>
                        <StageItem title={t('Tin vendor')}>{t('AI đọc tin, xác định mức độ, nhánh bị ảnh hưởng và bản cần nâng lên.')}</StageItem>
                    </Stage>
                    <FlowArrow />
                    <Stage step={3} title={t('Kết quả')}>
                        <StageItem title={t('Findings')}>{t('Một vấn đề của một service, có mức độ và hạn xử lý.')}</StageItem>
                        <StageItem title={t('Sự kiện bảo mật')}>{t('Các tin của cùng một lỗ hổng, kèm những team bị ảnh hưởng.')}</StageItem>
                    </Stage>
                    <FlowArrow />
                    <Stage step={4} title={t('Thông báo qua Google Chat')}>
                        <StageItem title={t('Gửi ngay')}>{t('Vấn đề nghiêm trọng, tới space của team sở hữu.')}</StageItem>
                        <StageItem title={t('Bản tin ngày')}>{t('Phần còn lại, gom một tin mỗi ngày cho mỗi team.')}</StageItem>
                        <StageItem title={t('Space của admin')}>{t('Lỗi hệ thống và mọi tin bảo mật của vendor.')}</StageItem>
                    </Stage>
                </div>
            </Section>

            <Section title={t('Bắt đầu sử dụng')} description={t('Bốn bước để một service được theo dõi.')}>
                <ol className="grid grid-cols-4 gap-3 max-lg:grid-cols-2 max-sm:grid-cols-1">
                    {steps.map((step, index) => (
                        <li key={step.href} className="flex flex-col rounded-lg border border-border p-4">
                            <p className="flex items-center gap-2 font-semibold">
                                <span className="grid size-5 shrink-0 place-items-center rounded-full bg-primary text-xs text-primary-foreground tabular-nums">{index + 1}</span>
                                {step.title}
                            </p>
                            <p className="mt-2 flex-1 text-sm text-muted-foreground">{step.text}</p>
                            <Link href={step.href} className="mt-3 inline-flex items-center gap-1 text-sm font-medium text-link hover:underline">
                                {step.link}
                                <ArrowRightIcon className="size-3.5" aria-hidden />
                            </Link>
                        </li>
                    ))}
                </ol>
            </Section>

            <Section title={t('Khi nào có dữ liệu')} description={t('Lịch chạy trong một ngày, theo giờ :timezone. Mỗi dấu là một lần chạy.', { timezone })}>
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
                <div className="mt-5 grid grid-cols-3 gap-3 border-t border-border pt-5 text-sm text-muted-foreground max-lg:grid-cols-1">
                    <StageItem title={t('Ngay khi lưu service')}>{t('Đổi version hoặc lockfile thì service đó được đánh giá lại trong vài giây, không chờ lịch.')}</StageItem>
                    <StageItem title={t('Lấy tay khi cần')}>{t('Nút "Lấy lockfile ngay" ở trang service và "Đồng bộ ngay" ở trang Công nghệ.')}</StageItem>
                    <StageItem title={t('Khi một nguồn lỗi')}>{t('Runtime và package phụ thuộc nguồn đó hiện "Thiếu dữ liệu", không bao giờ hiện Đạt. Admin được báo và trang Tổng quan nêu nguồn lỗi.')}</StageItem>
                </div>
            </Section>

            <Section title={t('Khi nào team nhận tin')} description={t('Mức độ quyết định tin được gửi ngay hay chờ bản tin ngày.')}>
                <div className="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)_auto_minmax(0,1fr)] gap-x-3 gap-y-3 text-sm text-muted-foreground max-lg:grid-cols-1">
                    <p className="text-xs font-medium text-faint max-lg:hidden">{t('Điều gì xảy ra')}</p>
                    <span className="max-lg:hidden" />
                    <p className="text-xs font-medium text-faint max-lg:hidden">{t('Hệ thống làm gì')}</p>
                    <span className="max-lg:hidden" />
                    <p className="text-xs font-medium text-faint max-lg:hidden">{t('Sau đó')}</p>
                    {routes.map((route, index) => (
                        <Fragment key={index}>
                            <div className={cn('rounded-lg border border-border p-3.5', index > 0 && 'max-lg:mt-4')}>{route.when}</div>
                            <FlowArrow />
                            <div className="rounded-lg border border-border bg-surface-2 p-3.5">
                                <p className="font-medium text-foreground">{route.then}</p>
                                <p className="mt-0.5">{route.thenDetail}</p>
                            </div>
                            <FlowArrow />
                            <div className="rounded-lg border border-dashed border-border-strong p-3.5">{route.after}</div>
                        </Fragment>
                    ))}
                </div>

                <div className="mt-6 grid grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] gap-8 border-t border-border pt-5 max-lg:grid-cols-1 max-lg:gap-5">
                    <div>
                        <h3 className="font-semibold">{t('Hạn xử lý')}</h3>
                        <p className="mt-0.5 text-sm text-muted-foreground">{t('Tính từ lúc finding được phát hiện.')}</p>
                        <dl className="mt-3 grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-6 gap-y-2">
                            {policy.deadlines.map((deadline) => (
                                <Fragment key={deadline.severity.value}>
                                    <dt>
                                        <SeverityBadge severity={deadline.severity} />
                                    </dt>
                                    <dd className="text-sm tabular-nums">{t(':count ngày', { count: deadline.days })}</dd>
                                </Fragment>
                            ))}
                        </dl>
                    </div>
                    <div>
                        <h3 className="font-semibold">{t('Cần biết thêm')}</h3>
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
                </div>
            </Section>

            <Section title={t('Việc của admin')} description={t('Mỗi trang dùng để làm gì.')}>
                <ul className="grid grid-cols-2 gap-x-8 max-lg:grid-cols-1">
                    {dailyWork.map((item) => (
                        <li key={item.href} className="border-b border-border py-3 last:border-b-0 lg:nth-last-[2]:border-b-0">
                            <Link href={item.href} className="group flex items-start justify-between gap-4">
                                <span className="min-w-0">
                                    <span className="font-medium group-hover:underline">{item.title}</span>
                                    <span className="mt-0.5 block text-sm text-muted-foreground">{item.text}</span>
                                </span>
                                <ArrowRightIcon className="mt-1 size-4 shrink-0 text-faint group-hover:text-foreground" aria-hidden />
                            </Link>
                        </li>
                    ))}
                </ul>
            </Section>
        </>
    );
}
