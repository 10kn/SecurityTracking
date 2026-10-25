<?php

namespace App\Services\Packages;

use App\Services\Catalog\CatalogHttp;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use UnexpectedValueException;

/**
 * Reads the RubySec advisory database (https://github.com/rubysec/ruby-advisory-db), the source `bundler-audit` reads:
 * one YAML file per advisory at gems/{gem}/{id}.yml. It has no API, so the tarball of its default branch is read,
 * about 600 KB, on every scan.
 */
class RubySecClient
{
    /**
     * Every gem advisory of the database.
     *
     * @return array<string, array<string, array<string, mixed>>> By gem name, then advisory id: the file name, such as
     *                                                            "CVE-2024-45614" or "GHSA-pq4g-8rxf-4g29".
     *
     * @throws ConnectionException
     * @throws RequestException
     * @throws UnexpectedValueException When the archive or one of its advisories cannot be read.
     */
    public function advisories(): array
    {
        $archive = CatalogHttp::client(config('psm.packages.http_timeout_seconds'))->get(config('psm.packages.rubysec_url'))->body();
        $tar = @gzdecode($archive);

        if ($tar === false) {
            throw new UnexpectedValueException('The RubySec archive is not gzip compressed.');
        }

        $advisories = [];

        foreach ($this->files($tar) as $path => $content) {
            if (preg_match('#^[^/]+/gems/([^/]+)/([^/]+)\.yml$#', $path, $matches) !== 1) {
                continue;
            }

            try {
                $advisory = Yaml::parse($content);
            } catch (ParseException $exception) {
                throw new UnexpectedValueException("RubySec advisory gems/{$matches[1]}/{$matches[2]}.yml is not valid YAML.", previous: $exception);
            }

            if (! is_array($advisory)) {
                throw new UnexpectedValueException("RubySec advisory gems/{$matches[1]}/{$matches[2]}.yml is not a mapping.");
            }

            $advisories[$matches[1]][$matches[2]] = $advisory;
        }

        if ($advisories === []) {
            throw new UnexpectedValueException('The RubySec archive has no gem advisories.');
        }

        return $advisories;
    }

    /**
     * The regular files of a tar archive, by path. A path longer than the 100 bytes of the name field is split into
     * the ustar prefix, or carried by a pax extended header, as `git archive` does.
     *
     * @return Generator<string, string>
     */
    private function files(string $tar): Generator
    {
        $offset = 0;
        $paxPath = null;

        while ($offset + 512 <= strlen($tar)) {
            $header = substr($tar, $offset, 512);

            if (trim($header, "\0") === '') {
                return;
            }

            $type = $header[156];
            $size = (int) octdec(trim(substr($header, 124, 12), " \0"));
            $content = substr($tar, $offset + 512, $size);
            $offset += 512 + (int) ceil($size / 512) * 512;

            if ($type === 'x') {
                $paxPath = preg_match('/^\d+ path=(.*)$/m', $content, $matches) === 1 ? $matches[1] : null;

                continue;
            }

            $prefix = rtrim(substr($header, 345, 155), "\0");
            $name = rtrim(substr($header, 0, 100), "\0");
            $path = $paxPath ?? ($prefix === '' ? $name : "{$prefix}/{$name}");
            $paxPath = null;

            if ($type === '0' || $type === "\0") {
                yield $path => $content;
            }
        }
    }
}
