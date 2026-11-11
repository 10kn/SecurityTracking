<?php

namespace App\Services;

use App\Enums\BranchSupport;
use App\Enums\Ecosystem;
use App\Enums\FindingRule;
use App\Enums\Severity;
use App\Enums\Technology;
use App\Models\Component;
use App\Models\Deployment;
use App\Models\GitHubActionVersion;
use App\Models\GitHubRunnerImage;
use App\Models\Lockfile;
use App\Models\RuntimeBranch;
use App\Services\Lockfiles\GitHubWorkflowParser;
use App\Services\Lockfiles\UnreadableLockfileException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Findings that break workflows rather than expose them (GitHub Actions, group 2): a runner image GitHub removed or
 * removes within `psm.github_actions.runner_warning_days`, one finding per image, and an action that runs on a Node.js
 * branch past its end of life, one per action and Node.js version. Both are at `psm.github_actions.lifecycle_severity`
 * and their deadline is the day the image goes or the branch went.
 */
class WorkflowFindings
{
    /**
     * @var list<FindingRule>
     */
    public const array RULES = [FindingRule::RunnerImageEol, FindingRule::ActionRuntimeEol];

    public function __construct(private GitHubWorkflowParser $parser) {}

    /**
     * @param  Collection<string, GitHubRunnerImage>  $images  By name.
     * @param  Collection<string, RuntimeBranch>  $nodeBranches  By branch, e.g. "20".
     * @return list<FindingData>
     */
    public function for(Deployment $deployment, Collection $images, Collection $nodeBranches): array
    {
        $workflows = $deployment->lockfiles->filter(fn (Lockfile $lockfile): bool => $lockfile->ecosystem === Ecosystem::GitHubActions);
        $actions = $deployment->components->filter(fn (Component $component): bool => $component->ecosystem === Ecosystem::GitHubActions);

        return [
            ...$this->runnerFindings($deployment->id, $workflows, $images),
            ...$this->runtimeFindings($deployment->id, $actions, $nodeBranches),
        ];
    }

    /**
     * @param  Collection<int, Lockfile>  $workflows
     * @param  Collection<string, GitHubRunnerImage>  $images
     * @return list<FindingData>
     */
    private function runnerFindings(int $deploymentId, Collection $workflows, Collection $images): array
    {
        $warnUntil = today()->addDays((int) config('psm.github_actions.runner_warning_days'));
        $usages = [];

        foreach ($workflows as $workflow) {
            try {
                $labels = $this->parser->runnerLabels($workflow->content);
            } catch (UnreadableLockfileException) {
                continue;
            }

            foreach ($labels as $label) {
                $image = GitHubRunnerImage::forLabel($label, $images);
                $removalDate = $image?->removalDate();

                if ($image !== null && $removalDate !== null && $removalDate->lte($warnUntil)) {
                    $usages[$image->name]['image'] = $image;
                    $usages[$image->name]['labels'][] = $label;
                    $usages[$image->name]['paths'][] = $workflow->path;
                }
            }
        }

        $locale = config('psm.alerts.locale');
        $rule = FindingRule::RunnerImageEol;
        $findings = [];

        foreach ($usages as $name => ['image' => $image, 'labels' => $labels, 'paths' => $paths]) {
            $removalDate = Carbon::parse($image->removalDate()?->toDateString(), config('psm.digest.timezone'));
            $labels = collect($labels)->unique()->sort()->values();
            $paths = collect($paths)->unique()->sort()->values();
            $isRemoved = $removalDate->lte(today());

            $context = [
                'runner' => $labels->join(', '),
                'image' => $name,
                'date' => $removalDate->toDateString(),
                'is_removed' => $isRemoved,
                'lockfile' => $paths->first(),
                'lockfiles' => $paths->all(),
                'source_url' => 'https://endoflife.date/'.GitHubRunnerImage::ENDOFLIFE_PRODUCT,
                'detail' => collect([
                    $isRemoved
                        ? __('Workflow chạy trên runner này không còn chạy được', [], $locale)
                        : __('Đổi sang runner mới hơn trước ngày gỡ', [], $locale),
                    $paths->join(', '),
                ])->join(' · '),
            ];

            $findings[] = new FindingData(
                fingerprint: FindingData::fingerprint($rule, $deploymentId, "runner:{$name}", $name),
                rule: $rule,
                severity: $this->severity(),
                title: Str::limit((string) $rule->title($context, $locale), 250),
                groupKey: "{$rule->value}:{$name}",
                context: $context,
                deadline: $removalDate,
            );
        }

        return $findings;
    }

    /**
     * @param  Collection<int, Component>  $actions
     * @param  Collection<string, RuntimeBranch>  $nodeBranches
     * @return list<FindingData>
     */
    private function runtimeFindings(int $deploymentId, Collection $actions, Collection $nodeBranches): array
    {
        $versions = GitHubActionVersion::forReferences($actions->map(fn (Component $component): array => [$component->name, $component->version]));
        $usages = [];

        foreach ($actions as $action) {
            $version = $versions->get("{$action->name}@{$action->version}");
            $major = $version?->nodeMajor();
            $branch = $major === null ? null : $nodeBranches->get((string) $major);

            if ($branch === null || $branch->support() !== BranchSupport::Eol) {
                continue;
            }

            $key = "{$action->name}|{$major}";
            $usages[$key]['component'] ??= $action;
            $usages[$key]['branch'] = $branch;
            $usages[$key]['references'][] = GitHubActionVersion::describe($action->name, $action->version, $version->version);
            $usages[$key]['paths'][] = $action->lockfile_path;
        }

        $locale = config('psm.alerts.locale');
        $rule = FindingRule::ActionRuntimeEol;
        $findings = [];

        foreach ($usages as ['component' => $component, 'branch' => $branch, 'references' => $references, 'paths' => $paths]) {
            $references = collect($references)->unique()->sort()->values();
            $paths = collect($paths)->unique()->sort()->values();
            $endDate = $branch->security_support_until;

            $context = [
                'ecosystem' => Ecosystem::GitHubActions->value,
                'package' => $component->name,
                'action' => $references->join(', '),
                'node' => $branch->branch,
                'date' => $endDate?->toDateString(),
                'lockfile' => $paths->first(),
                'lockfiles' => $paths->all(),
                'source_url' => Technology::Nodejs->endoflifeUrl(),
                'detail' => collect([
                    __('Nâng action lên bản chạy trên Node.js còn hỗ trợ', [], $locale),
                    $paths->join(', '),
                ])->join(' · '),
            ];

            $findings[] = new FindingData(
                fingerprint: FindingData::fingerprint($rule, $deploymentId, "action:{$component->name}", "node{$branch->branch}"),
                rule: $rule,
                severity: $this->severity(),
                title: Str::limit((string) $rule->title($context, $locale), 250),
                groupKey: "{$rule->value}:{$component->name}:node{$branch->branch}",
                context: $context,
                componentId: $component->id,
                deadline: $endDate === null ? null : Carbon::parse($endDate->toDateString(), config('psm.digest.timezone')),
            );
        }

        return $findings;
    }

    private function severity(): Severity
    {
        return Severity::tryFrom((string) config('psm.github_actions.lifecycle_severity')) ?? Severity::Medium;
    }
}
