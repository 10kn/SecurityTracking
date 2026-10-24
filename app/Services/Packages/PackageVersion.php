<?php

namespace App\Services\Packages;

use App\Enums\Ecosystem;
use App\Models\Component;

/**
 * A package at one version, the unit a vulnerability source is asked about.
 */
final readonly class PackageVersion
{
    public function __construct(public Ecosystem $ecosystem, public string $name, public string $version) {}

    public static function of(Component $component): self
    {
        return new self($component->ecosystem, $component->name, $component->version);
    }

    public function key(): string
    {
        return "{$this->ecosystem->value}|{$this->name}|{$this->version}";
    }
}
