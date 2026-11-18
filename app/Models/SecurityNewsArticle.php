<?php

namespace App\Models;

use Database\Factories\SecurityNewsArticleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['url', 'section', 'title', 'listed_on', 'listed_seen_at', 'published_on', 'is_backfill', 'classification_key', 'attempt_key', 'attempts', 'last_attempt_at', 'last_error', 'accepted_at', 'summary', 'reason', 'matched_tags', 'cve_ids', 'notification_queued_at'])]
class SecurityNewsArticle extends Model
{
    /** @use HasFactory<SecurityNewsArticleFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'listed_on' => 'immutable_date',
            'listed_seen_at' => 'datetime',
            'published_on' => 'immutable_date',
            'is_backfill' => 'boolean',
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
            'accepted_at' => 'datetime',
            'notification_queued_at' => 'datetime',
            'matched_tags' => 'array',
            'cve_ids' => 'array',
        ];
    }
}
