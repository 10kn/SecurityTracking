import { type InertiaFormProps, router } from '@inertiajs/react';
import { FileIcon, PlusIcon, RefreshCwIcon, Trash2Icon, UploadIcon, XIcon } from 'lucide-react';
import { useRef, useState } from 'react';

import { store as syncRepositories } from '@/actions/App/Http/Controllers/Admin/AccessibleRepositorySyncController';
import { store as syncLockfiles } from '@/actions/App/Http/Controllers/Admin/ServiceLockfileSyncController';
import { Combobox } from '@/components/admin/combobox';
import { Field, FormFields, FormSection } from '@/components/admin/form-section';
import { StatusDot } from '@/components/admin/status-dot';
import { TechnologyLogo } from '@/components/admin/technology-logo';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input, inputClassName } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useRuntimeEvaluation } from '@/hooks/use-runtime-evaluation';
import { useSlugFromName } from '@/hooks/use-slug-from-name';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Option, StatusDotData } from '@/types';

/** Mirrors Runtime::RAW_VERSION_PATTERN and RAW_VERSION_MAX_LENGTH, e.g. "8.3.12", "v20.11.0", "8.3.12-1+deb.sury.org+1". */
const RAW_VERSION_PATTERN = /^[0-9A-Za-z][0-9A-Za-z.+~_-]*$/;
const RAW_VERSION_MAX_LENGTH = 64;

/** Mirrors Ecosystem::lockfileNames() and Lockfile::MAX_UPLOAD_KILOBYTES. */
const LOCKFILE_NAMES = ['composer.lock', 'Gemfile.lock', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock'];
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
    /** E.g. "acme/shop"; null when lockfiles are uploaded by hand. */
    repository: string | null;
    repository_branch: string;
    /** Paths relative to the service directory that are not searched for lockfiles, one per line. */
    lockfile_excludes: string;
    runtimes: RuntimeRow[];
    lockfile_uploads: File[];
    /** The directory of the upload at the same index, relative to the service directory; "" is the directory itself. */
    lockfile_directories: string[];
    removed_lockfiles: string[];
}

export interface RepositoryOption extends Option<string> {
    default_branch: string | null;
}

export interface ServiceFormOptions {
    projects: Option<number>[];
    /** The repositories the GitHub App may read, as last refreshed. */
    repositories: RepositoryOption[];
    repositoriesSyncedAt: string | null;
    gitHubConfigured: boolean;
    /** Directory names never searched for lockfiles (psm.github.lockfile_search.skip_directories). */
    lockfileSkippedDirectories: string[];
    kinds: Option<string>[];
    exposures: Option<string>[];
    technologies: TechnologyOption[];
}

export interface ExistingLockfile {
    /** Relative to the service directory, e.g. "frontend/package-lock.json". */
    path: string;
    source: 'upload' | 'github';
    commit: string | null;
    uploaded_at: string;
}

export interface LockfileSync {
    status: StatusDotData;
    synced_at: string | null;
    commit: string | null;
    commit_href: string | null;
    error: string | null;
}

/** The service as saved, for the state of its last sync. */
export interface SavedService {
    id: number;
    repository: string | null;
    repository_branch: string | null;
    lockfile_sync: LockfileSync | null;
}

/** A counter, not crypto.randomUUID(): that only exists on HTTPS or localhost, and the key only has to be unique on the page. */
let lastRuntimeRowKey = 0;

export function newRuntimeRow(technology: string | null = null, rawVersion = ''): RuntimeRow {
    lastRuntimeRowKey += 1;

    return { key: `runtime-${lastRuntimeRowKey}`, technology, raw_version: rawVersion };
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
    savedService,
}: {
    form: InertiaFormProps<ServiceFormData>;
    options: ServiceFormOptions;
    isCreating: boolean;
    existingLockfiles?: ExistingLockfile[];
    savedService?: SavedService;
}) {
    const t = useTranslation();
    const slug = useSlugFromName(isCreating, (value) => form.setData('slug', value));
    const errors = form.errors as Record<string, string | undefined>;
    const syncsLockfiles = form.data.repository !== null;

    return (
        <>
            <FormSection title={t('Thông tin service')} description={t('Service thuộc một project; cảnh báo gửi tới team sở hữu project.')}>
                <FormFields columns={2}>
                    <Field label={t('Project')} htmlFor="project_id" required error={form.errors.project_id} className="sm:col-span-2">
                        <Combobox
                            id="project_id"
                            value={form.data.project_id}
                            options={options.projects}
                            onChange={(projectId) => form.setData('project_id', projectId)}
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
                </FormFields>
            </FormSection>
            <RepositoryFields form={form} options={options} savedService={savedService} />
            <FormSection
                title={t('Môi trường production')}
                description={
                    syncsLockfiles
                        ? t('Chọn công nghệ và nhập version đang chạy. Lockfile lấy từ GitHub mỗi ngày, trong thư mục của service và các thư mục con. Lưu xong, hệ thống đánh giá lại service này.')
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
                            help={t('Service lấy lockfile từ GitHub thì không tải lên hay xóa tay được.')}
                        >
                            {existingLockfiles.length === 0 ? (
                                <p className="text-muted-foreground">{savedService?.repository === form.data.repository ? t('Chưa có lockfile nào trong thư mục của service và các thư mục con.') : t('Lấy ngay sau khi lưu.')}</p>
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

/**
 * The repository to read lockfiles from, picked from the repositories the GitHub App may read. The list only changes
 * when the refresh button asks GitHub again; the rest of the form keeps what was typed meanwhile.
 */
function RepositoryFields({ form, options, savedService }: { form: InertiaFormProps<ServiceFormData>; options: ServiceFormOptions; savedService?: SavedService }) {
    const t = useTranslation();
    const [isRefreshing, setIsRefreshing] = useState(false);
    const repository = form.data.repository;
    const selected = options.repositories.find((option) => option.value === repository);

    const repositoryOptions = [
        { value: '', label: t('Không dùng GitHub'), description: t('Tải lockfile tay') },
        // A repository picked before stays selectable after the App lost access to it.
        ...(repository !== null && selected === undefined ? [{ value: repository, label: repository, description: t('Không còn trong danh sách GitHub App được đọc') }] : []),
        ...options.repositories,
    ];

    const refreshRepositories = () =>
        router.post(
            syncRepositories.url(),
            {},
            {
                preserveState: true,
                preserveScroll: true,
                only: ['repositories', 'repositoriesSyncedAt'],
                onStart: () => setIsRefreshing(true),
                onFinish: () => setIsRefreshing(false),
            },
        );

    const listHelp = !options.gitHubConfigured
        ? t('Server chưa cấu hình GitHub App (PSM_GITHUB_* trong .env), chỉ tải lockfile tay được.')
        : options.repositoriesSyncedAt === null
          ? t('Chưa có danh sách. Bấm làm mới để lấy các repository GitHub App được đọc.')
          : t('Danh sách lấy lúc :time. Thiếu repository thì cấp quyền cho GitHub App trên GitHub rồi bấm làm mới.', { time: options.repositoriesSyncedAt });

    const isSaved = savedService !== undefined && savedService.repository === repository && (savedService.repository_branch ?? '') === form.data.repository_branch;

    return (
        <FormSection
            title={t('Repository')}
            description={t('Chọn repository GitHub để mỗi ngày hệ thống tự đọc composer.lock, Gemfile.lock, package-lock.json, pnpm-lock.yaml và yarn.lock trong thư mục của service và các thư mục con, tại commit mới nhất của branch. GitHub App chỉ có quyền đọc.')}
        >
            <FormFields columns={2}>
                <Field label={t('Repository GitHub')} htmlFor="repository" error={form.errors.repository} help={listHelp} className="sm:col-span-2">
                    <div className="flex gap-2">
                        <Combobox
                            id="repository"
                            className="min-w-0 flex-1"
                            value={repository ?? ''}
                            options={repositoryOptions}
                            onChange={(value) => {
                                const picked = value === '' ? null : value;

                                if (picked === repository) {
                                    return;
                                }

                                // A service that reads lockfiles from GitHub takes no uploads, so files picked before are dropped.
                                form.setData(
                                    picked === null
                                        ? { ...form.data, repository: null, repository_branch: '' }
                                        : { ...form.data, repository: picked, repository_branch: '', lockfile_uploads: [], lockfile_directories: [], removed_lockfiles: [] },
                                );
                            }}
                            placeholder={t('Chọn repository')}
                            searchPlaceholder={t('Tìm repository')}
                            invalid={form.errors.repository !== undefined}
                        />
                        <Button
                            type="button"
                            variant="secondary"
                            size="icon"
                            aria-label={t('Làm mới danh sách repository')}
                            title={t('Làm mới danh sách repository')}
                            disabled={!options.gitHubConfigured || isRefreshing}
                            onClick={refreshRepositories}
                        >
                            <RefreshCwIcon className={cn(isRefreshing && 'animate-spin')} />
                        </Button>
                    </div>
                </Field>
                <Field
                    label={t('Branch')}
                    htmlFor="repository_branch"
                    error={form.errors.repository_branch}
                    help={t('Để trống để dùng branch mặc định. Khi lưu, hệ thống kiểm tra branch có trên GitHub không.')}
                >
                    <Input
                        id="repository_branch"
                        className="font-mono text-[calc(var(--fs-sm)-0.5px)]"
                        placeholder={selected?.default_branch ?? 'main'}
                        value={form.data.repository_branch}
                        disabled={repository === null}
                        onChange={(event) => form.setData('repository_branch', event.target.value)}
                        aria-invalid={form.errors.repository_branch !== undefined}
                    />
                </Field>
                <Field
                    label={t('Thư mục trong repository')}
                    htmlFor="repository_path"
                    required
                    error={form.errors.repository_path}
                    help={t('Dùng cho monorepo. Để "/" nếu service nằm ở gốc repository.')}
                >
                    <Input
                        id="repository_path"
                        className="font-mono text-[calc(var(--fs-sm)-0.5px)]"
                        value={form.data.repository_path}
                        onChange={(event) => form.setData('repository_path', event.target.value)}
                        aria-invalid={form.errors.repository_path !== undefined}
                    />
                </Field>
                {repository !== null && (
                    <Field
                        label={t('Bỏ qua khi tìm lockfile')}
                        htmlFor="lockfile_excludes"
                        error={form.errors.lockfile_excludes}
                        help={t(
                            'Mỗi dòng một thư mục hoặc file, tính từ thư mục của service (vd. legacy, tools/old/package-lock.json). Luôn bỏ qua các thư mục tên :directories. Lockfile bị bỏ qua sẽ bị xóa khỏi service và finding của nó tự đóng.',
                            { directories: options.lockfileSkippedDirectories.join(', ') },
                        )}
                        className="sm:col-span-2"
                    >
                        <textarea
                            id="lockfile_excludes"
                            rows={3}
                            value={form.data.lockfile_excludes}
                            onChange={(event) => form.setData('lockfile_excludes', event.target.value)}
                            aria-invalid={form.errors.lockfile_excludes !== undefined}
                            className={cn(inputClassName, 'h-auto py-2 font-mono text-[calc(var(--fs-sm)-0.5px)]')}
                        />
                    </Field>
                )}
                {isSaved && savedService.lockfile_sync && (
                    <Field label={t('Lần đồng bộ gần nhất')} className="sm:col-span-2">
                        <LockfileSyncState serviceId={savedService.id} sync={savedService.lockfile_sync} />
                    </Field>
                )}
            </FormFields>
        </FormSection>
    );
}

function LockfileSyncState({ serviceId, sync }: { serviceId: number; sync: LockfileSync }) {
    const t = useTranslation();

    return (
        <div className="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border px-3 py-2.5">
            <div className="min-w-0">
                <StatusDot status={sync.status} className="items-baseline whitespace-normal" />
                {sync.commit && (
                    <span className="mt-0.5 block text-sm text-muted-foreground">
                        {t('Commit')}{' '}
                        {sync.commit_href ? (
                            <a href={sync.commit_href} target="_blank" rel="noreferrer noopener" className="font-mono text-link hover:underline hover:underline-offset-3">
                                {sync.commit}
                            </a>
                        ) : (
                            <span className="font-mono">{sync.commit}</span>
                        )}
                    </span>
                )}
                {sync.synced_at && <span className="mt-0.5 block text-sm text-muted-foreground">{t('Thành công gần nhất: :time', { time: sync.synced_at })}</span>}
                {sync.error && <span className="mt-0.5 block text-sm whitespace-pre-line text-danger-text">{sync.error}</span>}
            </div>
            <Button type="button" variant="secondary" size="sm" onClick={() => router.post(syncLockfiles.url(serviceId), {}, { preserveState: true, preserveScroll: true })}>
                <RefreshCwIcon />
                {t('Lấy lockfile ngay')}
            </Button>
        </div>
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
                    options={technologies.map((option) => ({ ...option, icon: <TechnologyLogo technology={option.value} /> }))}
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
 * Drop or pick lockfiles, each with its directory under the service directory, e.g. "frontend" for
 * frontend/package-lock.json. A file at the path of an existing one replaces it. Names and sizes are checked here so a
 * wrong file is flagged before uploading; the server checks again, the directory too.
 */
function LockfileUpload({ form, errors }: { form: InertiaFormProps<ServiceFormData>; errors: Record<string, string | undefined> }) {
    const t = useTranslation();
    const inputRef = useRef<HTMLInputElement>(null);
    const [isDragging, setIsDragging] = useState(false);
    const files = form.data.lockfile_uploads;
    const directories = form.data.lockfile_directories;

    // A file picked again for the service directory itself replaces the one picked before.
    const addFiles = (list: FileList | null) => {
        if (list === null) {
            return;
        }

        const added = Array.from(list);
        const kept = files.map((file, index) => ({ file, directory: directories[index] ?? '' })).filter(({ file, directory }) => directory !== '' || !added.some((other) => other.name === file.name));

        form.setData({ ...form.data, lockfile_uploads: [...kept.map(({ file }) => file), ...added], lockfile_directories: [...kept.map(({ directory }) => directory), ...added.map(() => '')] });
    };

    const removeFile = (removed: number) =>
        form.setData({
            ...form.data,
            lockfile_uploads: files.filter((_, index) => index !== removed),
            lockfile_directories: directories.filter((_, index) => index !== removed),
        });

    const fileProblem = (file: File): string | undefined => {
        if (!LOCKFILE_NAMES.includes(file.name)) {
            return t('Chỉ nhận composer.lock, Gemfile.lock, package-lock.json, pnpm-lock.yaml hoặc yarn.lock.');
        }

        if (file.size > MAX_LOCKFILE_BYTES) {
            return t('File lớn hơn 20 MB.');
        }

        return undefined;
    };

    return (
        <Field
            label={t('Tải lockfile')}
            help={t('composer.lock, Gemfile.lock, package-lock.json, pnpm-lock.yaml hoặc yarn.lock. Lockfile ở thư mục con thì ghi thư mục, vd. frontend. File cùng đường dẫn thay file cũ.')}
        >
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
                        const problem = fileProblem(file) ?? errors[`lockfile_uploads.${index}`] ?? errors[`lockfile_directories.${index}`];

                        return (
                            <li key={`${index}:${file.name}`} className="flex flex-wrap items-center gap-2 rounded-md border border-border px-3 py-2">
                                <FileIcon className="size-4 shrink-0 text-faint" aria-hidden />
                                <Input
                                    className="h-8 w-44 font-mono text-[calc(var(--fs-sm)-0.5px)]"
                                    placeholder={t('Thư mục (để trống nếu ở gốc)')}
                                    aria-label={t('Thư mục của :file', { file: file.name })}
                                    value={directories[index] ?? ''}
                                    onChange={(event) =>
                                        form.setData(
                                            'lockfile_directories',
                                            files.map((_, other) => (other === index ? event.target.value : (directories[other] ?? ''))),
                                        )
                                    }
                                    aria-invalid={errors[`lockfile_directories.${index}`] !== undefined}
                                />
                                <span className="font-mono text-sm">/{file.name}</span>
                                <span className="text-sm text-faint">{t(':size KB', { size: Math.ceil(file.size / 1024) })}</span>
                                {problem && <span className="text-sm text-danger-text">{problem}</span>}
                                <Button type="button" variant="ghost" size="icon-sm" className="ml-auto" aria-label={t('Bỏ file')} onClick={() => removeFile(index)}>
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
