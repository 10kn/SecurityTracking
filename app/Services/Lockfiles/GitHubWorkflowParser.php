<?php

namespace App\Services\Lockfiles;

use App\Enums\Ecosystem;
use App\Support\GitHubAction;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A GitHub Actions workflow (`.github/workflows/*.yml`) or local action (`action.yml`): the actions and reusable
 * workflows it `uses`, each as a package named after it (see GitHubAction) at the reference written, e.g.
 * `actions/checkout` at "v4", and the runner labels its jobs ask for (`runs-on`).
 *
 * Steps are read from `jobs.*.steps` and, in a composite action, `runs.steps`; reusable workflows from `jobs.*.uses`.
 * Local actions (`./…`) are read from their own `action.yml`, and Docker images (`docker://…`) have no release to check.
 * Workflows run in CI with its secrets, so their actions count as production, not development dependencies.
 */
class GitHubWorkflowParser implements LockfileParser
{
    public function parse(string $content): array
    {
        $document = $this->document($content);
        $components = [];

        foreach ($this->usesValues($document) as $uses) {
            $reference = GitHubAction::parseUses($uses);

            if ($reference !== null) {
                $components["{$reference[0]}@{$reference[1]}"] = new ComponentData(Ecosystem::GitHubActions, $reference[0], $reference[1]);
            }
        }

        return array_values($components);
    }

    /**
     * Every runner label the jobs ask for, lower-cased, e.g. ["ubuntu-22.04", "macos-14"]. A label written as
     * `${{ matrix.os }}` is replaced by the values the job's matrix lists for it; other expressions are left out.
     *
     * @return list<string>
     *
     * @throws UnreadableLockfileException
     */
    public function runnerLabels(string $content): array
    {
        $labels = [];

        foreach ((array) ($this->document($content)['jobs'] ?? []) as $job) {
            if (! is_array($job)) {
                continue;
            }

            $runsOn = $job['runs-on'] ?? null;
            $runsOn = is_array($runsOn) && array_key_exists('labels', $runsOn) ? $runsOn['labels'] : $runsOn;

            foreach ((array) $runsOn as $label) {
                if (! is_string($label)) {
                    continue;
                }

                if (preg_match('/\A\$\{\{\s*matrix\.([A-Za-z0-9_-]+)\s*\}\}\z/', trim($label), $matches) === 1) {
                    array_push($labels, ...$this->matrixValues($job, $matches[1]));
                } elseif (! str_contains($label, '${{')) {
                    $labels[] = strtolower(trim($label));
                }
            }
        }

        return array_values(array_unique(array_filter($labels)));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws UnreadableLockfileException
     */
    private function document(string $content): array
    {
        try {
            $document = Yaml::parse($content);
        } catch (ParseException $exception) {
            throw new UnreadableLockfileException('The workflow is not valid YAML: '.$exception->getMessage(), previous: $exception);
        }

        if (! is_array($document)) {
            throw new UnreadableLockfileException('The workflow is not a YAML mapping.');
        }

        return $document;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    private function usesValues(array $document): array
    {
        $uses = [];

        foreach ((array) ($document['jobs'] ?? []) as $job) {
            if (! is_array($job)) {
                continue;
            }

            if (is_string($job['uses'] ?? null)) {
                $uses[] = $job['uses'];
            }

            array_push($uses, ...$this->stepUses($job['steps'] ?? null));
        }

        $runs = $document['runs'] ?? null;

        if (is_array($runs)) {
            array_push($uses, ...$this->stepUses($runs['steps'] ?? null));
        }

        return $uses;
    }

    /**
     * @return list<string>
     */
    private function stepUses(mixed $steps): array
    {
        return collect(is_array($steps) ? $steps : [])
            ->map(fn (mixed $step): mixed => is_array($step) ? ($step['uses'] ?? null) : null)
            ->filter(fn (mixed $uses): bool => is_string($uses))
            ->values()
            ->all();
    }

    /**
     * The string values of `strategy.matrix.{key}` and of `strategy.matrix.include[*].{key}`.
     *
     * @param  array<string, mixed>  $job
     * @return list<string>
     */
    private function matrixValues(array $job, string $key): array
    {
        $matrix = $job['strategy']['matrix'] ?? null;

        if (! is_array($matrix)) {
            return [];
        }

        $values = is_array($matrix[$key] ?? null) ? $matrix[$key] : [];

        foreach ((array) ($matrix['include'] ?? []) as $include) {
            if (is_array($include) && array_key_exists($key, $include)) {
                $values[] = $include[$key];
            }
        }

        return collect($values)
            ->filter(fn (mixed $value): bool => is_string($value) && ! str_contains($value, '${{'))
            ->map(fn (string $value): string => strtolower(trim($value)))
            ->values()
            ->all();
    }
}
