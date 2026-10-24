<?php

namespace App\Http\Requests\Admin;

use App\Models\Project;
use App\Models\Team;
use App\Support\Slug;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        ];
    }
}
