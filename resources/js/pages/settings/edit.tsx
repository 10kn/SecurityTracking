import { useForm } from '@inertiajs/react';
import { RotateCcwIcon } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import { update } from '@/actions/App/Http/Controllers/Admin/SystemSettingController';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/admin/card';
import { Combobox } from '@/components/admin/combobox';
import { EmptyState, Table, Td, Th } from '@/components/admin/data-table';
import { Field, FormActions, FormFields, FormSection } from '@/components/admin/form-section';
import { PageHeader } from '@/components/admin/page-header';
import { AdminWebhookField, type AdminWebhookData } from '@/components/admin/settings/admin-webhook-field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useTranslation } from '@/lib/i18n';
import type { Option } from '@/types';

interface SettingValues {
    alert_locale: string;
    scheduler_heartbeat_url: string | null;
    digest_time: string;
    digest_days: number[];
    digest_timezone: string;
    digest_weekly_day: number;
    eol_warning_days: number | '';
    eol_reminder_days: number[];
    immediate_severities: string[];
    immediate_dev_dependencies: boolean;
    lockfile_summary_threshold: number | '';
    runtime_eol_severity: string;
    runtime_outdated_security_severity: string;
    kev_severity: string;
    deadline_critical_days: number | '';
    deadline_high_days: number | '';
    deadline_medium_days: number | '';
    deadline_low_days: number | '';
    notice_relevant_days: number | '';
    notice_collect_days: number | '';
    classifier_model: string;
    classifier_effort: string;
    classifier_alert_after_minutes: number | '';
    incident_patch_hold_hours: number | '';
    incident_review_after_days: number | '';
    incident_awaiting_patch_days: number | '';
    incident_window_before_hours: number | '';
    incident_window_after_hours: number | '';
    github_sync_time: string;
    github_sync_timezone: string;
    catalog_stale_after_minutes: number | '';
    notice_stale_after_minutes: number | '';
    kev_stale_after_minutes: number | '';
    packages_stale_after_minutes: number | '';
    github_stale_after_minutes: number | '';
    alert_stuck_after_minutes: number | '';
}

type SettingName = keyof SettingValues;
type Unit = 'days' | 'hours' | 'minutes';

interface Props {
    settings: SettingValues;
    defaults: SettingValues;
    changed: Partial<Record<SettingName, { by: string | null; at: string | null }>>;
    webhooks: { admin: AdminWebhookData; news: AdminWebhookData };
    history: { id: number; key: string; old_value: unknown; new_value: unknown; user: string | null; at: string | null }[];
    options: {
        locales: Option<string>[];
        severities: Option<string>[];
        timezones: string[];
        classifier_models: string[];
        classifier_efforts: string[];
    };
}

const UNITS: Partial<Record<SettingName, Unit>> = {
    eol_warning_days: 'days',
    eol_reminder_days: 'days',
    deadline_critical_days: 'days',
    deadline_high_days: 'days',
    deadline_medium_days: 'days',
    deadline_low_days: 'days',
    notice_relevant_days: 'days',
    notice_collect_days: 'days',
    classifier_alert_after_minutes: 'minutes',
    incident_patch_hold_hours: 'hours',
    incident_review_after_days: 'days',
    incident_awaiting_patch_days: 'days',
    incident_window_before_hours: 'hours',
    incident_window_after_hours: 'hours',
    catalog_stale_after_minutes: 'minutes',
    notice_stale_after_minutes: 'minutes',
    kev_stale_after_minutes: 'minutes',
    packages_stale_after_minutes: 'minutes',
    github_stale_after_minutes: 'minutes',
    alert_stuck_after_minutes: 'minutes',
};

/** Days of the week as PHP numbers them (0 = Sunday), listed from Monday. */
const WEEK = [1, 2, 3, 4, 5, 6, 0];

/**
 * The settings of config/psm.php an admin changes here instead of the .env (App\Services\SystemSettings). Each field
 * says when it differs from the .env and can go back to it; a value equal to the .env one is not stored.
 */
export default function SettingsEdit({ settings, defaults, changed, webhooks, history, options }: Props) {
    const t = useTranslation();
    const form = useForm<SettingValues>(settings);
    const [reminderDaysText, setReminderDaysText] = useState(settings.eol_reminder_days.join(', '));

    const labels: Record<string, string> = {
        admin_webhook: t('Webhook sự cố hệ thống'),
        news_webhook: t('Webhook tin bảo mật'),
        alert_locale: t('Ngôn ngữ tin nhắn'),
        scheduler_heartbeat_url: t('URL heartbeat'),
        digest_time: t('Giờ gửi'),
        digest_days: t('Ngày nhận tin'),
        digest_timezone: t('Múi giờ'),
        digest_weekly_day: t('Ngày gửi bản tổng hợp tuần'),
        eol_warning_days: t('Cảnh báo EOL trước'),
        eol_reminder_days: t('Các mốc nhắc EOL'),
        immediate_severities: t('Gửi ngay khi mức độ'),
        immediate_dev_dependencies: t('Dev dependency'),
        lockfile_summary_threshold: t('Ngưỡng gộp thẻ tóm tắt'),
        runtime_eol_severity: t('Runtime hết vòng đời (EOL)'),
        runtime_outdated_security_severity: t('Runtime thiếu bản vá bảo mật'),
        kev_severity: t('Lỗ hổng trong CISA KEV'),
        deadline_critical_days: 'Critical',
        deadline_high_days: 'High',
        deadline_medium_days: 'Medium',
        deadline_low_days: 'Low',
        notice_relevant_days: t('Số ngày tin còn hiệu lực'),
        notice_collect_days: t('Số ngày lưu tin'),
        classifier_model: t('Model phân loại'),
        classifier_effort: t('Mức suy luận'),
        classifier_alert_after_minutes: t('Báo tin chưa phân loại sau'),
        incident_patch_hold_hours: t('Giữ thẻ "thiếu bản vá"'),
        incident_review_after_days: t('Nhắc review sau'),
        incident_awaiting_patch_days: t('Theo dõi "đang chờ bản vá"'),
        incident_window_before_hours: t('Trước bản phát hành'),
        incident_window_after_hours: t('Sau bản phát hành'),
        github_sync_time: t('Giờ đồng bộ'),
        github_sync_timezone: t('Múi giờ đồng bộ'),
        catalog_stale_after_minutes: t('Danh mục phiên bản'),
        notice_stale_after_minutes: t('Tin từ vendor'),
        kev_stale_after_minutes: 'CISA KEV',
        packages_stale_after_minutes: t('Lỗ hổng package'),
        github_stale_after_minutes: 'GitHub',
        alert_stuck_after_minutes: t('Tin chờ gửi trong hàng đợi'),
    };
    const unitLabels: Record<Unit, string> = { days: t('ngày'), hours: t('giờ'), minutes: t('phút') };
    const dayLabels = [t('Chủ nhật'), t('Thứ hai'), t('Thứ ba'), t('Thứ tư'), t('Thứ năm'), t('Thứ sáu'), t('Thứ bảy')];
    const shortDayLabels = [t('CN'), t('T2'), t('T3'), t('T4'), t('T5'), t('T6'), t('T7')];
    const timezoneOptions = options.timezones.map((timezone) => ({ value: timezone, label: timezone }));

    const formatValue = (name: string, value: unknown): string => {
        if (value === null || value === '' || (Array.isArray(value) && value.length === 0)) {
            return '—';
        }

        if (name === 'digest_days') {
            return WEEK.filter((day) => (value as number[]).includes(day)).map((day) => shortDayLabels[day]).join(', ');
        }

        if (name === 'digest_weekly_day') {
            return dayLabels[value as number] ?? String(value);
        }

        if (name === 'immediate_severities') {
            return (value as string[]).map((severity) => options.severities.find((option) => option.value === severity)?.label ?? severity).join(', ');
        }

        if (name.endsWith('_severity')) {
            return options.severities.find((option) => option.value === value)?.label ?? String(value);
        }

        if (name === 'alert_locale') {
            return options.locales.find((option) => option.value === value)?.label ?? String(value);
        }

        if (typeof value === 'boolean') {
            return value ? t('Có') : t('Không');
        }

        const unit = UNITS[name as SettingName];
        const text = Array.isArray(value) ? value.join(', ') : String(value);

        return unit ? `${text} ${unitLabels[unit]}` : text;
    };

    const isDefault = (name: SettingName) => JSON.stringify(form.data[name]) === JSON.stringify(defaults[name]);

    const resetToDefault = (name: SettingName) => {
        form.setData(name, defaults[name] as never);

        if (name === 'eol_reminder_days') {
            setReminderDaysText(defaults.eol_reminder_days.join(', '));
        }
    };

    /** The help of a field: what it does, who changed it last, and the .env value with a way back to it. */
    const hint = (name: SettingName, help?: ReactNode) => {
        const change = changed[name];

        return (
            <>
                {help && <span className="block">{help}</span>}
                {(change || !isDefault(name)) && (
                    <span className="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-faint">
                        {change && <span>{t('Đổi bởi :user, :time', { user: change.by ?? '—', time: change.at ?? '' })}</span>}
                        {change && !isDefault(name) && <span aria-hidden>·</span>}
                        {!isDefault(name) && (
                            <>
                                <span>{t('.env: :value', { value: formatValue(name, defaults[name]) })}</span>
                                <button type="button" className="inline-flex items-center gap-1 text-link hover:underline" onClick={() => resetToDefault(name)}>
                                    <RotateCcwIcon className="size-3" aria-hidden />
                                    {t('Dùng giá trị .env')}
                                </button>
                            </>
                        )}
                    </span>
                )}
            </>
        );
    };

    const numberField = (name: SettingName, help?: ReactNode, bounds: { min?: number; max?: number } = {}) => {
        const unit = UNITS[name];

        return (
            <Field label={labels[name]} htmlFor={name} required error={form.errors[name]} help={hint(name, help)}>
                <div className="flex items-center gap-2">
                    <Input
                        id={name}
                        type="number"
                        inputMode="numeric"
                        min={bounds.min}
                        max={bounds.max}
                        className="w-32"
                        value={form.data[name] as number | ''}
                        onChange={(event) => form.setData(name, (event.target.value === '' ? '' : Number(event.target.value)) as never)}
                        aria-invalid={form.errors[name] !== undefined}
                    />
                    {unit && <span className="text-muted-foreground">{unitLabels[unit]}</span>}
                </div>
            </Field>
        );
    };

    const selectField = (name: SettingName, choices: Option<string | number>[], help?: ReactNode) => (
        <Field label={labels[name]} htmlFor={name} required error={form.errors[name]} help={hint(name, help)}>
            <NativeSelect
                id={name}
                value={String(form.data[name])}
                onChange={(event) => form.setData(name, (typeof defaults[name] === 'number' ? Number(event.target.value) : event.target.value) as never)}
                aria-invalid={form.errors[name] !== undefined}
            >
                {choices.map((choice) => (
                    <option key={choice.value} value={choice.value}>
                        {choice.label}
                    </option>
                ))}
            </NativeSelect>
        </Field>
    );

    const timeField = (name: 'digest_time' | 'github_sync_time', help?: ReactNode) => (
        <Field label={labels[name]} htmlFor={name} required error={form.errors[name]} help={hint(name, help)}>
            <Input id={name} type="time" className="w-36" value={form.data[name]} onChange={(event) => form.setData(name, event.target.value)} aria-invalid={form.errors[name] !== undefined} />
        </Field>
    );

    const timezoneField = (name: 'digest_timezone' | 'github_sync_timezone') => (
        <Field label={labels[name]} htmlFor={name} required error={form.errors[name]} help={hint(name)}>
            <Combobox
                id={name}
                value={form.data[name]}
                options={timezoneOptions}
                onChange={(timezone) => form.setData(name, timezone)}
                searchPlaceholder={t('Tìm múi giờ')}
                invalid={form.errors[name] !== undefined}
            />
        </Field>
    );

    /** The first error of the items of a list, e.g. "digest_days.2". */
    const itemError = (name: SettingName) => Object.entries(form.errors as Record<string, string>).find(([key]) => key.startsWith(`${name}.`))?.[1];

    const severityChoices = options.severities;

    return (
        <>
            <PageHeader
                title={t('Cài đặt hệ thống')}
                description={t('Giá trị đặt ở đây được dùng thay cho .env. API key, GitHub App và database vẫn chỉ đặt trong .env.')}
                actions={
                    <Button type="submit" form="system-settings" loading={form.processing} disabled={!form.isDirty}>
                        {t('Lưu thay đổi')}
                    </Button>
                }
            />
            <form
                id="system-settings"
                className="rounded-lg border border-border bg-surface"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.submit(update(), { preserveScroll: true, onSuccess: () => form.setDefaults() });
                }}
                noValidate
            >
                <CardBody>
                    <FormSection title={t('Kênh thông báo admin')} description={t('Space Google Chat nhận lỗi hệ thống và tin bảo mật từ vendor.')}>
                        <FormFields>
                            <AdminWebhookField webhook="admin" label={labels.admin_webhook} data={webhooks.admin} emptyText={t('Chưa có webhook. Lỗi hệ thống sẽ không được báo.')} />
                            <AdminWebhookField webhook="news" label={labels.news_webhook} data={webhooks.news} emptyText={t('Chưa có webhook. Tin bảo mật được gửi về space sự cố hệ thống.')} />
                            <FormFields columns={2}>{selectField('alert_locale', options.locales, t('Ngôn ngữ của mọi tin gửi lên Google Chat.'))}</FormFields>
                            <Field
                                label={labels.scheduler_heartbeat_url}
                                htmlFor="scheduler_heartbeat_url"
                                error={form.errors.scheduler_heartbeat_url}
                                help={hint('scheduler_heartbeat_url', t('Được ping sau mỗi lần kiểm tra nguồn (vd. Healthchecks.io) để biết khi scheduler ngừng chạy. Để trống để tắt.'))}
                            >
                                <Input
                                    id="scheduler_heartbeat_url"
                                    type="url"
                                    className="font-mono text-[calc(var(--fs-sm)-0.5px)]"
                                    placeholder="https://hc-ping.com/…"
                                    value={form.data.scheduler_heartbeat_url ?? ''}
                                    onChange={(event) => form.setData('scheduler_heartbeat_url', event.target.value === '' ? null : event.target.value)}
                                    aria-invalid={form.errors.scheduler_heartbeat_url !== undefined}
                                />
                            </Field>
                        </FormFields>
                    </FormSection>

                    <FormSection title={t('Lịch nhận tin')} description={t('Bản tin gửi cho từng team. Team không có gì mới thì không nhận.')}>
                        <FormFields>
                            <FormFields columns={2}>
                                {timeField('digest_time')}
                                {timezoneField('digest_timezone')}
                            </FormFields>
                            <Field label={labels.digest_days} required error={form.errors.digest_days ?? itemError('digest_days')} help={hint('digest_days')}>
                                <div className="flex flex-wrap gap-x-5 gap-y-2.5" role="group" aria-label={labels.digest_days}>
                                    {WEEK.map((day) => (
                                        <label key={day} className="flex items-center gap-2 text-base">
                                            <Checkbox
                                                checked={form.data.digest_days.includes(day)}
                                                onCheckedChange={(checked) =>
                                                    form.setData(
                                                        'digest_days',
                                                        (checked === true ? [...form.data.digest_days, day] : form.data.digest_days.filter((selected) => selected !== day)).sort((a, b) => a - b),
                                                    )
                                                }
                                            />
                                            {dayLabels[day]}
                                        </label>
                                    ))}
                                </div>
                            </Field>
                            <FormFields columns={2}>
                                {selectField(
                                    'digest_weekly_day',
                                    WEEK.map((day) => ({ value: day, label: dayLabels[day] })),
                                    t('Hôm đó gửi bản tổng hợp tuần thay cho bản tin ngày.'),
                                )}
                            </FormFields>
                            <FormFields columns={2}>
                                {numberField('eol_warning_days', t('Bản tổng hợp tuần liệt kê runtime hết vòng đời trong khoảng này.'), { min: 1, max: 365 })}
                                <Field
                                    label={labels.eol_reminder_days}
                                    htmlFor="eol_reminder_days"
                                    required
                                    error={form.errors.eol_reminder_days ?? itemError('eol_reminder_days')}
                                    help={hint('eol_reminder_days', t('Số ngày trước EOL để nhắc trong bản tin ngày, cách nhau bằng dấu phẩy.'))}
                                >
                                    <div className="flex items-center gap-2">
                                        <Input
                                            id="eol_reminder_days"
                                            inputMode="numeric"
                                            className="w-40"
                                            placeholder="30, 7, 1"
                                            value={reminderDaysText}
                                            onChange={(event) => {
                                                setReminderDaysText(event.target.value);
                                                form.setData(
                                                    'eol_reminder_days',
                                                    [...new Set(event.target.value.split(',').map((day) => day.trim()).filter((day) => /^\d+$/.test(day)).map(Number))].sort((a, b) => b - a),
                                                );
                                            }}
                                            aria-invalid={form.errors.eol_reminder_days !== undefined}
                                        />
                                        <span className="text-muted-foreground">{unitLabels.days}</span>
                                    </div>
                                </Field>
                            </FormFields>
                        </FormFields>
                    </FormSection>

                    <FormSection title={t('Chính sách cảnh báo')} description={t('Mức độ nào được gửi ngay cho team, mức nào chỉ có trong bản tin ngày.')}>
                        <FormFields>
                            <Field label={labels.immediate_severities} error={form.errors.immediate_severities} help={hint('immediate_severities', t('Các mức khác chỉ có trong bản tin ngày.'))}>
                                <div className="flex flex-wrap gap-x-5 gap-y-2.5" role="group" aria-label={labels.immediate_severities}>
                                    {severityChoices.map((severity) => (
                                        <label key={severity.value} className="flex items-center gap-2 text-base">
                                            <Checkbox
                                                checked={form.data.immediate_severities.includes(severity.value)}
                                                onCheckedChange={(checked) =>
                                                    form.setData(
                                                        'immediate_severities',
                                                        severityChoices
                                                            .map((choice) => choice.value)
                                                            .filter((value) => (value === severity.value ? checked === true : form.data.immediate_severities.includes(value))),
                                                    )
                                                }
                                            />
                                            {severity.label}
                                        </label>
                                    ))}
                                </div>
                            </Field>
                            <Field error={form.errors.immediate_dev_dependencies} help={hint('immediate_dev_dependencies', t('Mặc định lỗ hổng của dev dependency chỉ có trong bản tin ngày.'))}>
                                <label className="flex items-center gap-2 text-base">
                                    <Checkbox checked={form.data.immediate_dev_dependencies} onCheckedChange={(checked) => form.setData('immediate_dev_dependencies', checked === true)} />
                                    {t('Gửi ngay cả lỗ hổng của dev dependency')}
                                </label>
                            </Field>
                            {numberField('lockfile_summary_threshold', t('Service có từ chừng này lỗ hổng package trở lên trong một lần quét chỉ nhận một thẻ tóm tắt. 0 để tắt.'), { min: 0, max: 100 })}
                            <FormFields columns={2}>
                                {selectField('runtime_eol_severity', severityChoices)}
                                {selectField('runtime_outdated_security_severity', severityChoices)}
                                {selectField('kev_severity', severityChoices, t('Lỗ hổng đang bị khai thác (CISA KEV) được nâng lên mức này.'))}
                            </FormFields>
                        </FormFields>
                    </FormSection>

                    <FormSection
                        title={t('Hạn xử lý (SLA)')}
                        description={t('Số ngày từ lúc phát hiện đến hạn xử lý, theo mức độ. Chỉ áp dụng cho finding phát hiện sau khi lưu; finding cũ giữ hạn đã tính.')}
                    >
                        <FormFields columns={2}>
                            {numberField('deadline_critical_days', undefined, { min: 1, max: 365 })}
                            {numberField('deadline_high_days', undefined, { min: 1, max: 365 })}
                            {numberField('deadline_medium_days', undefined, { min: 1, max: 365 })}
                            {numberField('deadline_low_days', undefined, { min: 1, max: 365 })}
                        </FormFields>
                    </FormSection>

                    <FormSection title={t('Tin bảo mật từ vendor')} description={t('Tin từ php.net, nodejs.org, ruby-lang.org và cách AI phân loại tin.')}>
                        <FormFields columns={2}>
                            {numberField('notice_relevant_days', t('Chỉ tin đăng trong khoảng này mới được gửi cho team.'), { min: 1, max: 365 })}
                            {numberField('notice_collect_days', t('Không ngắn hơn số ngày tin còn hiệu lực.'), { min: 1, max: 3650 })}
                            {selectField(
                                'classifier_model',
                                options.classifier_models.map((model) => ({ value: model, label: model })),
                            )}
                            {selectField(
                                'classifier_effort',
                                options.classifier_efforts.map((effort) => ({ value: effort, label: effort })),
                                t('Cao hơn thì đọc kỹ hơn nhưng chậm và tốn hơn.'),
                            )}
                            {numberField('classifier_alert_after_minutes', t('Báo admin khi một tin vẫn chưa được phân loại sau chừng này.'), { min: 5, max: 1440 })}
                        </FormFields>
                    </FormSection>

                    <FormSection title={t('Sự cố')} description={t('Cách gom tin thành sự cố và theo dõi bản vá.')}>
                        <FormFields columns={2}>
                            {numberField('incident_patch_hold_hours', t('Finding "thiếu bản vá" mới chờ chừng này để vendor xác nhận phiên bản trước khi gửi thẻ.'), { min: 0, max: 168 })}
                            {numberField('incident_review_after_days', t('Bản tin nhắc review sự cố chưa được review sau chừng này.'), { min: 1, max: 365 })}
                            {numberField('incident_awaiting_patch_days', t('Thời gian sự cố nằm trong mục "Đang chờ bản vá" của bản tin.'), { min: 1, max: 365 })}
                        </FormFields>
                        <p className="mt-6 mb-3 text-sm text-muted-foreground">{t('Tin đăng trong khoảng này quanh một bản phát hành đã báo trước chỉ được gợi ý gộp vào sự cố.')}</p>
                        <FormFields columns={2}>
                            {numberField('incident_window_before_hours', undefined, { min: 0, max: 720 })}
                            {numberField('incident_window_after_hours', undefined, { min: 0, max: 720 })}
                        </FormFields>
                    </FormSection>

                    <FormSection title={t('Đồng bộ & giám sát nguồn')} description={t('Nâng cao. Khi một nguồn không trả lời quá ngưỡng, admin được báo.')}>
                        <FormFields>
                            <FormFields columns={2}>
                                {timeField('github_sync_time', t('Đọc lockfile từ GitHub mỗi ngày một lần.'))}
                                {timezoneField('github_sync_timezone')}
                            </FormFields>
                            <FormFields columns={2}>
                                {numberField('catalog_stale_after_minutes', undefined, { min: 15, max: 10080 })}
                                {numberField('notice_stale_after_minutes', undefined, { min: 15, max: 10080 })}
                                {numberField('kev_stale_after_minutes', undefined, { min: 15, max: 10080 })}
                                {numberField('packages_stale_after_minutes', undefined, { min: 15, max: 10080 })}
                                {numberField('github_stale_after_minutes', undefined, { min: 15, max: 10080 })}
                                {numberField('alert_stuck_after_minutes', t('Hàng đợi bị coi là dừng khi một tin chờ lâu hơn chừng này.'), { min: 5, max: 1440 })}
                            </FormFields>
                        </FormFields>
                    </FormSection>

                    <FormActions>
                        <Button type="submit" loading={form.processing} disabled={!form.isDirty}>
                            {t('Lưu thay đổi')}
                        </Button>
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={!form.isDirty}
                            onClick={() => {
                                form.resetAndClearErrors();
                                setReminderDaysText(settings.eol_reminder_days.join(', '));
                            }}
                        >
                            {t('Hoàn tác')}
                        </Button>
                    </FormActions>
                </CardBody>
            </form>

            <Card className="mt-6">
                <CardHeader>
                    <CardTitle>{t('Lịch sử thay đổi')}</CardTitle>
                </CardHeader>
                {history.length === 0 ? (
                    <EmptyState title={t('Chưa có thay đổi nào')} description={t('Mọi cài đặt đang dùng giá trị trong .env.')} />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>{t('Thời gian')}</Th>
                                <Th>{t('Người đổi')}</Th>
                                <Th>{t('Cài đặt')}</Th>
                                <Th>{t('Thay đổi')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {history.map((change) => (
                                <tr key={change.id}>
                                    <Td className="whitespace-nowrap">{change.at}</Td>
                                    <Td>{change.user ?? '—'}</Td>
                                    <Td>{labels[change.key] ?? change.key}</Td>
                                    <Td>
                                        {change.key.endsWith('_webhook') ? (
                                            <span className="text-muted-foreground">{t('Webhook đã được thay đổi')}</span>
                                        ) : (
                                            <span>
                                                <span className="text-muted-foreground line-through">{formatValue(change.key, change.old_value)}</span> → {formatValue(change.key, change.new_value)}
                                            </span>
                                        )}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>
        </>
    );
}
