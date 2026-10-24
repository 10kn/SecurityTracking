<?php

namespace App\Services\Lockfiles;

use App\Enums\Ecosystem;
use App\Support\PackageUrl;

/**
 * One package at one version, as a lockfile pins it. `qualifiers` go into the package URL only, e.g. the platform of
 * a native gem.
 */
final readonly class ComponentData
{
    /**
     * @param  array<string, string>  $qualifiers
     */
    public function __construct(
        public Ecosystem $ecosystem,
        public string $name,
        public string $version,
        public bool $isDev = false,
        public array $qualifiers = [],
    ) {}

    public function purl(): string
    {
        return PackageUrl::for($this->ecosystem, $this->name, $this->version, $this->qualifiers);
    }

    /**
     * Keeps one entry per name and version. A package installed for production anywhere counts as production, so it
     * is only a dev dependency when every occurrence is.
     *
     * @param  list<self>  $components
     * @return list<self>
     */
    public static function unique(array $components): array
    {
        $unique = [];

        foreach ($components as $component) {
            $key = "{$component->name}@{$component->version}";
            $existing = $unique[$key] ?? null;

            if ($existing === null) {
                $unique[$key] = $component;
            } elseif ($existing->isDev && ! $component->isDev) {
                $unique[$key] = new self($existing->ecosystem, $existing->name, $existing->version, false, $existing->qualifiers);
            }
        }

        return array_values($unique);
    }
}
