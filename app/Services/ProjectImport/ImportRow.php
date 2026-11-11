<?php

namespace App\Services\ProjectImport;

use App\Enums\Exposure;
use App\Enums\ImportRowStatus;
use App\Enums\OperatingSystem;
use App\Enums\ServiceKind;

/**
 * One row of an import file once checked: what it creates, or why it cannot. The texts are the trimmed cells as
 * written; the other values are only set on a row that creates its service.
 */
final readonly class ImportRow
{
    /**
     * @param  list<string>  $errors
     * @param  array<string, string>  $runtimeVersions  Raw versions keyed by technology, e.g. ['php' => '8.3.12'].
     */
    public function __construct(
        public int $row,
        public ImportRowStatus $status,
        public string $teamName,
        public string $projectName,
        public string $serviceName,
        public array $errors = [],
        public ?int $teamId = null,
        public ?string $projectKey = null,
        public ?int $projectId = null,
        public ?string $projectSlug = null,
        public ?string $serviceSlug = null,
        public ?ServiceKind $kind = null,
        public ?Exposure $exposure = null,
        public ?OperatingSystem $os = null,
        public array $runtimeVersions = [],
        public ?string $repository = null,
        public ?string $repositoryBranch = null,
        public string $repositoryPath = '/',
    ) {}
}
