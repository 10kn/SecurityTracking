<?php

namespace App\Http\Requests\Admin;

use App\Enums\Ecosystem;
use App\Enums\Exposure;
use App\Enums\ServiceKind;
use App\Enums\Technology;
use App\Models\Lockfile;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\Service;
use App\Support\Slug;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * The service and what it runs in production: one version per technology and the uploaded lockfiles. A project that
 * reads its lockfiles from GitHub takes no uploads.
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
        $projectSyncsLockfiles = Project::whereKey($this->integer('project_id'))->where('syncs_lockfiles', true)->exists();

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
            'runtimes' => ['array'],
            'runtimes.*.technology' => ['required', 'distinct', Rule::enum(Technology::class)],
            'runtimes.*.raw_version' => ['required', 'string', 'max:'.Runtime::RAW_VERSION_MAX_LENGTH, 'regex:'.Runtime::RAW_VERSION_PATTERN],
            'lockfile_uploads' => ['array', Rule::prohibitedIf($projectSyncsLockfiles)],
            'lockfile_uploads.*' => ['file', 'max:'.Lockfile::MAX_UPLOAD_KILOBYTES, $this->lockfileNameRule()],
            'removed_lockfiles' => ['array', Rule::prohibitedIf($projectSyncsLockfiles)],
            'removed_lockfiles.*' => ['string'],
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
            'lockfile_uploads.prohibited' => __('Project này lấy lockfile từ GitHub, không tải lên hay xóa tay được.'),
            'removed_lockfiles.prohibited' => __('Project này lấy lockfile từ GitHub, không tải lên hay xóa tay được.'),
        ];
    }

    /**
     * The columns of the service itself.
     *
     * @return array<string, mixed>
     */
    public function serviceAttributes(): array
    {
        return Arr::only($this->validated(), ['project_id', 'name', 'slug', 'kind', 'exposure', 'repository_path']);
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
