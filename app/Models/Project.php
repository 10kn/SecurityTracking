<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['team_id', 'name', 'slug', 'repository_url', 'repository_branch', 'syncs_lockfiles', 'lockfiles_synced_at', 'lockfiles_commit_sha', 'lockfiles_sync_error'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * An https:// URL or an scp-style git remote such as git@host:org/repo.git.
     */
    public const string REPOSITORY_URL_PATTERN = '/^(https:\/\/\S+|git@[^\s:]+:\S+)$/';

    /**
     * A git branch name such as "main" or "release/2026-10"; ".." is rejected separately.
     */
    public const string REPOSITORY_BRANCH_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/';

    /**
     * Mirrors the column defaults, so a freshly created project does not read lockfiles from its repository.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'syncs_lockfiles' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'syncs_lockfiles' => 'boolean',
            'lockfiles_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }
}
