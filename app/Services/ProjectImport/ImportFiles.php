<?php

namespace App\Services\ProjectImport;

use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Uploaded import files wait on the private disk between the preview and the confirmation, so neither the session
 * nor the page carries the rows. A file is deleted once imported; one never confirmed is pruned the next day.
 */
final class ImportFiles
{
    private const string DIRECTORY = 'project-imports';

    /**
     * @return string The id of the stored file, a UUID.
     */
    public static function store(UploadedFile $file): string
    {
        $id = (string) Str::uuid();
        $file->storeAs(self::DIRECTORY, "{$id}.xlsx", 'local');

        return $id;
    }

    /**
     * The absolute path of a stored file, null when there is none (imported, pruned or never uploaded).
     */
    public static function path(string $id): ?string
    {
        $relativePath = self::DIRECTORY."/{$id}.xlsx";

        return Str::isUuid($id) && Storage::disk('local')->exists($relativePath) ? Storage::disk('local')->path($relativePath) : null;
    }

    public static function delete(string $id): void
    {
        Storage::disk('local')->delete(self::DIRECTORY."/{$id}.xlsx");
    }

    /**
     * Deletes the files uploaded before the given time.
     *
     * @return int How many were deleted.
     */
    public static function prune(CarbonInterface $before): int
    {
        $disk = Storage::disk('local');
        $oldFiles = array_filter($disk->files(self::DIRECTORY), fn (string $path): bool => $disk->lastModified($path) < $before->getTimestamp());

        $disk->delete($oldFiles);

        return count($oldFiles);
    }
}
