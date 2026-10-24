<?php

namespace App\Services\Lockfiles;

/**
 * Reads the packages a lockfile pins. Parsers only read the text: nothing in the lockfile is ever executed or
 * installed (handover 8).
 */
interface LockfileParser
{
    /**
     * Every package the lockfile installs, once per name and version.
     *
     * @return list<ComponentData>
     *
     * @throws UnreadableLockfileException When the content is not a lockfile of this kind.
     */
    public function parse(string $content): array;
}
