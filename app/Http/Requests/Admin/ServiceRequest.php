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
            'runtimes' => ['array'],
            'runtimes.*.technology' => ['required', 'distinct', Rule::enum(Technology::class)],
            'runtimes.*.raw_version' => ['required', 'string', 'max:'.Runtime::RAW_VERSION_MAX_LENGTH, 'regex:'.Runtime::RAW_VERSION_PATTERN],
            'lockfile_uploads' => ['array', Rule::prohibitedIf($syncsLockfiles)],
            'lockfile_uploads.*' => ['file', 'max:'.Lockfile::MAX_UPLOAD_KILOBYTES, $this->lockfileNameRule()],
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

        return [
            ...$attributes,
            'repository' => $attributes['repository'] ?? null,
            'repository_branch' => isset($attributes['repository']) ? ($attributes['repository_branch'] ?? null) : null,
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
     * @return list<array{name: string, content: string}>
     */
    public function uploadedLockfiles(): array
    {
        return array_values(array_map(
            fn (UploadedFile $file): array => ['name' => $file->getClientOriginalName(), 'content' => (string) $file->get()],
            Arr::wrap($this->file('lockfile_uploads', [])),
        ));
    }

    /**
     * @return list<string>
     */
    public function removedLockfilePaths(): array
    {
        return array_values($this->validated('removed_lockfiles', []));
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
