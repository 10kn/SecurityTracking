<?php

use App\Enums\Exposure;
use App\Enums\OperatingSystem;
use App\Enums\ServiceKind;
use App\Events\DeploymentInventoryChanged;
use App\Jobs\SyncServiceLockfilesJob;
use App\Models\AccessibleRepository;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use App\Services\ProjectImport\ImportSheet;
use App\Services\ProjectImport\ImportTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

use function Pest\Laravel\artisan;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    Storage::fake('local');
});

/**
 * A row of the "Services" sheet: the server "acme/api" of the team "Commerce" in the project "Cổng thanh toán", with the
 * given cells keyed by column letter.
 *
 * @param  array<string, string>  $cells
 * @return list<string>
 */
function importRow(array $cells = []): array
{
    $cells = [
        ImportSheet::TEAM => 'Commerce',
        ImportSheet::PROJECT => 'Cổng thanh toán',
        ImportSheet::REPOSITORY => 'acme/api',
        ImportSheet::KIND => 'Server',
        ImportSheet::EXPOSURE => 'Public',
        ...$cells,
    ];

    return array_map(fn (string $column): string => $cells[$column] ?? '', range('A', ImportSheet::LAST_COLUMN));
}

/**
 * Repositories the GitHub App reads, so rows can name them.
 */
function accessibleRepositories(string ...$fullNames): void
{
    foreach ($fullNames as $fullName) {
        AccessibleRepository::factory()->create(['full_name' => $fullName]);
    }
}

/**
 * The template as an admin downloads it, with the rows filled in.
 *
 * @param  list<list<string>>  $rows
 */
function importFile(array $rows, ?Spreadsheet $spreadsheet = null): UploadedFile
{
    $spreadsheet ??= app(ImportTemplate::class)->build();
    $spreadsheet->getSheetByName(ImportSheet::DATA_SHEET)?->fromArray($rows, null, 'A'.ImportSheet::FIRST_ROW, true);
    $path = tempnam(sys_get_temp_dir(), 'psm-import-');
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

/**
 * Uploads the rows and returns the id of the import the preview opens.
 *
 * @param  list<list<string>>  $rows
 */
function uploadImport(array $rows): string
{
    $response = post('/admin/project-imports', ['file' => importFile($rows)])->assertSessionHasNoErrors();
    $location = (string) $response->headers->get('Location');

    expect($location)->toContain('/admin/project-imports/');

    return Str::afterLast($location, '/');
}

it('gives a template with a dropdown for each list and versions typed by hand', function () {
    actingAsAdmin();
    Team::factory()->create(['name' => 'Commerce']);

    $response = get('/admin/project-imports/template')->assertOk()->assertDownload('psm-project-import.xlsx');
    $path = tempnam(sys_get_temp_dir(), 'psm-template-');
    file_put_contents($path, $response->streamedContent());
    $spreadsheet = IOFactory::load($path);

    $data = $spreadsheet->getSheetByName(ImportSheet::DATA_SHEET);
    $lists = $spreadsheet->getSheetByName(ImportSheet::LISTS_SHEET);
    expect($spreadsheet->getSheetNames())->toBe([ImportSheet::DATA_SHEET, 'Hướng dẫn', ImportSheet::LISTS_SHEET])
        ->and($lists->getSheetState())->toBe(Worksheet::SHEETSTATE_HIDDEN)
        ->and($lists->getCell(ImportSheet::MARKER_CELL)->getValue())->toBe(ImportSheet::MARKER)
        ->and($data->getCell('A1')->getValue())->toBe('Team *')
        ->and($data->getCell('C1')->getValue())->toBe('Repository *')
        ->and($data->getCell('E1')->getValue())->toBe('Thư mục trong repository')
        ->and($data->getCell('I1')->getValue())->toBe('Công nghệ 1')
        ->and($data->getCell('N1')->getValue())->toBe('Version 3')
        ->and($data->getCell('O1')->getValue())->toBeNull()
        ->and($lists->getCell('A2')->getValue())->toBe('Commerce')
        ->and($spreadsheet->getNamedRange('psm_technologies')->getRange())->toBe("'Lists'!\$E\$2:\$E\$4");

    $team = $data->getDataValidation('A2');
    expect($team->getType())->toBe(DataValidation::TYPE_LIST)
        ->and($team->getFormula1())->toBe('psm_teams')
        ->and($team->getErrorStyle())->toBe(DataValidation::STYLE_STOP)
        ->and($data->getDataValidation('F2')->getFormula1())->toBe('psm_kinds')
        ->and($data->getDataValidation('M2')->getFormula1())->toBe('psm_technologies')
        ->and($data->dataValidationExists('J10'))->toBeFalse()
        ->and(collect($data->getDataValidationCollection())->filter(fn (DataValidation $validation): bool => str_contains($validation->getFormula1(), 'INDIRECT'))->all())->toBe([]);
});

it('previews what each row creates: new projects, services added to existing ones and services already there', function () {
    actingAsAdmin();
    $commerce = Team::factory()->create(['name' => 'Commerce']);
    accessibleRepositories('acme/api', 'Acme/Admin-Web', 'acme/stock', 'acme/worker', 'acme/sync', 'acme/kessai');
    $existing = Project::factory()->for($commerce)->create(['name' => 'Kho hàng', 'slug' => 'kho-hang']);
    Service::factory()->for($existing)->syncingLockfiles('Acme/Worker')->create(['name' => 'Hàng đợi', 'slug' => 'hang-doi']);
    Service::factory()->for($existing)->create(['name' => 'Sync', 'slug' => 'sync']);
    Project::factory()->create(['name' => 'Cổng thanh toán', 'slug' => 'cong-thanh-toan']);

    $import = uploadImport([
        importRow([ImportSheet::OS => 'Linux', 'I' => 'PHP', 'J' => '8.3.12', 'K' => 'Node.js', 'L' => '20.11.1']),
        importRow([ImportSheet::PROJECT => '  cổng THANH toán ', ImportSheet::REPOSITORY => 'https://github.com/acme/admin-web', ImportSheet::REPOSITORY_PATH => '/apps/admin/', ImportSheet::KIND => 'ECS', ImportSheet::EXPOSURE => 'Nội bộ']),
        importRow([ImportSheet::PROJECT => 'kho hàng', ImportSheet::REPOSITORY => 'acme/stock']),
        importRow([ImportSheet::PROJECT => 'Kho hàng', ImportSheet::REPOSITORY => 'acme/worker', ImportSheet::REPOSITORY_PATH => '/']),
        importRow([ImportSheet::PROJECT => 'Kho hàng', ImportSheet::REPOSITORY => 'acme/sync']),
        importRow([ImportSheet::PROJECT => '決済', ImportSheet::REPOSITORY => 'acme/kessai']),
    ]);

    get("/admin/project-imports/{$import}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('project-imports/show')
            ->where('summary', ['new_projects' => 2, 'services' => 4, 'skipped' => 2, 'invalid' => 0])
            ->where('can_import', true)
            ->where('rows.0.status', ['label' => 'Tạo kèm project mới', 'tone' => 'success'])
            ->where('rows.0.project', 'Cổng thanh toán')
            ->where('rows.0.project_slug', 'cong-thanh-toan-2')
            ->where('rows.0.service', 'api')
            ->where('rows.0.service_slug', 'api')
            ->where('rows.0.details', ['Server', 'Public', 'Linux', 'PHP 8.3.12', 'Node.js 20.11.1', 'acme/api'])
            ->where('rows.1.project_slug', 'cong-thanh-toan-2')
            ->where('rows.1.service', 'Admin-Web (apps/admin)')
            ->where('rows.1.service_slug', 'admin-web-apps-admin')
            ->where('rows.1.details', ['ECS', 'Nội bộ', 'Acme/Admin-Web · /apps/admin/'])
            ->where('rows.2.status.label', 'Thêm vào project có sẵn')
            ->where('rows.2.project', 'Kho hàng')
            ->where('rows.2.project_slug', 'kho-hang')
            ->where('rows.2.service', 'stock')
            ->where('rows.3.status', ['label' => 'Bỏ qua, service đã có', 'tone' => 'neutral'])
            ->where('rows.4.status.label', 'Bỏ qua, service đã có')
            ->where('rows.5.project_slug', 'project'));
});

it('reads the lists in japanese too, and by their stored value', function () {
    actingAsAdmin();
    Team::factory()->create(['name' => 'Commerce', 'slug' => 'commerce']);
    accessibleRepositories('acme/api', 'acme/web');

    $import = uploadImport([
        importRow([ImportSheet::TEAM => 'commerce', ImportSheet::KIND => 'サーバー', ImportSheet::EXPOSURE => '社内', 'I' => 'nodejs', 'J' => '22.11.0']),
        importRow([ImportSheet::REPOSITORY => 'acme/web', ImportSheet::KIND => 'ecs', ImportSheet::EXPOSURE => 'public', ImportSheet::OS => 'macos']),
    ]);

    get("/admin/project-imports/{$import}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.invalid', 0)
            ->where('rows.0.details', ['Server', 'Nội bộ', 'Node.js 22.11.0', 'acme/api'])
            ->where('rows.1.details', ['ECS', 'Public', 'macOS', 'acme/web']));
});

it('shows what is wrong with each row and allows no import until it is fixed', function () {
    actingAsAdmin();
    Team::factory()->create(['name' => 'Commerce']);
    accessibleRepositories('acme/api', 'acme/web', 'acme/shop');

    $import = uploadImport([
        importRow([ImportSheet::TEAM => 'Marketing', ImportSheet::PROJECT => '', ImportSheet::REPOSITORY => '', ImportSheet::KIND => 'VM', ImportSheet::EXPOSURE => '']),
        importRow(['I' => 'PHP', 'J' => '8.3; rm -rf /', 'K' => 'php', 'L' => '8.4.1', 'M' => '', 'N' => '3.3.6']),
        importRow([ImportSheet::REPOSITORY => 'acme/web', 'I' => 'Java', 'J' => '21', 'K' => 'Ruby']),
        importRow([ImportSheet::REPOSITORY => 'https://gitlab.com/acme/shop', ImportSheet::BRANCH => 'main']),
        importRow([ImportSheet::REPOSITORY => 'acme/secret']),
        importRow([ImportSheet::REPOSITORY => 'acme/shop', ImportSheet::BRANCH => 'feature..x']),
        importRow([ImportSheet::REPOSITORY => 'https://github.com/ACME/web', ImportSheet::REPOSITORY_PATH => '/']),
    ]);

    get("/admin/project-imports/{$import}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.invalid', 7)
            ->where('can_import', false)
            ->where('rows.0.status', ['label' => 'Lỗi', 'tone' => 'danger'])
            ->where('rows.0.service', '')
            ->where('rows.0.errors', ['Không có team "Marketing".', 'Thiếu Tên project.', 'Thiếu Repository.', '"VM" không có trong danh sách Loại.', 'Thiếu Phạm vi truy cập.'])
            ->where('rows.1.errors', [
                'Version 1 "8.3; rm -rf /": version chỉ gồm chữ, số và . + ~ _ - (tối đa 64 ký tự).',
                'Chọn Công nghệ 3 cho version "3.3.6".',
            ])
            ->where('rows.2.errors', ['"Java" không có trong danh sách Công nghệ 1.', 'Thiếu Version 2 của Ruby.'])
            ->where('rows.3.errors', ['Không đọc được repository "https://gitlab.com/acme/shop". Ghi link dạng https://github.com/acme/shop hoặc owner/repo.'])
            ->where('rows.4.errors', ['Repository acme/secret chưa có trong danh sách GitHub App được đọc. Server chưa cấu hình GitHub App nên không làm mới được danh sách repository.'])
            ->where('rows.5.errors', ['Tên branch không hợp lệ.'])
            ->where('rows.6.errors', ['Trùng repository và thư mục với dòng 4.']));

    post("/admin/project-imports/{$import}/confirm")->assertRedirect("/admin/project-imports/{$import}");

    expect(Project::count())->toBe(0)->and(Service::count())->toBe(0);
});

it('flags the same technology twice in a row', function () {
    actingAsAdmin();
    Team::factory()->create(['name' => 'Commerce']);
    accessibleRepositories('acme/api');

    $import = uploadImport([importRow(['I' => 'PHP', 'J' => '8.3.12', 'K' => 'PHP', 'L' => '8.4.1'])]);

    get("/admin/project-imports/{$import}")->assertInertia(fn (Assert $page) => $page->where('rows.0.errors', ['Mỗi công nghệ chỉ chọn một lần.']));
});

it('takes a repository link and checks its branch on github once', function () {
    actingAsAdmin();
    Team::factory()->create(['name' => 'Commerce']);
    AccessibleRepository::factory()->create(['full_name' => 'Acme/Shop']);
    Http::fake([
        'https://api.github.com/repos/Acme/Shop/branches/main' => Http::response(['name' => 'main']),
        'https://api.github.com/repos/Acme/Shop/branches/gone' => Http::response(['message' => 'Branch not found'], 404),
    ]);
    fakeGitHub();

    $import = uploadImport([
        importRow([ImportSheet::REPOSITORY => 'https://github.com/acme/shop.git', ImportSheet::BRANCH => 'main', ImportSheet::REPOSITORY_PATH => 'apps/api']),
        importRow([ImportSheet::REPOSITORY => 'git@github.com:acme/shop.git', ImportSheet::BRANCH => 'main']),
        importRow([ImportSheet::REPOSITORY => 'acme/shop', ImportSheet::BRANCH => 'gone', ImportSheet::REPOSITORY_PATH => 'apps/worker']),
    ]);

    // No server-side rendering while every other request is faked, e.g. when the Vite dev server runs.
    config(['inertia.ssr.enabled' => false]);

    get("/admin/project-imports/{$import}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.service', 'Shop (apps/api)')
            ->where('rows.0.details', ['Server', 'Public', 'Acme/Shop · main · apps/api'])
            ->where('rows.1.service', 'Shop')
            ->where('rows.1.errors', [])
            ->where('rows.2.errors', ['Không thấy branch gone trong Acme/Shop.']));

    Http::assertSentCount(3);
});

it('refreshes the repository list from github before checking a repository it does not have yet', function () {
    actingAsAdmin();
    Team::factory()->create(['name' => 'Commerce']);
    accessibleRepositories('acme/api', 'acme/revoked');
    Http::fake(['https://api.github.com/installation/repositories*' => Http::response(['total_count' => 2, 'repositories' => [
        ['full_name' => 'acme/api', 'default_branch' => 'main', 'private' => true],
        ['full_name' => 'Acme/New-Shop', 'default_branch' => 'main', 'private' => true],
    ]])]);
    fakeGitHub();
    config(['inertia.ssr.enabled' => false]);

    $import = uploadImport([
        importRow(),
        importRow([ImportSheet::REPOSITORY => 'https://github.com/acme/new-shop']),
        importRow([ImportSheet::REPOSITORY => 'acme/secret']),
    ]);

    get("/admin/project-imports/{$import}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.errors', [])
            ->where('rows.1.service', 'New-Shop')
            ->where('rows.1.errors', [])
            ->where('rows.2.errors', ['GitHub App không đọc được repository acme/secret. Cấp quyền cho GitHub App với repository này rồi tải lại trang.']));

    expect(AccessibleRepository::orderBy('full_name')->pluck('full_name')->all())->toBe(['Acme/New-Shop', 'acme/api']);
    Http::assertSentCount(2);
});

it('does not ask github for the repository list when the file names only repositories it has', function () {
    actingAsAdmin();
    Team::factory()->create(['name' => 'Commerce']);
    accessibleRepositories('acme/api');
    fakeGitHub();
    config(['inertia.ssr.enabled' => false]);

    $import = uploadImport([importRow()]);

    get("/admin/project-imports/{$import}")->assertInertia(fn (Assert $page) => $page->where('rows.0.errors', []));

    Http::assertNothingSent();
});

it('keeps the repository list and says why when github cannot be reached to refresh it', function () {
    actingAsAdmin();
    Sleep::fake();
    Team::factory()->create(['name' => 'Commerce']);
    accessibleRepositories('acme/api');
    Http::fake(['https://api.github.com/installation/repositories*' => Http::response('Service Unavailable', 503)]);
    fakeGitHub();
    config(['inertia.ssr.enabled' => false]);

    $import = uploadImport([importRow(), importRow([ImportSheet::REPOSITORY => 'acme/new-shop'])]);

    get("/admin/project-imports/{$import}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.errors', [])
            ->where('rows.1.errors.0', fn (string $error): bool => str_starts_with($error, 'Repository acme/new-shop chưa có trong danh sách GitHub App được đọc. Không làm mới được danh sách repository từ GitHub, thử lại sau: ')));

    expect(AccessibleRepository::pluck('full_name')->all())->toBe(['acme/api']);
});

it('creates the projects and services of a file without errors all at once', function () {
    actingAsAdmin();
    Queue::fake([SyncServiceLockfilesJob::class]);
    Event::fake([DeploymentInventoryChanged::class]);
    $commerce = Team::factory()->create(['name' => 'Commerce']);
    $existing = Project::factory()->for($commerce)->create(['name' => 'Kho hàng']);
    accessibleRepositories('acme/shop', 'acme/admin', 'acme/api');

    $import = uploadImport([
        importRow([ImportSheet::OS => 'Linux', 'I' => 'PHP', 'J' => '8.3.12', ImportSheet::REPOSITORY => 'https://github.com/acme/shop', ImportSheet::REPOSITORY_PATH => 'apps/api']),
        importRow([ImportSheet::REPOSITORY => 'acme/admin', ImportSheet::KIND => 'ECS', ImportSheet::EXPOSURE => 'Nội bộ', 'I' => 'Node.js', 'J' => '20.11.1']),
        importRow([ImportSheet::PROJECT => 'Kho hàng']),
    ]);

    post("/admin/project-imports/{$import}/confirm")->assertRedirect('/admin/projects');

    $project = Project::where('slug', 'cong-thanh-toan')->sole();
    $api = $project->services()->where('slug', 'shop-apps-api')->sole();
    $admin = $project->services()->where('slug', 'admin')->sole();
    expect($project)->team_id->toBe($commerce->id)->name->toBe('Cổng thanh toán')
        ->and($api)->name->toBe('shop (apps/api)')->kind->toBe(ServiceKind::Server)->exposure->toBe(Exposure::Public)->repository->toBe('acme/shop')->repository_branch->toBeNull()->repository_path->toBe('apps/api')
        ->and($api->productionDeployment->os)->toBe(OperatingSystem::Linux)
        ->and($api->productionDeployment->runtimes()->pluck('raw_version', 'technology')->all())->toEqual(['php' => '8.3.12'])
        ->and($admin)->name->toBe('admin')->kind->toBe(ServiceKind::Ecs)->exposure->toBe(Exposure::Internal)->repository->toBe('acme/admin')->repository_path->toBe('/')
        ->and($admin->productionDeployment->runtimes()->pluck('raw_version', 'technology')->all())->toEqual(['nodejs' => '20.11.1'])
        ->and($existing->services()->pluck('repository', 'slug')->all())->toBe(['api' => 'acme/api'])
        ->and(Project::count())->toBe(2);
    Event::assertDispatched(DeploymentInventoryChanged::class);
    Queue::assertPushed(SyncServiceLockfilesJob::class, 3);
    Storage::disk('local')->assertDirectoryEmpty('project-imports');

    get("/admin/project-imports/{$import}")->assertRedirect('/admin/project-imports/create');
});

it('refuses a file that is not the import template', function () {
    actingAsAdmin();
    $plainWorkbook = new Spreadsheet;
    $plainWorkbook->getActiveSheet()->setTitle(ImportSheet::DATA_SHEET);

    post('/admin/project-imports', ['file' => importFile([importRow()], $plainWorkbook)])
        ->assertSessionHasErrors(['file' => 'File không phải template import của hệ thống, hoặc là template cũ. Tải template mới rồi điền lại.']);
    post('/admin/project-imports', ['file' => UploadedFile::fake()->createWithContent('import.csv', "Team,Project\n")])
        ->assertSessionHasErrors(['file' => 'Chỉ nhận file Excel (.xlsx) tải từ nút "Tải template".']);
    post('/admin/project-imports', ['file' => UploadedFile::fake()->createWithContent('import.xlsx', 'not a zip')])
        ->assertSessionHasErrors(['file' => 'Không đọc được file. Lưu file ở dạng Excel (.xlsx) rồi tải lên lại.']);

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses a file with more rows than one import takes', function () {
    actingAsAdmin();

    post('/admin/project-imports', ['file' => importFile(array_fill(0, ImportSheet::MAX_ROWS + 1, importRow()))])
        ->assertSessionHasErrors(['file' => 'File có quá 500 dòng. Chia thành nhiều file rồi import từng file.']);
});

it('deletes the uploads never confirmed after a day', function () {
    $disk = Storage::disk('local');
    $disk->put('project-imports/old.xlsx', 'x');
    $disk->put('project-imports/new.xlsx', 'x');
    touch($disk->path('project-imports/old.xlsx'), now()->subDays(2)->getTimestamp());

    artisan('psm:prune-project-imports')->assertSuccessful();

    expect($disk->files('project-imports'))->toBe(['project-imports/new.xlsx']);
});
