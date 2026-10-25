<?php

namespace App\Support;

/**
 * Slugs of teams, projects and services.
 */
final class Slug
{
    /**
     * Lowercase letters, digits and single hyphens, e.g. "internal-tools".
     */
    public const string PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';
}
