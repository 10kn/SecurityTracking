import type { InertiaFormProps } from '@inertiajs/react';
import { FileIcon, PlusIcon, Trash2Icon, UploadIcon, XIcon } from 'lucide-react';
import { useRef, useState } from 'react';

import { Combobox } from '@/components/admin/combobox';
import { Field, FormFields, FormSection } from '@/components/admin/form-section';
import { StatusDot } from '@/components/admin/status-dot';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useRuntimeEvaluation } from '@/hooks/use-runtime-evaluation';
import { useSlugFromName } from '@/hooks/use-slug-from-name';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Option } from '@/types';

/** Mirrors Runtime::RAW_VERSION_PATTERN and RAW_VERSION_MAX_LENGTH, e.g. "8.3.12", "v20.11.0", "8.3.12-1+deb.sury.org+1". */
const RAW_VERSION_PATTERN = /^[0-9A-Za-z][0-9A-Za-z.+~_-]*$/;
const RAW_VERSION_MAX_LENGTH = 64;

/** Mirrors Ecosystem::lockfileNames() and Lockfile::MAX_UPLOAD_KILOBYTES. */
const LOCKFILE_NAMES = ['composer.lock', 'Gemfile.lock', 'package-lock.json'];
const MAX_LOCKFILE_BYTES = 20480 * 1024;

export interface TechnologyOption extends Option<string> {
    versions: string[];
}

export interface RuntimeRow {
    /** Only for React; removed before the form is sent. */
    key: string;
    technology: string | null;
    raw_version: string;
}

export interface ServiceFormData {
    project_id: number | null;
    name: string;
    slug: string;
    kind: string;
    exposure: string;
    repository_path: string;
    runtimes: RuntimeRow[];
    lockfile_uploads: File[];
    removed_lockfiles: string[];
}

export interface ServiceFormOptions {
    projects: Option<number>[];
    /** Projects that read their lockfiles from GitHub: their services take no uploads. */
    lockfileSyncProjectIds: number[];
    kinds: Option<string>[];
    exposures: Option<string>[];
    technologies: TechnologyOption[];
}

export interface ExistingLockfile {
    path: string;
    source: 'upload' | 'github';
    commit: string | null;
    uploaded_at: string;
}

export function newRuntimeRow(technology: string | null = null, rawVersion = ''): RuntimeRow {
    return { key: crypto.randomUUID(), technology, raw_version: rawVersion };
}

/** The form data as the server expects it: runtimes without their React keys. */
export function serviceRequestData({ runtimes, ...data }: ServiceFormData) {
    return { ...data, runtimes: runtimes.map(({ technology, raw_version }) => ({ technology, raw_version })) };
}

export function ServiceFields({
    form,
    options,
    isCreating,
    existingLockfiles = [],
}: {
    form: InertiaFormProps<ServiceFormData>;
    options: ServiceFormOptions;
    isCreating: boolean;
    existingLockfiles?: ExistingLockfile[];
}) {
    const t = useTranslation();
    const slug = useSlugFromName(isCreating, (value) => form.setData('slug', value));
    const errors = form.errors as Record<string, string | undefined>;
    const syncsLockfiles = form.data.project_id !== null && options.lockfileSyncProjectIds.includes(form.data.project_id);

    return (
        <>
            <FormSection title={t('Thông tin service')} description={t('Service thuộc một project; cảnh báo gửi tới team sở hữu project.')}>
                <FormFields columns={2}>
                    <Field label={t('Project')} htmlFor="project_id" required error={form.errors.project_id} className="sm:col-span-2">
                        <Combobox
                            id="project_id"
                            value={form.data.project_id}
                            options={options.projects}
                            onChange={(projectId) =>
                                // A project that reads lockfiles from GitHub takes no uploads, so files picked before are dropped.
                                form.setData(
                                    projectId !== null && options.lockfileSyncProjectIds.includes(projectId)
                                        ? { ...form.data, project_id: projectId, lockfile_uploads: [], removed_lockfiles: [] }
                                        : { ...form.data, project_id: projectId },
                                )
                            }
                            placeholder={t('Chọn project')}
                            searchPlaceholder={t('Tìm project')}
                            invalid={form.errors.project_id !== undefined}
                        />
                    </Field>
                    <Field label={t('Tên service')} htmlFor="name" required error={form.errors.name}>
                        <Input
                            id="name"
                            placeholder="shop-api"
                            value={form.data.name}
                            onChange={(event) => {
                                form.setData('name', event.target.value);
                                slug.onNameChange(event.target.value);
                            }}
                            aria-invalid={form.errors.name !== undefined}
                        />
                    </Field>
                    <Field label={t('Slug')} htmlFor="slug" required error={form.errors.slug}>
                        <Input
                            id="slug"
                            className="font-mono text-[calc(var(--fs-sm)-0.5px)]"
                            value={form.data.slug}
                            onChange={(event) => slug.onSlugChange(event.target.value)}
                            aria-invalid={form.errors.slug !== undefined}
                        />
                    </Field>
                    <Field label={t('Loại')} htmlFor="kind" required error={form.errors.kind}>
                        <NativeSelect id="kind" value={form.data.kind} onChange={(event) => form.setData('kind', event.target.value)} aria-invalid={form.errors.kind !== undefined}>
                            {options.kinds.map((kind) => (
                                <option key={kind.value} value={kind.value}>
                                    {kind.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field label={t('Phạm vi truy cập')} htmlFor="exposure" required error={form.errors.exposure}>
                        <NativeSelect
                            id="exposure"
                            value={form.data.exposure}
                            onChange={(event) => form.setData('exposure', event.target.value)}
                            aria-invalid={form.errors.exposure !== undefined}
                        >
                            {options.exposures.map((exposure) => (
                                <option key={exposure.value} value={exposure.value}>
                                    {exposure.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field
                        label={t('Thư mục trong repository')}
                        htmlFor="repository_path"
                        required
                        error={form.errors.repository_path}
                        help={t('Dùng cho monorepo. Để "/" nếu service nằm ở gốc repository.')}
                        className="sm:col-span-2"
                    >
                        <Input
                            id="repository_path"
                            className="font-mono text-[calc(var(--fs-sm)-0.5px)]"
                            value={form.data.repository_path}
                            onChange={(event) => form.setData('repository_path', event.target.value)}
                            aria-invalid={form.errors.repository_path !== undefined}
                        />
                    </Field>
                </FormFields>
            </FormSection>
            <FormSection
                title={t('Môi trường production')}
                description={
                    syncsLockfiles
                        ? t('Chọn công nghệ và nhập version đang chạy. Lockfile lấy từ GitHub mỗi ngày, trong thư mục của service. Lưu xong, hệ thống đánh giá lại service này.')
                        : t('Chọn công nghệ, nhập version đang chạy và tải lockfile. Lưu xong, hệ thống đánh giá lại service này.')
                }
            >
                <FormFields>
                    <Field label={t('Runtime / công nghệ')} error={errors.runtimes}>
                        <RuntimeRows form={form} technologies={options.technologies} errors={errors} />
                    </Field>
                    {syncsLockfiles && (
                        <Field
                            label={t('Lockfile từ GitHub')}
                            error={errors.lockfile_uploads ?? errors.removed_lockfiles}
                            help={t('Project này lấy lockfile từ GitHub, không tải lên hay xóa tay được.')}
                        >
                            {existingLockfiles.length === 0 ? (
                                <p className="text-muted-foreground">{isCreating ? t('Lấy ngay sau khi lưu.') : t('Chưa có lockfile nào trong thư mục của service.')}</p>
                            ) : (
                                <ul className="grid gap-2">
                                    {existingLockfiles.map((lockfile) => (
                                        <li key={lockfile.path} className="flex min-w-0 flex-wrap items-baseline gap-x-2">
                                            <span className="font-mono text-sm whitespace-nowrap">{lockfile.path}</span>
                                            <LockfileOrigin lockfile={lockfile} />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Field>
                    )}
                    {!syncsLockfiles && existingLockfiles.length > 0 && (
                        <Field label={t('Lockfile hiện có')} help={t('Đánh dấu để xóa lockfile khi lưu.')}>
                            <div className="grid gap-2">
                                {existingLockfiles.map((lockfile) => (
                                    <label key={lockfile.path} className="flex items-start gap-2.5 text-base">
                                        <Checkbox
                                            className="mt-[3px]"
                                            checked={form.data.removed_lockfiles.includes(lockfile.path)}
                                            onCheckedChange={(checked) =>
                                                form.setData(
                                                    'removed_lockfiles',
                                                    checked === true ? [...form.data.removed_lockfiles, lockfile.path] : form.data.removed_lockfiles.filter((path) => path !== lockfile.path),
                                                )
                                            }
                                        />
                                        <span className="flex min-w-0 flex-wrap items-baseline gap-x-2">
                                            <span className={cn('font-mono text-sm whitespace-nowrap', form.data.removed_lockfiles.includes(lockfile.path) && 'text-faint line-through')}>
                                                {lockfile.path}
                                            </span>
                                            <LockfileOrigin lockfile={lockfile} />
                                        </span>
                                    </label>
                                ))}
                            </div>
                        </Field>
                    )}
                    {!syncsLockfiles && <LockfileUpload form={form} errors={errors} />}
                </FormFields>
            </FormSection>
        </>
    );
}

/** "tải lên 24/10/2026 09:12", or "GitHub · abc1234 · lấy lúc 24/10/2026 06:30". */
function LockfileOrigin({ lockfile }: { lockfile: ExistingLockfile }) {
    const t = useTranslation();

    return (
        <span className="text-sm text-muted-foreground">
            {lockfile.source === 'github'
                ? t('GitHub · :commit · lấy lúc :time', { commit: lockfile.commit ?? '—', time: lockfile.uploaded_at })
                : t('tải lên :time', { time: lockfile.uploaded_at })}
        </span>
    );
}

function RuntimeRows({ form, technologies, errors }: { form: InertiaFormProps<ServiceFormData>; technologies: TechnologyOption[]; errors: Record<string, string | undefined> }) {
    const t = useTranslation();
    const rows = form.data.runtimes;

    const updateRow = (key: string, changes: Partial<RuntimeRow>) => form.setData('runtimes', rows.map((row) => (row.key === key ? { ...row, ...changes } : row)));

    return (
        <div className="grid gap-3">
            {rows.length > 0 && (
                <div className="overflow-hidden rounded-lg border border-border">
                    <div className="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)_40px] gap-3 border-b border-border bg-surface-2 px-3 py-2 text-sm font-medium text-muted-foreground max-sm:hidden">
                        <span>
                            {t('Công nghệ')}
                            <span className="text-danger-text"> *</span>
                        </span>
                        <span>
                            {t('Version')}
                            <span className="text-danger-text"> *</span>
                        </span>
                        <span />
                    </div>
                    {rows.map((row, index) => (
                        <RuntimeRowFields
                            key={row.key}
                            row={row}
                            technologies={technologies.map((technology) => ({
                                ...technology,
                                disabled: technology.value !== row.technology && rows.some((other) => other.technology === technology.value),
                            }))}
                            technologyError={errors[`runtimes.${index}.technology`]}
                            versionError={errors[`runtimes.${index}.raw_version`]}
                            onChange={(changes) => updateRow(row.key, changes)}
                            onRemove={() =>
                                form.setData(
                                    'runtimes',
                                    rows.filter((other) => other.key !== row.key),
                                )
                            }
                        />
                    ))}
                </div>
            )}
            <div>
                <Button type="button" variant="secondary" onClick={() => form.setData('runtimes', [...rows, newRuntimeRow()])}>
                    <PlusIcon />
                    {t('Thêm công nghệ')}
                </Button>
            </div>
        </div>
    );
}

function RuntimeRowFields({
    row,
    technologies,
    technologyError,
    versionError,
    onChange,
    onRemove,
}: {
    row: RuntimeRow;
    technologies: (TechnologyOption & { disabled: boolean })[];
    technologyError?: string;
    versionError?: string;
    onChange: (changes: Partial<RuntimeRow>) => void;
    onRemove: () => void;
}) {
    const t = useTranslation();
    const technology = technologies.find((option) => option.value === row.technology);
    const preview = useRuntimeEvaluation(row.technology, row.raw_version);
    const versionOptions = (technology?.versions ?? []).map((version) => ({ value: version, label: version }));

    return (
        <div className="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)_40px] items-start gap-3 border-b border-border px-3 py-3 last:border-b-0 max-sm:grid-cols-[minmax(0,1fr)_40px]">
            <div className="min-w-0 max-sm:col-span-1">
                <Combobox
                    value={row.technology}
                    options={technologies}
                    onChange={(technology) => onChange({ technology })}
                    placeholder={t('Chọn công nghệ')}
                    searchPlaceholder={t('Tìm công nghệ')}
                    invalid={technologyError !== undefined}
                />
                {technologyError && <p className="mt-1.5 text-sm text-danger-text">{technologyError}</p>}
            </div>
            <div className="min-w-0 max-sm:col-start-1 max-sm:row-start-2">
                <Combobox
                    value={row.raw_version === '' ? null : row.raw_version}
                    options={versionOptions}
                    onChange={(version) => onChange({ raw_version: version })}
                    onCreate={(version) => onChange({ raw_version: version })}
                    canCreate={(text) => text.length <= RAW_VERSION_MAX_LENGTH && RAW_VERSION_PATTERN.test(text)}
                    match="prefix"
                    mono
                    placeholder={versionOptions[0]?.label ?? t('Chọn version')}
                    searchPlaceholder={t('Gõ để lọc hoặc nhập version khác')}
                    emptyText={t('Version chỉ gồm chữ, số và . + ~ _ -')}
                    invalid={versionError !== undefined}
                    disabled={row.technology === null}
                />
                {versionError ? (
                    <p className="mt-1.5 text-sm text-danger-text">{versionError}</p>
                ) : (
                    preview && (
                        <p className="mt-1.5 text-sm text-muted-foreground">
                            <StatusDot status={preview.status} /> · {preview.details}
                        </p>
                    )
                )}
            </div>
            <Button type="button" variant="ghost" size="icon" className="text-danger-text hover:text-danger-text max-sm:row-span-2" aria-label={t('Xóa dòng')} onClick={onRemove}>
                <Trash2Icon />
            </Button>
        </div>
    );
}

/**
 * Drop or pick lockfiles. A file with the name of an existing one replaces it. Names and sizes are checked here so a
 * wrong file is flagged before uploading; the server checks again.
 */
function LockfileUpload({ form, errors }: { form: InertiaFormProps<ServiceFormData>; errors: Record<string, string | undefined> }) {
    const t = useTranslation();
    const inputRef = useRef<HTMLInputElement>(null);
    const [isDragging, setIsDragging] = useState(false);
    const files = form.data.lockfile_uploads;

    const addFiles = (list: FileList | null) => {
        if (list === null) {
            return;
        }

        const added = Array.from(list);
        form.setData('lockfile_uploads', [...files.filter((file) => !added.some((other) => other.name === file.name)), ...added]);
    };

    const fileProblem = (file: File): string | undefined => {
        if (!LOCKFILE_NAMES.includes(file.name)) {
            return t('Chỉ nhận composer.lock, Gemfile.lock hoặc package-lock.json.');
        }

        if (file.size > MAX_LOCKFILE_BYTES) {
            return t('File lớn hơn 20 MB.');
        }

        return undefined;
    };

    return (
        <Field label={t('Tải lockfile')} help={t('composer.lock, Gemfile.lock hoặc package-lock.json. File cùng tên thay file cũ.')}>
            <div
                onDragOver={(event) => {
                    event.preventDefault();
                    setIsDragging(true);
                }}
                onDragLeave={() => setIsDragging(false)}
                onDrop={(event) => {
                    event.preventDefault();
                    setIsDragging(false);
                    addFiles(event.dataTransfer.files);
                }}
                className={cn(
                    'flex flex-wrap items-center justify-center gap-2 rounded-lg border border-dashed border-border-strong bg-surface px-4 py-6 text-center text-muted-foreground',
                    isDragging && 'border-primary bg-primary-soft',
                )}
            >
                <UploadIcon className="size-4 text-faint" aria-hidden />
                <span>{t('Kéo thả lockfile vào đây hoặc')}</span>
                <button type="button" className="font-medium text-link hover:underline hover:underline-offset-3" onClick={() => inputRef.current?.click()}>
                    {t('chọn file')}
                </button>
                <input
                    ref={inputRef}
                    type="file"
                    multiple
                    className="sr-only"
                    tabIndex={-1}
                    onChange={(event) => {
                        addFiles(event.target.files);
                        event.target.value = '';
                    }}
                />
            </div>
            {files.length > 0 && (
                <ul className="mt-2 grid gap-1.5">
                    {files.map((file, index) => {
                        const problem = fileProblem(file) ?? errors[`lockfile_uploads.${index}`];

                        return (
                            <li key={file.name} className="flex items-center gap-2 rounded-md border border-border px-3 py-2">
                                <FileIcon className="size-4 shrink-0 text-faint" aria-hidden />
                                <span className="font-mono text-sm">{file.name}</span>
                                <span className="text-sm text-faint">{t(':size KB', { size: Math.ceil(file.size / 1024) })}</span>
                                {problem && <span className="text-sm text-danger-text">{problem}</span>}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    className="ml-auto"
                                    aria-label={t('Bỏ file')}
                                    onClick={() =>
                                        form.setData(
                                            'lockfile_uploads',
                                            files.filter((other) => other !== file),
                                        )
                                    }
                                >
                                    <XIcon />
                                </Button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </Field>
    );
}
