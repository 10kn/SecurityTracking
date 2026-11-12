<?php

namespace App\Models;

use Database\Factories\RepositoryConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A way to read repositories other than the GitHub App of `psm.github`: the personal access token of a developer
 * account a customer invited to its repositories. An outside collaborator can only use a classic token (scope
 * `repo`); a member of the customer's organization can use a fine-grained one (Contents: read). The token reads
 * every repository the account can, so each customer gets its own account and connection.
 *
 * A service without a connection reads through the GitHub App.
 */
#[Fillable(['name', 'token', 'account_login', 'scopes', 'expires_at', 'last_checked_at', 'last_error'])]
#[Hidden(['token'])]
class RepositoryConnection extends Model
{
    /** @use HasFactory<RepositoryConnectionFactory> */
    use HasFactory;

    /**
     * How long before its token expires the admin is told to replace it.
     */
    public const int EXPIRY_WARNING_DAYS = 14;

    /**
     * Every connection's source heartbeat key starts with it.
     */
    public const string SOURCE_KEY_PREFIX = 'github:connection-';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'expires_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * @return HasMany<AccessibleRepository, $this>
     */
    public function accessibleRepositories(): HasMany
    {
        return $this->hasMany(AccessibleRepository::class);
    }

    /**
     * The source heartbeat of the connection, e.g. "github:connection-3"; the GitHub App's is "github".
     */
    public function sourceKey(): string
    {
        return self::SOURCE_KEY_PREFIX.$this->id;
    }

    /**
     * E.g. "GitHub · Khách ABC".
     */
    public function sourceName(): string
    {
        return "GitHub · {$this->name}";
    }

    /**
     * The token with all but its prefix and last four characters hidden, e.g. "ghp_••••a1b2".
     */
    public function maskedToken(): string
    {
        $token = (string) $this->token;
        $prefix = preg_match('/^(github_pat_|gh[a-z]_)/', $token, $matches) === 1 ? $matches[1] : '';

        return $prefix.'••••'.Str::substr($token, -4);
    }

    /**
     * Whether the token expires within EXPIRY_WARNING_DAYS, or already has.
     */
    public function isExpiringSoon(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lte(now()->addDays(self::EXPIRY_WARNING_DAYS));
    }
}
