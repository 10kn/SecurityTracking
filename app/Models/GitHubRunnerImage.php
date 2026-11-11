<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\GitHubRunnerImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A GitHub-hosted runner image and the day GitHub removes it, from endoflife.date (product
 * `github-actions-runner-images`), named as there: "ubuntu-22.04", "macos-14", "macos-14-arm64", "windows-2022"…
 */
#[Table('github_runner_images')]
#[Fillable(['name', 'eol_from', 'is_eol', 'synced_at'])]
class GitHubRunnerImage extends Model
{
    /** @use HasFactory<GitHubRunnerImageFactory> */
    use HasFactory;

    public const string ENDOFLIFE_PRODUCT = 'github-actions-runner-images';

    public const string SOURCE_KEY = 'catalog:github-runner-images';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'eol_from' => 'date',
            'is_eol' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * The day GitHub removes (or removed) the image; null when none is announced.
     */
    public function removalDate(): ?CarbonInterface
    {
        return $this->eol_from ?? ($this->is_eol ? $this->updated_at?->copy()->startOfDay() : null);
    }

    /**
     * The image a `runs-on` label of a workflow runs on, e.g. "macos-14-xlarge" → "macos-14", "ubuntu-24.04-arm" →
     * "ubuntu-24.04-arm64". Null for labels with no fixed image ("ubuntu-latest", self-hosted runners, expressions) and
     * for images endoflife.date does not list.
     *
     * @param  Collection<string, self>  $images  By name.
     */
    public static function forLabel(string $label, Collection $images): ?self
    {
        $label = strtolower(trim($label));

        if ($label === '' || str_contains($label, '${{') || str_ends_with($label, '-latest')) {
            return null;
        }

        $base = (string) preg_replace('/-(x?large)\z/', '', $label);
        $candidates = array_unique([
            $label,
            $base,
            (string) preg_replace('/-arm\z/', '-arm64', $base),
            (string) preg_replace('/-arm(64)?\z/', '', $base),
        ]);

        foreach ($candidates as $candidate) {
            if ($images->has($candidate)) {
                return $images->get($candidate);
            }
        }

        return null;
    }
}
