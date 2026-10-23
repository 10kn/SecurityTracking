<?php

namespace App\Models;

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
}
