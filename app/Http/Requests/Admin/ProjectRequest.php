<?php

namespace App\Http\Requests\Admin;

use App\Models\Project;
use App\Models\Team;
use App\Services\Repositories\RepositoryReader;
use App\Support\Slug;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProjectRequest extends FormRequest
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
        $project = $this->route('project');

        return [
            'team_id' => ['required', 'integer', Rule::exists(Team::class, 'id')],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:'.Slug::PATTERN, Rule::unique(Project::class)->ignore($project instanceof Project ? $project : null)],
            'repository_url' => ['nullable', 'string', 'max:255', 'regex:'.Project::REPOSITORY_URL_PATTERN],
            'repository_branch' => ['nullable', 'string', 'max:255', 'regex:'.Project::REPOSITORY_BRANCH_PATTERN, 'not_regex:/\.\./'],
            'syncs_lockfiles' => ['boolean'],
        ];
    }

    /**
     * Reading lockfiles needs a repository on the host the App is installed on.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->boolean('syncs_lockfiles') && ! app(RepositoryReader::class)->supports((string) $this->input('repository_url'))) {
                    $validator->errors()->add('syncs_lockfiles', __('Cần repository URL trên GitHub, ví dụ https://github.com/org/repo.'));
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
            'team_id' => __('Team sở hữu'),
            'name' => __('Tên project'),
            'slug' => __('Slug'),
            'repository_url' => __('Repository URL'),
            'repository_branch' => __('Branch'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => __('Chỉ dùng chữ thường, số và dấu gạch ngang.'),
            'repository_url.regex' => __('Dùng URL https:// hoặc git@host:org/repo.'),
            'repository_branch.regex' => __('Tên branch không hợp lệ.'),
            'repository_branch.not_regex' => __('Tên branch không hợp lệ.'),
        ];
    }
}
