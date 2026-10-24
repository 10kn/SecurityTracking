<?php

use Symfony\Component\Finder\Finder;

it('translates every string of the react screens to japanese', function () {
    $japanese = json_decode((string) file_get_contents(lang_path('ja.json')), true, flags: JSON_THROW_ON_ERROR);
    $missing = [];

    foreach (Finder::create()->in(resource_path('js'))->exclude(['actions', 'routes', 'wayfinder'])->name(['*.ts', '*.tsx'])->files() as $file) {
        preg_match_all("/\\bt\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $file->getContents(), $matches);

        foreach ($matches[1] as $string) {
            if (! array_key_exists(stripslashes($string), $japanese)) {
                $missing[] = $file->getRelativePathname().': '.stripslashes($string);
            }
        }
    }

    expect($missing)->toBe([]);
});
