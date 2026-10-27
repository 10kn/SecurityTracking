<?php

namespace App\Http\Requests\Admin;

use App\Enums\Ecosystem;
use App\Enums\Exposure;
use App\Enums\ServiceKind;
use App\Enums\Technology;
use App\Models\AccessibleRepository;
use App\Models\Lockfile;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\Service;
use App\Services\Repositories\RepositoryReader;
use App\Support\LockfilePath;
use App\Support\Slug;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * The service and what it runs in production: one version per technology and the uploaded lockfiles. A service that
 * reads its lockfiles from a GitHub repository takes no uploads.
 */
class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $service = $this->route('service');
        $syncsLockfiles = $this->filled('repository');

        return [
            'project_id' => ['required', 'integer', Rule::exists(Project::class, 'id')],
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:255', 'regex:'.Slug::PATTERN,
                Rule::unique(Service::class)->where('project_id', $this->integer('project_id'))->ignore($service instanceof Service ? $service : null),
            ],
            'kind' => ['required', Rule::enum(ServiceKind::class)],
            'exposure' => ['required', Rule::enum(Exposure::class)],
            'repository_path' => ['required', 'string', 'max:255'],
            // A repository picked before stays valid after it leaves the list, until another one is picked.
            'repository' => [
                'nullable', 'string', 'max:255', 'regex:'.Service::REPOSITORY_PATTERN,
                Rule::when($this->input('repository') !== ($service instanceof Service ? $service->repository : null), [Rule::exists(AccessibleRepository::class, 'full_name')]),
            ],
            'repository_branch' => ['nullable', 'string', 'max:255', 'regex:'.Service::REPOSITORY_BRANCH_PATTERN, 'not_regex:/\.\./'],
            // Only a service reading its lockfiles from GitHub searches for them.
            'lockfile_excludes' => [Rule::excludeIf(! $syncsLockfiles), 'nullable', 'string', 'max:5000', $this->lockfileExcludesRule()],
            'runtimes' => ['array'],
            'runtimes.*.technology' => ['required', 'distinct', Rule::enum(Technology::class)],
            'runtimes.*.raw_version' => ['required', 'string', 'max:'.Runtime::RAW_VERSION_MAX_LENGTH, 'regex:'.Runtime::RAW_VERSION_PATTERN],
            'lockfile_uploads' => ['array', Rule::prohibitedIf($syncsLockfiles)],
            'lockfile_uploads.*' => ['file', 'max:'.Lockfile::MAX_UPLOAD_KILOBYTES, $this->lockfileNameRule()],
            'lockfile_directories' => ['array'],
            'lockfile_directories.*' => ['nullable', 'string', 'max:'.LockfilePath::MAX_LENGTH],
            'removed_lockfiles' => ['array', Rule::prohibitedIf($syncsLockfiles)],
            'removed_lockfiles.*' => ['string'],
        ];
    }

    /**
     * A branch typed by hand must exist in the repository. It is checked on GitHub when the repository or the branch
     * changed, so a GitHub outage only blocks saving a new one.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $repository = $this->input('repository');
                $branch = $this->input('repository_branch');
                $service = $this->route('service');

                if (! is_string($repository) || ! is_string($branch) || $validator->errors()->hasAny(['repository', 'repository_branch'])) {
                    return;
                }

                if ($service instanceof Service && $service->repository === $repository && $service->repository_branch === $branch) {
                    return;
                }

                $repositoryReader = app(RepositoryReader::class);

                if (! $repositoryReader->isConfigured()) {
                    $validator->errors()->add('repository_branch', __('Server chưa cấu hình GitHub App nên không kiểm tra được branch.'));

                    return;
                }

                try {
                    $branchExists = $repositoryReader->branchExists($repository, $branch);
                } catch (Throwable $exception) {
                    report($exception);
                    $validator->errors()->add('repository_branch', __('Không kiểm tra được branch trên :source, thử lại sau: :error', [
                        'source' => $repositoryReader->sourceName(),
                        'error' => $exception->getMessage(),
                    ]));

                    return;
                }

                if (! $branchExists) {
                    $validator->errors()->add('repository_branch', __('Không thấy branch :branch trong :repository.', ['branch' => $branch, 'repository' => $repository]));
                }
            },
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['lockfile_uploads', 'lockfile_uploads.*', 'lockfile_directories', 'lockfile_directories.*', 'removed_lockfiles', 'removed_lockfiles.*'])) {
                    return;
                }

                $service = $this->route('service');
                $existingPaths = $service instanceof Service ? $service->productionDeployment?->lockfiles()->pluck('path')->all() ?? [] : [];
                $removedPaths = Arr::wrap($this->input('removed_lockfiles', []));

                foreach (array_diff($removedPaths, $existingPaths) as $index => $path) {
                    $validator->errors()->add("removed_lockfiles.{$index}", __('Service không có lockfile :file.', ['file' => $path]));
                }

                $paths = [];

                foreach (array_keys(Arr::wrap($this->file('lockfile_uploads', []))) as $index) {
                    $path = $this->uploadedLockfilePath($index);

                    if (! LockfilePath::isValid($path)) {
                        $validator->errors()->add("lockfile_directories.{$index}", __('Thư mục chỉ gồm chữ, số và . _ @ + -, các cấp ngăn bằng "/", không có ".." (tối đa 255 ký tự).'));
                    } elseif (in_array($path, $paths, true)) {
                        $validator->errors()->add("lockfile_directories.{$index}", __('Đã chọn một file khác cho :file.', ['file' => $path]));
                    } elseif (in_array($path, $removedPaths, true)) {
                        $validator->errors()->add("lockfile_directories.{$index}", __('Không vừa xóa vừa tải lên :file. Bỏ dấu xóa để thay file cũ.', ['file' => $path]));
                    }

                    $paths[] = $path;
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'project_id' => __('Project'),
            'name' => __('Tên service'),
            'slug' => __('Slug'),
            'kind' => __('Loại'),
            'exposure' => __('Phạm vi truy cập'),
            'repository_path' => __('Thư mục trong repository'),
            'repository' => __('Repository'),
            'repository_branch' => __('Branch'),
            'runtimes.*.technology' => __('Công nghệ'),
            'runtimes.*.raw_version' => __('Version'),
            'lockfile_uploads.*' => __('Lockfile'),
            'lockfile_directories.*' => __('Thư mục'),
            'lockfile_excludes' => __('Bỏ qua khi tìm lockfile'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => __('Chỉ dùng chữ thường, số và dấu gạch ngang.'),
            'runtimes.*.raw_version.regex' => __('Version chỉ gồm chữ, số và . + ~ _ -'),
            'runtimes.*.technology.distinct' => __('Mỗi công nghệ chỉ chọn một lần.'),
            'repository.regex' => __('Chọn repository trong danh sách.'),
            'repository.exists' => __('Repository không có trong danh sách GitHub App được đọc. Làm mới danh sách rồi chọn lại.'),
            'repository_branch.regex' => __('Tên branch không hợp lệ.'),
            'repository_branch.not_regex' => __('Tên branch không hợp lệ.'),
            'lockfile_uploads.prohibited' => __('Service lấy lockfile từ GitHub thì không tải lên hay xóa tay được.'),
            'removed_lockfiles.prohibited' => __('Service lấy lockfile từ GitHub thì không tải lên hay xóa tay được.'),
        ];
    }

    /**
     * The columns of the service itself. Without a repository there is no branch to read.
     *
     * @return array<string, mixed>
     */
    public function serviceAttributes(): array
    {
        $attributes = Arr::only($this->validated(), ['project_id', 'name', 'slug', 'kind', 'exposure', 'repository_path', 'repository', 'repository_branch']);
        $excludes = self::lines($this->validated('lockfile_excludes'));

        return [
            ...$attributes,
            'repository' => $attributes['repository'] ?? null,
            'repository_branch' => isset($attributes['repository']) ? ($attributes['repository_branch'] ?? null) : null,
            'lockfile_excludes' => isset($attributes['repository']) && $excludes !== [] ? $excludes : null,
        ];
    }

    /**
     * Raw versions keyed by technology, e.g. "php".
     *
     * @return array<string, string>
     */
    public function runtimeVersions(): array
    {
        return Arr::pluck(Arr::wrap($this->validated('runtimes', [])), 'raw_version', 'technology');
    }

    /**
     * Each upload sits at its directory (`lockfile_directories` at the same index) under the service directory.
     *
     * @return list<array{path: string, content: string}>
     */
    public function uploadedLockfiles(): array
    {
        $files = Arr::wrap($this->file('lockfile_uploads', []));

        return array_map(
            fn (int|string $index): array => ['path' => $this->uploadedLockfilePath($index), 'content' => (string) $files[$index]->get()],
            array_keys($files),
        );
    }

    /**
     * @return list<string>
     */
    public function removedLockfilePaths(): array
    {
        return array_values($this->validated('removed_lockfiles', []));
    }

    /**
     * E.g. "frontend/package-lock.json" for package-lock.json uploaded with the directory "frontend".
     */
    private function uploadedLockfilePath(int|string $index): string
    {
        $file = Arr::wrap($this->file('lockfile_uploads', []))[$index] ?? null;
        $directory = Arr::wrap($this->input('lockfile_directories', []))[$index] ?? null;

        return LockfilePath::join(is_string($directory) ? $directory : null, $file instanceof UploadedFile ? $file->getClientOriginalName() : '');
    }

    /**
     * The non-blank lines of a textarea, without slashes around them, each once.
     *
     * @return list<string>
     */
    private static function lines(mixed $text): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn (string $line): string => trim(trim($line), '/'), preg_split('/\R/', is_string($text) ? $text : '') ?: []),
            fn (string $line): bool => $line !== '',
        )));
    }

    /**
     * One path per line, relative to the service directory, following the rules of lockfile paths (any name).
     */
    private function lockfileExcludesRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            foreach (self::lines($value) as $line) {
                $segments = explode('/', $line);

                if (mb_strlen($line) > LockfilePath::MAX_LENGTH || array_filter($segments, fn (string $segment): bool => in_array($segment, ['.', '..'], true) || preg_match(LockfilePath::SEGMENT_PATTERN, $segment) !== 1) !== []) {
                    $fail(__('":path" không hợp lệ: mỗi dòng là một đường dẫn trong thư mục service, chỉ gồm chữ, số và . _ @ + -, không có "..".', ['path' => $line]));

                    return;
                }
            }

            if (count(self::lines($value)) > 50) {
                $fail(__('Tối đa 50 đường dẫn.'));
            }
        };
    }

    private function lockfileNameRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile || Ecosystem::forLockfile($value->getClientOriginalName()) === null) {
                $fail(__('Chỉ nhận composer.lock, Gemfile.lock hoặc package-lock.json.'));
            }
        };
    }
}
