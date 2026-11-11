<?php

namespace App\Services\ProjectImport;

use App\Enums\Exposure;
use App\Enums\ImportRowStatus;
use App\Enums\Locale;
use App\Enums\OperatingSystem;
use App\Enums\ServiceKind;
use App\Enums\Technology;
use App\Models\AccessibleRepository;
use App\Models\Project;
use App\Models\Runtime;
use App\Models\Service;
use App\Models\Team;
use App\Services\Repositories\GitHubRepository;
use App\Services\Repositories\RepositoryReader;
use App\Support\Slug;
use BackedEnum;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Throwable;

/**
 * Checks the rows of an import file against the same rules as the service form (ServiceRequest) and works out what
 * confirming it creates:
 *
 * - rows with the same team and project name (spaces and case aside) make one project; a project of that team with
 *   that name already gets the services instead;
 * - a service is named after its repository, with the directory for one of a monorepo, e.g. "shop (apps/api)";
 * - slugs come from the names, with "-2", "-3"… when taken, e.g. "shop-apps-api" for "shop (apps/api)";
 * - a service whose repository and directory, or whose name, its project already has is skipped, nothing existing is
 *   changed.
 *
 * Dropdown values are read in any language of the application, or as their stored value, e.g. "server".
 */
class ImportPlanner
{
    private const int MAX_NAME_LENGTH = 255;

    /** @var array<string, array{label: string, required: bool, help: string}> */
    private array $columns = [];

    /** @var array<string, list<Team>> */
    private array $teamsByName = [];

    /** @var array<string, Team> */
    private array $teamsBySlug = [];

    /** @var array<string, ServiceKind> */
    private array $kinds = [];

    /** @var array<string, Exposure> */
    private array $exposures = [];

    /** @var array<string, OperatingSystem> */
    private array $operatingSystems = [];

    /** @var array<string, Technology> */
    private array $technologies = [];

    /** @var array<string, string> Full names keyed by their lowercase form, e.g. "acme/shop". */
    private array $repositories = [];

    /** @var array<string, string|null> What is wrong with a branch, keyed by "repository#branch"; null when it exists. */
    private array $branchProblems = [];

    public function __construct(private RepositoryReader $repositoryReader) {}

    /**
     * @param  list<array{row: int, cells: array<string, string>}>  $rows  As read by ImportSheetReader.
     */
    public function plan(array $rows): ImportPlan
    {
        $this->load();

        $checkedRows = array_map(fn (array $row): array => $this->check($row['row'], $row['cells']), $rows);

        return new ImportPlan($this->group($checkedRows));
    }

    private function load(): void
    {
        $this->columns = ImportSheet::columns();
        $this->teamsByName = [];
        $this->teamsBySlug = [];

        foreach (Team::orderBy('id')->get(['id', 'name', 'slug']) as $team) {
            $this->teamsByName[self::key($team->name)][] = $team;
            $this->teamsBySlug[self::key($team->slug)] = $team;
        }

        $this->kinds = self::labelMap(ServiceKind::cases());
        $this->exposures = self::labelMap(Exposure::cases());
        $this->operatingSystems = self::labelMap(OperatingSystem::cases());
        $this->technologies = self::labelMap(Technology::cases());
        $this->repositories = AccessibleRepository::pluck('full_name')->keyBy(fn (string $fullName): string => mb_strtolower($fullName))->all();
    }

    /**
     * The values of one row and what is wrong with them, before rows are grouped into projects.
     *
     * @param  array<string, string>  $cells
     * @return array{row: int, team: Team|null, team_name: string, project_name: string, service_name: string, kind: ServiceKind|null, exposure: Exposure|null, os: OperatingSystem|null, runtime_versions: array<string, string>, repository: string|null, repository_branch: string|null, repository_path: string, errors: list<string>}
     */
    private function check(int $rowNumber, array $cells): array
    {
        $errors = [];
        $cell = fn (string $column): string => $cells[$column] ?? '';

        $team = $this->team($cell(ImportSheet::TEAM), $errors);

        if ($cell(ImportSheet::PROJECT) === '') {
            $errors[] = $this->missing(ImportSheet::PROJECT);
        } elseif (mb_strlen($cell(ImportSheet::PROJECT)) > self::MAX_NAME_LENGTH) {
            $errors[] = $this->tooLong(ImportSheet::PROJECT);
        }

        $repository = $this->repository($cell(ImportSheet::REPOSITORY), $errors);
        $branch = $this->branch($cell(ImportSheet::BRANCH), $repository, $errors);
        $repositoryPath = $cell(ImportSheet::REPOSITORY_PATH) === '' ? '/' : $cell(ImportSheet::REPOSITORY_PATH);
        $serviceName = $repository === null ? '' : self::serviceName($repository, $repositoryPath);

        if (mb_strlen($repositoryPath) > self::MAX_NAME_LENGTH || mb_strlen($serviceName) > self::MAX_NAME_LENGTH) {
            $errors[] = $this->tooLong(ImportSheet::REPOSITORY_PATH);
        }

        $kind = $this->listValue($cell(ImportSheet::KIND), ImportSheet::KIND, $this->kinds, $errors, required: true);
        $exposure = $this->listValue($cell(ImportSheet::EXPOSURE), ImportSheet::EXPOSURE, $this->exposures, $errors, required: true);
        $os = $this->listValue($cell(ImportSheet::OS), ImportSheet::OS, $this->operatingSystems, $errors, required: false);
        $runtimeVersions = $this->runtimeVersions($cell, $errors);

        return [
            'row' => $rowNumber,
            'team' => $team,
            'team_name' => $cell(ImportSheet::TEAM),
            'project_name' => $cell(ImportSheet::PROJECT),
            'service_name' => $serviceName,
            'kind' => $kind,
            'exposure' => $exposure,
            'os' => $os,
            'runtime_versions' => $runtimeVersions,
            'repository' => $repository,
            'repository_branch' => $branch,
            'repository_path' => $repositoryPath,
            'errors' => $errors,
        ];
    }

    /**
     * Groups the checked rows into projects, finds what already exists and gives each new project and service its slug.
     *
     * @param  list<array<string, mixed>>  $checkedRows
     * @return list<ImportRow>
     */
    private function group(array $checkedRows): array
    {
        $teamIds = array_values(array_unique(array_filter(array_map(fn (array $row): ?int => $row['team']?->id, $checkedRows))));
        $existingProjects = [];

        foreach (Project::whereIn('team_id', $teamIds)->with('services:id,project_id,name,slug,repository,repository_path')->orderBy('id')->get() as $project) {
            $existingProjects[$project->team_id.'|'.self::key($project->name)] ??= $project;
        }

        $groups = [];
        $takenProjectSlugs = [];
        $rows = [];

        foreach ($checkedRows as $checked) {
            /** @var Team|null $team */
            $team = $checked['team'];

            if ($team === null || $checked['project_name'] === '' || $checked['repository'] === null || mb_strlen($checked['project_name']) > self::MAX_NAME_LENGTH) {
                $rows[] = new ImportRow($checked['row'], ImportRowStatus::Invalid, $checked['team_name'], $checked['project_name'], $checked['service_name'], $checked['errors']);

                continue;
            }

            $projectKey = $team->id.'|'.self::key($checked['project_name']);

            if (! isset($groups[$projectKey])) {
                $project = $existingProjects[$projectKey] ?? null;
                $slug = $project->slug ?? Slug::unique(
                    Slug::from($checked['project_name'], 'project'),
                    fn (string $slug): bool => isset($takenProjectSlugs[$slug]) || Project::where('slug', $slug)->exists(),
                );
                $takenProjectSlugs[$slug] = true;

                $groups[$projectKey] = [
                    'project' => $project,
                    'name' => $project->name ?? $checked['project_name'],
                    'slug' => $slug,
                    'service_slugs' => $project?->services->pluck('slug')->flip()->all() ?? [],
                    'existing_repositories' => $project?->services->filter(fn (Service $service): bool => $service->repository !== null)
                        ->keyBy(fn (Service $service): string => self::serviceKey($service->repository, $service->repository_path))->all() ?? [],
                    'existing_names' => $project?->services->keyBy(fn (Service $service): string => self::key($service->name))->all() ?? [],
                    'rows' => [],
                ];
            }

            $group = $groups[$projectKey];
            $serviceKey = self::serviceKey($checked['repository'], $checked['repository_path']);
            $errors = $checked['errors'];

            if (isset($group['rows'][$serviceKey])) {
                $errors[] = __('Trùng repository và thư mục với dòng :row.', ['row' => $group['rows'][$serviceKey]]);
            } else {
                $groups[$projectKey]['rows'][$serviceKey] = $checked['row'];
            }

            $status = match (true) {
                $errors !== [] => ImportRowStatus::Invalid,
                isset($group['existing_repositories'][$serviceKey]), isset($group['existing_names'][self::key($checked['service_name'])]) => ImportRowStatus::Skipped,
                $group['project'] !== null => ImportRowStatus::ExistingProject,
                default => ImportRowStatus::NewProject,
            };

            if (! $status->createsService()) {
                $rows[] = new ImportRow($checked['row'], $status, $team->name, $group['name'], $checked['service_name'], $errors);

                continue;
            }

            $serviceSlug = Slug::unique(Slug::from(str_replace('/', ' ', $checked['service_name']), 'service'), fn (string $slug): bool => isset($group['service_slugs'][$slug]));
            $groups[$projectKey]['service_slugs'][$serviceSlug] = true;

            $rows[] = new ImportRow(
                row: $checked['row'],
                status: $status,
                teamName: $team->name,
                projectName: $group['name'],
                serviceName: $checked['service_name'],
                teamId: $team->id,
                projectKey: $projectKey,
                projectId: $group['project']?->id,
                projectSlug: $group['slug'],
                serviceSlug: $serviceSlug,
                kind: $checked['kind'],
                exposure: $checked['exposure'],
                os: $checked['os'],
                runtimeVersions: $checked['runtime_versions'],
                repository: $checked['repository'],
                repositoryBranch: $checked['repository_branch'],
                repositoryPath: $checked['repository_path'],
            );
        }

        return $rows;
    }

    /**
     * The team by its name, or by its slug when two teams share the name.
     *
     * @param  list<string>  $errors
     */
    private function team(string $text, array &$errors): ?Team
    {
        if ($text === '') {
            $errors[] = $this->missing(ImportSheet::TEAM);

            return null;
        }

        $teams = $this->teamsByName[self::key($text)] ?? (isset($this->teamsBySlug[self::key($text)]) ? [$this->teamsBySlug[self::key($text)]] : []);

        if (count($teams) === 1) {
            return $teams[0];
        }

        $errors[] = $teams === []
            ? __('Không có team ":value".', ['value' => $text])
            : __('Có nhiều team tên ":value". Ghi slug của team thay cho tên.', ['value' => $text]);

        return null;
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  array<string, TEnum>  $values
     * @param  list<string>  $errors
     * @return TEnum|null
     */
    private function listValue(string $text, string $column, array $values, array &$errors, bool $required): ?BackedEnum
    {
        if ($text === '') {
            if ($required) {
                $errors[] = $this->missing($column);
            }

            return null;
        }

        return $values[self::key($text)] ?? $this->notInList($text, $column, $errors);
    }

    /**
     * Raw versions keyed by technology from the three technology/version pairs.
     *
     * @param  callable(string): string  $cell
     * @param  list<string>  $errors
     * @return array<string, string>
     */
    private function runtimeVersions(callable $cell, array &$errors): array
    {
        $versions = [];

        foreach (ImportSheet::TECHNOLOGY_SLOTS as [$technologyColumn, $versionColumn]) {
            $technologyText = $cell($technologyColumn);
            $version = $cell($versionColumn);

            if ($technologyText === '' && $version === '') {
                continue;
            }

            if ($technologyText === '') {
                $errors[] = __('Chọn :column cho version ":version".', ['column' => $this->label($technologyColumn), 'version' => $version]);

                continue;
            }

            $technology = $this->technologies[self::key($technologyText)] ?? $this->notInList($technologyText, $technologyColumn, $errors);

            if ($technology === null) {
                continue;
            }

            if ($version === '') {
                $errors[] = __('Thiếu :column của :technology.', ['column' => $this->label($versionColumn), 'technology' => $technology->getLabel()]);
            } elseif (isset($versions[$technology->value])) {
                $errors[] = __('Mỗi công nghệ chỉ chọn một lần.');
            } elseif (mb_strlen($version) > Runtime::RAW_VERSION_MAX_LENGTH || preg_match(Runtime::RAW_VERSION_PATTERN, $version) !== 1) {
                $errors[] = __(':column ":value": version chỉ gồm chữ, số và . + ~ _ - (tối đa :max ký tự).', ['column' => $this->label($versionColumn), 'value' => $version, 'max' => Runtime::RAW_VERSION_MAX_LENGTH]);
            } else {
                $versions[$technology->value] = $version;
            }
        }

        return $versions;
    }

    /**
     * The full name of a repository the GitHub App reads, as GitHub writes it, from a link or "owner/repo".
     *
     * @param  list<string>  $errors
     */
    private function repository(string $text, array &$errors): ?string
    {
        if ($text === '') {
            $errors[] = $this->missing(ImportSheet::REPOSITORY);

            return null;
        }

        $repository = GitHubRepository::fromUrl($text);

        if ($repository === null) {
            $errors[] = __('Không đọc được repository ":value". Ghi link dạng :example hoặc owner/repo.', ['value' => $text, 'example' => 'https://'.config('psm.github.host').'/acme/shop']);

            return null;
        }

        $fullName = $this->repositories[mb_strtolower("{$repository->owner}/{$repository->name}")] ?? null;

        if ($fullName === null) {
            $errors[] = __('Repository :repository không có trong danh sách GitHub App được đọc. Làm mới danh sách ở form service rồi import lại.', ['repository' => "{$repository->owner}/{$repository->name}"]);
        }

        return $fullName;
    }

    /**
     * A branch must exist in the repository (checked once per repository and branch).
     *
     * @param  list<string>  $errors
     */
    private function branch(string $text, ?string $repository, array &$errors): ?string
    {
        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > self::MAX_NAME_LENGTH || preg_match(Service::REPOSITORY_BRANCH_PATTERN, $text) !== 1 || str_contains($text, '..')) {
            $errors[] = __('Tên branch không hợp lệ.');
        } elseif ($repository !== null && ($problem = $this->branchProblem($repository, $text)) !== null) {
            $errors[] = $problem;
        }

        return $text;
    }

    private function branchProblem(string $repository, string $branch): ?string
    {
        $key = "{$repository}#{$branch}";

        if (array_key_exists($key, $this->branchProblems)) {
            return $this->branchProblems[$key];
        }

        if (! $this->repositoryReader->isConfigured()) {
            return $this->branchProblems[$key] = __('Server chưa cấu hình GitHub App nên không kiểm tra được branch.');
        }

        try {
            $exists = $this->repositoryReader->branchExists($repository, $branch);
        } catch (Throwable $exception) {
            report($exception);

            // Not remembered: the next preview asks GitHub again.
            return __('Không kiểm tra được branch trên :source, thử lại sau: :error', ['source' => $this->repositoryReader->sourceName(), 'error' => $exception->getMessage()]);
        }

        return $this->branchProblems[$key] = $exists ? null : __('Không thấy branch :branch trong :repository.', ['branch' => $branch, 'repository' => $repository]);
    }

    private function missing(string $column): string
    {
        return __('Thiếu :column.', ['column' => $this->label($column)]);
    }

    private function tooLong(string $column): string
    {
        return __(':column tối đa :max ký tự.', ['column' => $this->label($column), 'max' => self::MAX_NAME_LENGTH]);
    }

    /**
     * @param  list<string>  $errors
     */
    private function notInList(string $text, string $column, array &$errors): null
    {
        $errors[] = __('":value" không có trong danh sách :column.', ['value' => $text, 'column' => $this->label($column)]);

        return null;
    }

    private function label(string $column): string
    {
        return $this->columns[$column]['label'];
    }

    /**
     * The cases keyed by their label in every language of the application and by their value.
     *
     * @template TEnum of BackedEnum
     *
     * @param  list<TEnum>  $cases
     * @return array<string, TEnum>
     */
    private static function labelMap(array $cases): array
    {
        $map = [];
        $currentLocale = App::getLocale();

        try {
            foreach (Locale::cases() as $locale) {
                App::setLocale($locale->value);

                foreach ($cases as $case) {
                    $map[self::key($case->getLabel())] = $case;
                }
            }
        } finally {
            App::setLocale($currentLocale);
        }

        foreach ($cases as $case) {
            $map[self::key((string) $case->value)] = $case;
        }

        return $map;
    }

    /**
     * The name of the repository, with the directory for a service that is not at its root, e.g. "shop (apps/api)" for
     * "acme/shop" and "/apps/api/".
     */
    private static function serviceName(string $repository, string $repositoryPath): string
    {
        $name = Str::after($repository, '/');
        $directory = trim($repositoryPath, '/');

        return $directory === '' ? $name : "{$name} ({$directory})";
    }

    /**
     * One service per repository and directory, e.g. "acme/shop#apps/api" for "Acme/Shop" and "/apps/api/".
     */
    private static function serviceKey(string $repository, string $repositoryPath): string
    {
        return mb_strtolower($repository).'#'.trim($repositoryPath, '/');
    }

    /**
     * Compares names without surrounding or repeated spaces and case, e.g. " Cổng  Thanh toán" and "cổng thanh toán".
     */
    private static function key(string $text): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($text)) ?? '');
    }
}
