<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ImportProjects;
use App\Enums\Technology;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProjectImportRequest;
use App\Models\Team;
use App\Services\ProjectImport\ImportFiles;
use App\Services\ProjectImport\ImportPlan;
use App\Services\ProjectImport\ImportPlanner;
use App\Services\ProjectImport\ImportRow;
use App\Services\ProjectImport\ImportSheet;
use App\Services\ProjectImport\ImportSheetReader;
use App\Services\ProjectImport\ImportTemplate;
use App\Services\ProjectImport\UnreadableImportException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Creating projects and services from the template teams fill in: download the template, upload it, check every row
 * on the preview, then confirm to create them all at once.
 */
class ProjectImportController extends Controller
{
    public function __construct(private ImportSheetReader $reader, private ImportPlanner $planner) {}

    public function create(): Response
    {
        return Inertia::render('project-imports/create', [
            'max_rows' => ImportSheet::MAX_ROWS,
            'max_kilobytes' => ProjectImportRequest::MAX_KILOBYTES,
            'has_teams' => Team::exists(),
        ]);
    }

    public function template(ImportTemplate $template): StreamedResponse
    {
        $spreadsheet = $template->build();

        return response()->streamDownload(
            fn () => (new Xlsx($spreadsheet))->save('php://output'),
            'psm-project-import.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * A file that is not a filled-in template is refused here, before it is kept.
     */
    public function store(ProjectImportRequest $request): RedirectResponse
    {
        $file = $request->file('file');

        try {
            $this->reader->read($file->getRealPath());
        } catch (UnreadableImportException $exception) {
            return back()->withErrors(['file' => $exception->getMessage()]);
        }

        return to_route('admin.project-imports.show', ImportFiles::store($file));
    }

    /**
     * The file is read and checked again on every visit, so the preview follows projects created meanwhile.
     */
    public function show(string $import): Response|RedirectResponse
    {
        $plan = $this->plan($import);

        if ($plan === null) {
            return $this->missingFile();
        }

        return Inertia::render('project-imports/show', [
            'import' => $import,
            'summary' => $plan->summary(),
            'can_import' => ! $plan->hasErrors() && $plan->rowsToCreate() !== [],
            'rows' => array_map(fn (ImportRow $row): array => [
                'row' => $row->row,
                'status' => $row->status->dot(),
                'team' => $row->teamName,
                'project' => $row->projectName,
                'project_slug' => $row->projectSlug,
                'service' => $row->serviceName,
                'service_slug' => $row->serviceSlug,
                'details' => $this->details($row),
                'errors' => $row->errors,
            ], $plan->rows),
        ]);
    }

    public function confirm(string $import, ImportProjects $importProjects): RedirectResponse
    {
        $plan = $this->plan($import);

        if ($plan === null) {
            return $this->missingFile();
        }

        if ($plan->hasErrors() || $plan->rowsToCreate() === []) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('File còn dòng lỗi hoặc không có service nào để tạo. Sửa file rồi tải lên lại.')]);

            return to_route('admin.project-imports.show', $import);
        }

        $created = $importProjects->handle($plan);
        ImportFiles::delete($import);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Đã tạo :projects project mới và :services service. Hệ thống đang đánh giá các service mới.', $created)]);

        return to_route('admin.projects.index');
    }

    /**
     * Null when the file is gone or no longer readable.
     */
    private function plan(string $import): ?ImportPlan
    {
        $path = ImportFiles::path($import);

        if ($path === null) {
            return null;
        }

        try {
            return $this->planner->plan($this->reader->read($path));
        } catch (UnreadableImportException) {
            return null;
        }
    }

    private function missingFile(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => __('Không còn file import này: đã import xong hoặc quá một ngày chưa xác nhận. Tải file lên lại.')]);

        return to_route('admin.project-imports.create');
    }

    /**
     * What the service will be created with, e.g. ["Server", "Public", "Linux", "PHP 8.3.12", "acme/shop · main"].
     *
     * @return list<string>
     */
    private function details(ImportRow $row): array
    {
        if (! $row->status->createsService()) {
            return [];
        }

        $runtimes = array_map(fn (string $technology, string $version): string => Technology::from($technology)->getLabel().' '.$version, array_keys($row->runtimeVersions), $row->runtimeVersions);
        $repository = $row->repository === null ? null : collect([$row->repository, $row->repositoryBranch, $row->repositoryPath !== '/' ? $row->repositoryPath : null])->filter()->join(' · ');

        return array_values(array_filter([$row->kind?->getLabel(), $row->exposure?->getLabel(), $row->os?->getLabel(), ...$runtimes, $repository]));
    }
}
