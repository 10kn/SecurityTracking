<?php

namespace App\Services\ProjectImport;

use App\Enums\ImportRowStatus;

/**
 * Every row of an import file once checked. Confirming it creates nothing while a row has errors.
 */
final readonly class ImportPlan
{
    /**
     * @param  list<ImportRow>  $rows
     */
    public function __construct(public array $rows) {}

    public function hasErrors(): bool
    {
        return $this->countOf(ImportRowStatus::Invalid) > 0;
    }

    /**
     * @return list<ImportRow>
     */
    public function rowsToCreate(): array
    {
        return array_values(array_filter($this->rows, fn (ImportRow $row): bool => $row->status->createsService()));
    }

    /**
     * @return array{new_projects: int, services: int, skipped: int, invalid: int}
     */
    public function summary(): array
    {
        $newProjects = array_unique(array_map(
            fn (ImportRow $row): ?string => $row->projectKey,
            array_filter($this->rows, fn (ImportRow $row): bool => $row->status === ImportRowStatus::NewProject),
        ));

        return [
            'new_projects' => count($newProjects),
            'services' => count($this->rowsToCreate()),
            'skipped' => $this->countOf(ImportRowStatus::Skipped),
            'invalid' => $this->countOf(ImportRowStatus::Invalid),
        ];
    }

    private function countOf(ImportRowStatus $status): int
    {
        return count(array_filter($this->rows, fn (ImportRow $row): bool => $row->status === $status));
    }
}
