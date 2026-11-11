<?php

namespace App\Console\Commands;

use App\Services\ProjectImport\ImportFiles;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('psm:prune-project-imports')]
#[Description('Delete the uploaded project import files that were not confirmed within a day')]
class PruneProjectImports extends Command
{
    public function handle(): int
    {
        $deleted = ImportFiles::prune(now()->subDay());

        $this->components->info(__('Đã xóa :count file import cũ.', ['count' => $deleted]));

        return self::SUCCESS;
    }
}
