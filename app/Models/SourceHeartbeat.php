<?php

namespace App\Models;

use App\Actions\ResolveNoticeCves;
use App\Enums\Technology;
use Database\Factories\SourceHeartbeatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['source', 'name', 'threshold_minutes', 'last_success_at', 'last_failure_at', 'last_error'])]
class SourceHeartbeat extends Model
{
    /** @use HasFactory<SourceHeartbeatFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public function isStale(): bool
    {
        return $this->last_success_at === null
            || $this->last_success_at->lt(now()->subMinutes($this->threshold_minutes));
    }

    /**
     * The name in the current locale. The stored one is written in the alert locale when the source runs; it is kept
     * for sources named the same in every language, e.g. "OSV".
     */
    public function localizedName(): string
    {
        $noticeFeed = collect(Technology::cases())->first(fn (Technology $technology): bool => $technology->noticeSourceKey() === $this->source);

        return match (true) {
            $this->catalogTechnology() !== null => __('Danh mục version :technology', ['technology' => $this->catalogTechnology()->getLabel()]),
            $noticeFeed !== null => __('Tin bảo mật :source', ['source' => $noticeFeed->releaseSourceLabel()]),
            $this->source === ResolveNoticeCves::SOURCE => __('Mã CVE của tin GitHub (GHSA)'),
            default => $this->name,
        };
    }

    /**
     * The technology whose version catalog this is, which an admin can sync again from the admin pages.
     */
    public function catalogTechnology(): ?Technology
    {
        return collect(Technology::cases())->first(fn (Technology $technology): bool => $technology->catalogSourceKey() === $this->source);
    }
}
