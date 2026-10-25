# Project Security Manager — Handover & kế hoạch tiếp theo

> Cập nhật: 25/10/2026 (bản rút gọn phạm vi MVP; đã chốt prototype UI v2 — mục 10; **M0 → M6 xong**, admin UI chuyển từ Filament sang React + Inertia (D2), M6 lấy lockfile từ GitHub (D12), còn Pilot — mục 3). Tài liệu bàn giao giữa các phiên làm việc (người hoặc AI).
> Đọc mục 1–3 trước khi viết code. Mục 5 là logic phát hiện, phần lõi của MVP. Mục 9 là cách làm việc đã thống nhất với chủ dự án. Mục 10 là spec giao diện đã chốt — đọc trước khi làm bất kỳ màn hình admin nào.

## 1. Bối cảnh

Trang web nội bộ theo dõi lỗ hổng bảo mật một cách chủ động, thay cho việc đọc tin thủ công. Hệ thống phát hiện runtime và package có lỗ hổng, đưa cảnh báo đến đúng team qua Google Chat.

**Mục tiêu MVP:** kiểm chứng rằng khi có thông tin project (team → project → service → version runtime, lockfile), hệ thống **phát hiện vấn đề kịp thời** và **gửi đúng tin, đúng team, không trùng** lên Google Chat. MVP chưa làm màn hình xử lý finding, tìm kiếm hay dashboard.

- Tài liệu kiến trúc: `docs/design/project-security-manager.html` (v2). Bản cũ: `project-security-manager.v1.html`. Nguồn để render lại: `project-security-manager.src.html` (skill visual-explainer). Tài liệu kiến trúc mô tả phạm vi đầy đủ, rộng hơn MVP.
- Người phát triển: một người, viết code cùng AI ("vibe code").

## 2. Quyết định đã chốt

| # | Quyết định | Ghi chú |
|---|---|---|
| D1 | Backend: **Laravel 13** + **PostgreSQL** | Chủ dự án review được code PHP; người khác trong công ty tiếp quản được. |
| D2 | Admin UI: **React + Inertia** (Laravel adapter `inertiajs/inertia-laravel` 3, React 19, TypeScript, shadcn/ui chỉnh theo token của prototype v2), đường dẫn `/admin` (24/10/2026) | Thay cho Filament 5 (dùng ở M1–M5): Filament khó tùy biến (vd. ô chọn version vừa lọc tại chỗ vừa nhận version mới) và mỗi thao tác đều gọi server. |
| D3 | Phạm vi MVP: **runtime ngôn ngữ (PHP, Ruby, Node.js) + package từ lockfile** (Composer, Bundler, npm) | OS để giai đoạn sau. yarn / pnpm làm nếu còn thời gian. |
| D4 | Engine lỗ hổng package: **Packagist** cho Composer (cùng nguồn với `composer audit`: GitHub + FriendsOfPHP), **RubySec** cho RubyGems (cùng nguồn với `bundler-audit`, 25/10/2026), **OSV** cho npm (24/10/2026; không dùng Dependency-Track) | Thiết kế để đổi được: lưu component theo PURL, interface `VulnerabilityMatcher` (mỗi matcher khai báo `ecosystems()`). Đo trên lockfile thật: OSV thiếu advisory chỉ có ở FriendsOfPHP (vd. 2 advisory Symfony tháng 8–9/2026); với RubyGems, OSV thiếu 130 advisory của RubySec (46 trong năm 2026, phần lớn là advisory GitHub chưa duyệt), RubySec chỉ thiếu 6 advisory của OSV (1 từ 2025). |
| D5 | Runtime dùng **quy tắc riêng**, không chờ CVE | MVP dùng 4 trạng thái: eol → outdated_security → outdated → ok, cộng `unknown`. `unknown` không bao giờ được coi là `ok`. Trạng thái `vulnerable` (đối chiếu dải CVE) để sau MVP. |
| D6 | **Hub là nguồn sự thật duy nhất** của trạng thái finding | MVP chỉ có 2 trạng thái **tự động**: `open` / `resolved`. Vòng đời thủ công (Đã nhận, Không ảnh hưởng, Chấp nhận rủi ro) để sau MVP. |
| D7 | Kênh cảnh báo: **Google Chat** (webhook theo team) | Gộp tin: 1 vấn đề × N service = 1 tin. Lỗi hệ thống gửi về một space riêng của admin (thay cho email dự phòng). |
| D8 | Nguồn thông tin version ở MVP: **admin nhập tay** trên màn hình admin | Version runtime theo service, upload lockfile. Collector, ingest API để sau. Từ M6, lockfile có thể lấy từ GitHub (D12). |
| D9 | MVP **không có** màn hình finding, dashboard | Card Google Chat link về trang sửa service (`/admin/services/{id}/edit`) và link nguồn tin gốc. Tìm kiếm ⌘K có từ UI React (D2). |
| D11 | **Công nghệ cố định trong code** (enum `App\Enums\Technology`: PHP, Node.js, Ruby), admin không thêm / sửa / xóa (24/10/2026; thay cho "công nghệ là dữ liệu" ngày 23/10) | Mỗi công nghệ khai báo trong code: mã sản phẩm endoflife.date (nhánh, ngày hết hỗ trợ, bản mới nhất), nguồn chính thức liệt kê mọi bản (php.net, nodejs.org, ruby-lang.org) và **bắt buộc** có feed tin bảo mật sớm. Lý do: thông báo sớm chỉ có trên kênh riêng của từng hãng, công nghệ thêm động chỉ có endoflife.date thì không phát hiện được bản vá khẩn cấp. Hỗ trợ thêm công nghệ (vd. Python) = thêm một case vào enum kèm các class feed. Không còn bảng `technologies`: các bảng khác lưu cột `technology` (giá trị enum). Màn Công nghệ chỉ xem danh mục (nhánh, ngày hết hỗ trợ, các bản) và "Đồng bộ ngay". Form service: danh sách động "công nghệ + version"; version là ô tự nhập có gợi ý từ danh mục. Không thêm ô framework: lỗ hổng framework lấy từ lockfile (M5). |
| D12 | **Hub chủ động đọc lockfile từ GitHub** (pull), không dùng CI/CD đẩy SBOM lên (24/10/2026) | Công ty chỉ dùng GitHub. Pull không phải cấu hình từng repo, không tốn phút CI, dùng lại parser lockfile của M5. Đăng nhập bằng **GitHub App** (quyền `Contents: Read` + `Metadata: Read`, cài cho các repo được chọn): ký JWT RS256 bằng `firebase/php-jwt` (chủ dự án đồng ý thêm), đổi lấy installation token 1 giờ, token chỉ giữ trong bộ nhớ. Không dùng PAT vì gắn với một người và phải gia hạn tay. **Mỗi ngày 1 lần** (06:30 `Asia/Ho_Chi_Minh`) + nút "Lấy lockfile ngay"; Hub chỉ chạy trong mạng nội bộ nên không dùng webhook. Repo + branch + thư mục đặt ở **từng service** (25/10/2026: mỗi service có thể là 1 repo; branch trống = branch mặc định). Repo chọn từ danh sách repo App được đọc (`GET /installation/repositories`), danh sách chỉ lấy lại khi admin bấm nút làm mới; branch nhập tay, khi lưu kiểm tra branch có trên GitHub. Chọn repo = bật đồng bộ (không có ô bật riêng). Service có repo thì GitHub là nguồn duy nhất của lockfile service đó (không upload tay). Code đọc repo qua interface `RepositoryReader` để thêm GitLab sau. Phương án CI/CD + SBOM để sau (mục 7). |
| D10 | Giao diện admin theo **prototype v2** (`public/prototypes/admin-v2/`): flat, tối giản; **4 theme** người dùng tự chọn (Graphite, Ocean, Forest, Night); **song ngữ Việt / Nhật** với font và cỡ chữ riêng cho tiếng Nhật | Spec ở mục 10. Áp dụng cho các resource của MVP (Team, Project, Service). Màn Tổng quan và Findings trong prototype là mẫu cho giai đoạn sau MVP; D9 giữ nguyên. Prototype v1 (`public/prototypes/admin/`) bị loại vì rối mắt, chỉ giữ để đối chiếu. |

## 3. Hiện trạng code (M0 → M5 xong — 23/10/2026)

### 3.1 Đã có

- App Laravel 13 ở thư mục gốc (PHP 8.5, Pest 5). Đã `git init` (nhánh `main`), **chưa có commit**.
- Package: `inertiajs/inertia-laravel` 3.5, `laravel/wayfinder` 0.1, `firebase/php-jwt` 7.2 (M6, ký JWT cho GitHub App), `larastan/larastan` (dev), `laravel/boost` (dev). `filament/filament` đã gỡ (24/10/2026).
- Laravel Boost đã cài: `CLAUDE.md`, `AGENTS.md`, `.claude/skills/`, `.agents/`, `.mcp.json`, `boost.json`.
- Database: `compose.yaml` chạy Postgres 17 ở cổng 5432 (user `psm` / mật khẩu `secret`). Docker chạy qua OrbStack.
  - `psm`: database dev. `php artisan migrate:fresh --seed` chạy được.
  - `psm_testing`: database cho test (`phpunit.xml`). Volume mới thì tạo lại: `docker compose exec -T pgsql psql -U psm -d psm -c "CREATE DATABASE psm_testing OWNER psm;"`.
- Tài khoản seed: `admin@example.com` / `password`. `DatabaseSeeder` từ chối chạy ở production.
- Data model (đã chỉnh theo mục 3.4, migration nháp sửa tại chỗ vì chưa deploy):
  - `app/Enums/`: 18 enum (thêm `Theme`, `Locale`, `FindingEventType`, `ChatNotificationKind`, `GoogleChatStatus`; `FindingState` chỉ còn `open` / `resolved`; `RuntimeSource::Manual`). Nhãn bọc `__()` (M1).
  - `database/migrations/2026_10_23_00000{1..9}_*`.
  - `app/Models/`: 16 model (thêm `Lockfile`, `ChatNotification`).
  - `database/factories/`: đủ factory cho mọi model, có state `admin()`, `withGoogleChatWebhook()`, `resolved()`, `stale()`, `eol()`, `security()`, `preNotice()`, `sent()`, `failed()`, `forAdmin()`.
  - `database/seeders/DemoSeeder.php`: 3 team, 5 project, 8 service (theo phụ lục A), mỗi service 1 deployment `production` và 1 runtime (`unknown` cho tới khi chạy `php artisan psm:sync-runtimes` lần đầu).
- Kiểm tra: `phpstan.neon` (Larastan level 6, `parseModelCastsMethod: true` để Larastan đọc `casts()`); Pint sạch; 19 test Pest xanh (`tests/Feature/Models`, `tests/Feature/Seeders`). Test dùng `LazilyRefreshDatabase`.
- **M1 (làm bằng Filament; giao diện đã làm lại bằng React ở mục "UI React" bên dưới, phần nghiệp vụ giữ nguyên):**
  - Resource `Teams` (Cài đặt), `Projects`, `Services` (Danh mục) trong `app/Filament/Resources/`. Không có Dashboard (D9): `/admin` chuyển tới resource đầu tiên.
  - Team: thông tin chung + Google Chat (URL chỉ hiện một phần, nút "Gửi thử", modal "Thay / Thêm webhook" gửi thử trước rồi mới lưu, dòng trạng thái lấy từ `chat_notifications`). Nút xóa team bị khóa khi còn project. Tab Projects.
  - Project: trang xem (infolist + tab Services có cột Runtime / Packages, `unknown` = chấm viền đứt), nút "Thêm service" điền sẵn project.
  - Service: form gồm thông tin service + "Môi trường production" (từ M2: danh sách động công nghệ + version, có gợi ý version và xem trước kết quả đánh giá ngay khi gõ; upload `composer.lock` / `Gemfile.lock` / `package-lock.json`, xóa lockfile). Lưu qua `App\Actions\UpdateProductionInventory`: version đổi → runtime về `unknown`; có thay đổi thì phát event `App\Events\DeploymentInventoryChanged` (after commit). Lưu lại y nguyên thì không phát event. M2 đã thêm listener queued chạy evaluator (thay cho "dispatch job" trong kế hoạch cũ).
  - Gửi tin: `App\Services\GoogleChatClient` (timeout, không theo redirect, redirect tính là lỗi) và `App\Actions\SendTestChatMessage` (ghi log vào `chat_notifications`; lỗi không chứa URL/token; webhook ứng viên gửi lỗi thì không ghi log để không làm webhook cũ thành "Lỗi gửi").
  - Giao diện: custom theme `resources/css/filament/admin/theme.css` (Tailwind 4 + Vite, đã `make:filament-theme`); palette theo `App\Enums\Theme::colors()` (Graphite / Forest đổi shade 500–600 để nút primary là zinc-900 / teal-700); Night ép dark mode, 3 theme kia ép light, ẩn nút light/dark của Filament. Menu "Giao diện" + nút VI / 日本語 trên topbar là Livewire `App\Livewire\AppearanceMenu` (render hook `USER_MENU_BEFORE`), lưu vào `users.theme` / `users.locale` rồi tải lại trang. Middleware `ApplyUserPreferences` (persistent) đặt locale và palette.
  - Song ngữ: chuỗi trong code viết tiếng Việt và bọc `__()`; `lang/ja.json` là bản dịch (đủ mọi key); `lang/vi|ja/validation.php` cho các rule đang dùng. `APP_LOCALE=vi`.
  - Avatar: `App\Filament\AvatarProviders\InitialsAvatarProvider` vẽ chữ tắt tại chỗ, thay cho mặc định của Filament (gọi ui-avatars.com, lộ tên admin ra bên thứ ba).
  - Kiểm tra: 80 test Pest xanh, Larastan level 6 sạch, Pint sạch. Đã xem bằng Brave headless: 4 theme, VI / JA, màn 390px.
- **M2 (danh mục version + đánh giá):**
  - Enum `App\Enums\Technology` (D11); màn **Công nghệ** (nhóm Cài đặt): danh sách có trạng thái danh mục (Đã đồng bộ / Lần gần nhất lỗi / Lỗi quá ngưỡng / Chưa đồng bộ) và nút "Đồng bộ ngay" (job); trang chi tiết `/admin/technologies/{php|nodejs|ruby}` chỉ xem: các nhánh với ngày hết hỗ trợ, các bản (lọc theo nhánh, tìm version) có đánh dấu bản vá bảo mật.
  - Nguồn: `App\Services\Catalog\EndoflifeDateClient` (API v1) + `ReleaseFeed`: `PhpNetReleaseFeed` (nhãn `security`), `NodejsReleaseFeed` (cờ `security`), `RubyLangReleaseFeed` (`cache.ruby-lang.org/pub/ruby/index.txt`, không có cờ). Bản mới nhất của nhánh = bản lớn nhất giữa endoflife.date và nguồn chính thức.
  - `App\Actions\SyncTechnologyCatalog` ghi heartbeat `catalog:{technology}` (vd. `catalog:php`) vào `source_heartbeats`; ngưỡng `PSM_CATALOG_STALE_AFTER_MINUTES` (mặc định 180) trong `config/psm.php`.
  - `App\Support\Version` (chuẩn hóa: `v20.11.0` → `20.11.0`, `8.3.12-1+deb.sury.org+1` → `8.3.12`; tìm nhánh dài nhất khớp). `App\Services\RuntimeEvaluator` theo mục 5.3; lý do lưu ở `runtimes.status_reason` (khóa dịch) + `status_context`.
  - Đánh giá chạy: ngay khi admin lưu (listener queued `EvaluateChangedDeployment`), sau mỗi lần đồng bộ, và `psm:evaluate-runtimes` lúc 00:05. Lịch trong `routes/console.php`: `psm:sync-runtimes` mỗi giờ.
  - **Chạy thật cần:** queue worker và scheduler. Dev: `composer run dev` (đã thêm `schedule:work` vào danh sách tiến trình). Production: cron `* * * * * php artisan schedule:run` + `php artisan queue:work`.
  - Đã chạy thật với nguồn thật ngày 23/10/2026: 8 service demo ra đúng `eol` / `outdated_security`. Test dùng fixture cắt từ dữ liệu thật trong `tests/Fixtures/catalog/` (Node.js 24 cắt ở 24.22.0 để có ca `outdated`).
  - Kiểm tra: 144 test Pest xanh, Larastan level 6 sạch, Pint sạch; xem bằng Brave headless.
- **M3 (finding + Google Chat):**
  - `findings` thêm `group_key`, `context` (jsonb), `notified_severity`, `notified_at` (sửa migration nháp tại chỗ). `App\Actions\SyncFindings` đồng bộ theo fingerprint (`App\Services\FindingData::fingerprint`, không chứa version): mở / tự đóng / mở lại / tăng mức, ghi `finding_events`. Runtime `unknown` **giữ nguyên** finding đang mở (thiếu dữ liệu không phải là đã sửa); runtime bị xóa → finding đóng.
  - `App\Services\RuntimeFindings`: `eol` và `outdated_security` mở finding; `outdated`, `unknown`, sắp EOL chỉ vào bản tổng hợp. `EvaluateRuntimes` đánh giá xong thì đồng bộ finding của các deployment liên quan; **người gọi** chạy `SendFindingAlerts` một lần ở cuối để gộp tin.
  - Gửi ngay: `App\Actions\SendFindingAlerts` gom theo team + `group_key` (cùng advisory, hoặc cùng quy tắc + công nghệ + version đích / nhánh EOL) → 1 card liệt kê mọi service, service mới thêm được đánh dấu "mới". Gửi lại chỉ khi mức tăng hoặc thêm service. Chính sách trong `App\Services\AlertPolicy` đọc `psm.alerts`.
  - Đường gửi: `QueueChatNotification` (dedupe_key unique, `createOrFirst`) → job `SendChatNotification` (retry có backoff 60/300/900/3600 giây, 4xx trừ 408/429 là lỗi vĩnh viễn, hết lượt → `failed_at` + cảnh báo admin). Team chưa có webhook → ghi lỗi + cảnh báo admin. Webhook admin phải có tiền tố Google Chat. Card do `App\Services\GoogleChatCards` dựng (cardsV2), viết bằng `psm.alerts.locale` (mặc định `vi`) dù admin đang dùng tiếng Nhật.
  - Bản tổng hợp `psm:send-digest` (giờ / ngày / múi giờ trong `psm.digest`, mặc định 08:00 `Asia/Ho_Chi_Minh` mọi ngày): finding mới từ bản trước, vẫn đang mở, đã tự đóng, số runtime thiếu dữ liệu / có bản mới hơn, runtime sắp hết hỗ trợ trong 30 ngày. Không có gì thì không gửi; mỗi team 1 bản/ngày.
  - `psm:check-sources` (15 phút): mỗi đợt nguồn lỗi quá ngưỡng → 1 tin vào space admin.
  - Cột "Finding mở" ở danh sách Team, Service và tab Services của Project. Dòng trạng thái Google Chat bỏ qua tin đang chờ retry.
- **M4 (thông báo sớm):** `psm:watch-notices` (15 phút) đọc feed php.net (Atom), nodejs.org vulnerability (RSS), ruby-lang.org news (RSS) qua `ReleaseSource::noticeFeed()`; parser `app/Services/Notices/` (không dùng mạng khi parse XML, từ chối DOCTYPE). Lọc từ khóa `psm.notices.keywords`; **bỏ qua bài "x.y.z Released"** (catalog đã báo đúng version cần nâng). Lưu `advisories` (`kind = pre_notice`, mức Critical / High theo chữ trong tin, CVSS, CVE). Mỗi team đang chạy công nghệ đó nhận 1 card "Cảnh báo chuẩn bị" mỗi tin (chỉ tin < 72 giờ). Tin Critical trong 14 ngày → finding `outdated_security` của công nghệ đó lên Critical và gửi lại. Không tạo finding cho tin báo trước (`FindingRule::PreNotice` chưa dùng). Heartbeat `notice:{nguồn}` (ngưỡng 120 phút). Fixture thật trong `tests/Fixtures/notices/` (có tin php.net "Upcoming Critical PHP Security Release" 20/10/2026 20:32 UTC).
- **M5 (lỗ hổng package):** tự parse lockfile (`app/Services/Lockfiles/`: composer.lock, Gemfile.lock, package-lock.json v1/v2/v3) → `components` (PURL qua `App\Support\PackageUrl`, `is_dev`), parse lại khi `content_hash` đổi; lockfile không đọc được thì giữ component cũ, lưu `parse_error`. `VulnerabilityMatcher` → `OsvVulnerabilityMatcher` (`querybatch` ≤ 1000, phân trang, chỉ lấy chi tiết advisory mới / đã sửa, bỏ advisory withdrawn). Mức: GitHub severity → CVSS v3.1 tự tính (`App\Support\Cvss3`) → `unknown`. Finding `dependency_vulnerable` theo (deployment, package, advisory), gom card theo advisory; dev dependency chỉ vào bản tổng hợp (`PSM_IMMEDIATE_DEV_DEPENDENCIES`). Quét khi lockfile đổi và `psm:scan-packages` mỗi 6 giờ; OSV lỗi → giữ finding đang mở, heartbeat `osv` (ngưỡng 720 phút). Migration nháp: `lockfiles` thêm `parsed_hash`, `parse_error`, `package_count`; unique của `components` thêm `version`; `advisories` thêm `source_modified_at`, `withdrawn_at`, `fixed_versions`. Cột Packages hiện số package và số package có lỗ hổng. Fixture OSV thật trong `tests/Fixtures/osv/`.
  - **Packagist cho Composer (24/10/2026):** `PackagistVulnerabilityMatcher` gọi `POST packagist.org/api/security-advisories/`, so version bằng `composer/semver` (thêm vào `require`) như `composer audit`. Advisory lưu theo mã PKSA (không đổi khi Packagist gộp nguồn) nên fingerprint ổn định; tiêu đề hiện mã GHSA, không có thì CVE (`Advisory::reference()`). Advisory chỉ có ở FriendsOfPHP không có mức độ → `unknown` → chỉ vào bản tổng hợp. `ScanPackages` chạy từng matcher theo hệ sinh thái; một nguồn lỗi thì finding của hệ sinh thái đó giữ mở, nguồn còn lại vẫn đồng bộ; heartbeat `packagist` và `osv` riêng. OSV tải chi tiết advisory song song (`PSM_OSV_CONCURRENCY`, mặc định 10). Fixture Packagist thật trong `tests/Fixtures/packagist/`.
  - **RubySec cho RubyGems (25/10/2026):** `RubySecVulnerabilityMatcher` tải tarball nhánh mặc định của `rubysec/ruby-advisory-db` (~600 KB, không có API) mỗi lần quét, đọc YAML bằng `symfony/yaml` (thêm vào `require`). Như `bundler-audit`: gem có lỗ hổng khi version không nằm trong `patched_versions` lẫn `unaffected_versions`, so bằng `App\Support\GemVersion` (theo `Gem::Version` / `Gem::Requirement`, đã đối chiếu với Ruby trên 14.008 trường hợp). Advisory lưu theo tên file (CVE-… / GHSA-…); tiêu đề hiện mã GHSA, không có thì CVE. Mức theo CVSS v4 → v3 → v2. Heartbeat `rubysec`. Fixture `tests/Fixtures/rubysec/` (tạo bằng `git archive` như GitHub).
- **UI React (24/10/2026, thay Filament — D2):**
  - Server: route trong `routes/web.php` (tiền tố `/admin`, tên `admin.*`), controller `app/Http/Controllers/Admin/`, form request `app/Http/Requests/Admin/`, resource `app/Http/Resources/Admin/` (dòng bảng). Bảng tìm / sắp xếp / phân trang qua query string (`App\Support\TableQuery`). Đăng nhập riêng (`SessionController`, giới hạn 6 lần / phút), chỉ admin (`EnsureUserIsAdmin`). Toast qua `Inertia::flash('toast', …)`.
  - `HandleInertiaRequests` chia sẻ người dùng, theme / ngôn ngữ, danh sách theme và bản dịch `lang/{locale}.json` (gửi một lần, chỉ khi không phải tiếng Việt). `ApplyUserPreferences` đặt locale trước khi chia sẻ.
  - Client: `resources/js/` (`pages/`, `layouts/admin-layout.tsx`, `components/admin/`, `components/ui/` là shadcn/ui đã chỉnh). Token màu và cỡ chữ trong `resources/css/app.css` (theo `styles.css` của prototype v2). Đổi theme tức thì (CSS theo `data-theme`), đổi ngôn ngữ thì tải lại trang. Font Inter và Noto Sans JP tự host (`@fontsource-variable/*`); Noto Sans JP chỉ nạp khi dùng tiếng Nhật (`resources/css/japanese.css`). Chuỗi viết tiếng Việt, dịch bằng `t('…')` từ cùng `lang/ja.json`; test `FrontendTranslationsTest` báo chuỗi thiếu bản dịch.
  - Route TypeScript sinh bằng Wayfinder (`resources/js/actions/`, không commit; plugin Vite tự sinh lại).
  - Ô version của service: lọc ngay trong trình duyệt theo tiền tố, nhận version không có trong danh mục ("Dùng “…”"); kết quả đánh giá lấy từ `GET /admin/runtime-evaluation`. Tìm kiếm ⌘K: `GET /admin/search`.
  - Kiểm tra: 320 test Pest (`tests/Feature/Admin/` thay cho test Filament), Larastan level 6 sạch, Pint sạch, `tsc` sạch; đã xem bằng Brave headless: 4 theme, VI / JA, màn 390px.
- **M6 (lockfile từ GitHub, D12 — 24/10/2026; chuyển cấu hình từ project sang service 25/10/2026):**
  - Cấu hình `psm.github` (`config/psm.php`, `.env`: `PSM_GITHUB_APP_ID`, `PSM_GITHUB_INSTALLATION_ID`, `PSM_GITHUB_PRIVATE_KEY_PATH`, `PSM_GITHUB_SYNC_TIME`, `PSM_GITHUB_SYNC_TIMEZONE`, `PSM_GITHUB_STALE_AFTER_MINUTES`; GitHub Enterprise: `PSM_GITHUB_HOST`, `PSM_GITHUB_API_URL`). Private key là file riêng ngoài web root (`chmod 600`), `.env` chỉ ghi đường dẫn.
  - `app/Services/Repositories/`: interface `RepositoryReader` (bind sang `GitHubRepositoryReader` trong `AppServiceProvider`), `GitHubAppAuthenticator` (JWT 9 phút → installation token 1 giờ, xin token mới khi còn < 5 phút, xin quyền `contents: read` + `metadata: read`), `GitHubRepository` (đọc full name `org/repo`). Reader có thêm `repositories()` (phân trang 100 / trang), `branchExists()` (`GET repos/{repo}/branches/{branch}`, `/` trong tên branch được encode), `commitUrl()`, `RepositoryNotFoundException` (404 / 409 / 422 = cấu hình sai, không retry).
  - `App\Actions\SyncServiceLockfiles`: lấy SHA đầu branch của service → liệt kê file trong thư mục service tại SHA đó → tải `composer.lock` / `Gemfile.lock` / `package-lock.json` → `App\Actions\ReplaceLockfiles` (tách từ `UpdateProductionInventory`, dùng chung cho upload). Lockfile đổi nội dung → `DeploymentInventoryChanged` (đánh giá lại như khi upload). Lockfile không còn trong thư mục → xóa (kể cả bản upload). File > 20 MB hoặc không đọc được → giữ bản cũ, ghi lỗi.
  - Lỗi cấu hình (repo / branch / thư mục không có, App chưa được cấp quyền, chưa cấu hình App) → `services.lockfiles_sync_error` + 1 tin vào space admin mỗi đợt lỗi (khóa `lockfile-sync:service-{id}:{lockfiles_synced_at}`, link "Mở service"). GitHub không trả lời → heartbeat `github` (ngưỡng 1500 phút), `psm:check-sources` báo admin. Cả hai trường hợp đều giữ lockfile cũ.
  - `psm:sync-lockfiles` (06:30 mỗi ngày, duyệt service có `repository`); job `SyncServiceLockfilesJob` chạy ngay khi tạo service có repo, đổi repo / branch / thư mục, và khi bấm "Lấy lockfile ngay" (`POST /admin/services/{service}/lockfile-sync`). Đổi repo / branch thì xóa trạng thái đồng bộ cũ.
  - Danh sách repo: bảng `accessible_repositories` (`full_name` unique, `default_branch`, `is_private`), `App\Actions\SyncAccessibleRepositories` thay toàn bộ danh sách (chạy khi admin bấm nút làm mới, `POST /admin/repositories/sync`, chạy luôn trong request; GitHub lỗi → toast lỗi, giữ danh sách cũ). Service giữ repo đã chọn kể cả khi repo rời danh sách (form ghi chú, lần đồng bộ sau báo lỗi).
  - Migration `2026_10_24_145411_add_lockfile_sync_to_projects_and_lockfiles`: `lockfiles` thêm `source` (enum `LockfileSource`: `upload` / `github`), `commit_sha` (và các cột GitHub ở `projects`, bị bỏ ở migration sau). `2026_10_25_145103_create_accessible_repositories_table`. `2026_10_25_145104_move_lockfile_sync_from_projects_to_services`: `services` thêm `repository`, `repository_branch`, `lockfiles_synced_at`, `lockfiles_commit_sha`, `lockfiles_sync_error`; `projects` bỏ 5 cột GitHub (chưa project nào dùng nên không chuyển dữ liệu). `projects.repository_url` giữ nguyên, chỉ để ghi thông tin.
  - UI: form service có section "Repository": chọn repo trong danh sách (có lựa chọn "Không dùng GitHub"), nút làm mới cạnh danh sách (khóa khi server chưa cấu hình App; làm mới giữ nguyên dữ liệu đang nhập), branch (gợi ý branch mặc định của repo), thư mục; trang sửa service hiện lần đồng bộ gần nhất (trạng thái, commit link GitHub, lần thành công, lỗi) và nút "Lấy lockfile ngay". Service có repo ẩn phần upload và hiện nguồn từng lockfile ("GitHub · abc1234 · lấy lúc …"). Form / trang project không còn phần GitHub.
  - Kiểm tra: test Pest mới `tests/Feature/Services/GitHubRepositoryReaderTest.php`, `tests/Feature/Actions/SyncServiceLockfilesTest.php`, `tests/Feature/Console/Commands/SyncRepositoryLockfilesTest.php`, `tests/Feature/Admin/AccessibleRepositorySyncControllerTest.php` và thêm vào `ServiceControllerTest` (helper `fakeGitHub()` trong `tests/Pest.php`, private key sinh lúc chạy test, không commit key); Larastan sạch, Pint sạch, `tsc` sạch. **Chưa thử với GitHub thật** — xem mục 3.2.
- Lịch (`routes/console.php`): `psm:sync-runtimes` mỗi giờ, `psm:watch-notices` và `psm:check-sources` 15 phút, `psm:scan-packages` 6 giờ, `psm:evaluate-runtimes` 00:05, `psm:send-digest` theo `psm.digest`, `psm:sync-lockfiles` 06:30 theo `psm.github`.
- Kiểm tra (M5): 297 test Pest xanh (gồm 6 kịch bản đầu cuối trong `tests/Feature/Scenarios/DetectionPipelineTest.php`), Larastan level 6 sạch, Pint sạch. Đã chạy thử với nguồn thật trên DB dev ngày 23/10/2026 (8 finding từ dữ liệu demo, 7 tin báo trước).
- Prototype UI (HTML/CSS/JS tĩnh, không gọi backend):
  - `public/prototypes/admin-v2/` — **bản đã chốt** (mục 10). Mở `index.html` để so sánh 4 theme.
  - `public/prototypes/admin/` — bản v1, đã loại.

### 3.2 Chưa làm / còn thiếu

- [ ] Chưa thử gửi thật lên Google Chat (card cardsV2) — cần một space thử và `PSM_ADMIN_CHAT_WEBHOOK` trước pilot.
- [ ] Lần poll đầu ở production sẽ lưu toàn bộ lịch sử feed nhưng chỉ gửi tin < 72 giờ; lần đồng bộ catalog đầu tiên sẽ gửi mọi finding hiện có — báo trước cho các team.
- [ ] Danh sách từ khóa thông báo sớm và quy tắc "critical/high" theo chữ là heuristic — xem lại khi pilot.
- [ ] `DemoSeeder` chưa có lockfile (thêm vào thì seed sẽ gọi OSV).
- [ ] Bản dịch `lang/ja.json` do AI viết — cần người dùng tiếng Nhật duyệt (câu hỏi mở 4).
- [ ] Chưa thử "Gửi thử" với một webhook Google Chat thật (test dùng `Http::fake()`).
- [ ] M6: chưa tạo GitHub App thật. Cần admin org: tạo App (quyền repository `Contents: Read`, `Metadata: Read`; không cần webhook, bỏ chọn "Active"), cài cho các repo cần theo dõi ("Only select repositories"), tạo private key, điền 3 biến `PSM_GITHUB_*` vào `.env`, rồi ở form 1 service bấm làm mới danh sách repo, chọn repo, lưu và xem "Lần đồng bộ gần nhất".
- [ ] M6: đang đọc lockfile ở **đầu branch**, chưa phải commit đang chạy trên production. Lệch nhau trong khoảng merge → deploy. Muốn đúng commit đang deploy thì đọc GitHub Deployments / Releases / tag (thêm quyền `Deployments: Read`).
- [ ] M6: chỉ đọc lockfile nằm **ngay trong** thư mục của service (không tìm sâu hơn). Service có `package-lock.json` ở thư mục con (vd. `frontend/`) cần tách thành service riêng hoặc mở rộng sau.

### 3.3 Điểm cần xem lại

- `DeploymentFactory` vẫn sinh `commit_sha` ngẫu nhiên; MVP nhập tay nên cột này để trống trong form M1.

### 3.4 Điều chỉnh data model cho MVP (đã làm ở M0)

| Bảng | MVP | Đã thay đổi |
|---|---|---|
| users | Dùng | Thêm `theme` (enum `Theme`, mặc định `graphite`) và `locale` (enum `Locale`: `vi` / `ja`, mặc định `vi`). Model mirror mặc định trong `$attributes`. |
| teams, team_user, projects, services | Dùng | `google_chat_webhook_url` cast `encrypted` và ẩn khi serialize. `escalation_email` chưa dùng. Team còn project thì không xóa được. |
| deployments | Dùng | Hiểu là "môi trường của service". MVP chỉ tạo `production`. Có quan hệ `runtimes`, `lockfiles`, `components`. |
| technologies | Bỏ | Công nghệ là enum trong code (D11). |
| runtimes | Dùng | `deployment_id` bắt buộc (cascade); `asset_id` nullable; **bỏ `app_path`**; `technology` (giá trị enum) thay cho `language`; unique `(deployment_id, technology)`; thêm `branch`, `status_context`; `source = manual`. |
| lockfiles (mới) | Dùng (M1 upload, M5 quét, M6 GitHub) | `deployment_id`, `ecosystem`, `path`, `content`, `content_hash` (sha256), `uploaded_at`; unique `(deployment_id, path)`. M6 thêm `source` (`upload` / `github`), `commit_sha`. |
| components | Dùng (M5) | Giữ nguyên. |
| runtime_branches, runtime_releases | Dùng | `technology` thay cho `language`. `runtime_branches` thêm `released_at`, `is_eol` (endoflife.date báo hết hỗ trợ dù không có ngày). |
| source_heartbeats | Dùng | Danh mục version: khóa `catalog:{technology}`; tin bảo mật: `notice:{technology}`. |
| advisories | Dùng | Chứa thông báo sớm (M4) và advisory OSV (M5). `technology` thay cho `language`. |
| findings | Dùng, rút gọn | `state` chỉ `open` / `resolved` (mặc định `open`). `sla_due_at`, `accepted_until` giữ cột nhưng chưa dùng. |
| **chat_notifications** (mới) | Dùng | Nhật ký gửi tin Google Chat: `team_id` (null = space admin), `dedupe_key` (unique), `kind`, `payload`, `attempts`, `sent_at`, `failed_at`, `last_error`. **Đặt tên `chat_notifications` thay cho `notifications`** để không trùng bảng database notification của Laravel / Filament. |
| finding_events | Dùng | Chỉ sự kiện hệ thống: `type` (`opened` / `resolved` / `escalated`) + `data` (jsonb). Bỏ `user_id`, `from_state`, `to_state`, `note` (thêm lại khi có vòng đời thủ công). |
| assets, asset_reports | Giữ, chưa dùng | Chủ dự án chọn giữ (23/10/2026) cho collector sau MVP. |

## 4. Câu hỏi còn mở

**Giả định tạm, chủ dự án quy định sau.** Không chặn MVP. Code phải đọc các giá trị này từ cấu hình (`config/psm.php` + `.env`), không viết cứng:

1. Mỗi team có space Google Chat và webhook. Có một space riêng cho admin để nhận lỗi hệ thống (`PSM_ADMIN_CHAT_WEBHOOK`). Trong test dùng `Http::fake()`.
2. Giờ gửi bản tổng hợp: mặc định 08:00 `Asia/Ho_Chi_Minh`, gửi mọi ngày trong tuần. Cấu hình được cả giờ và ngày.
3. Mức nào gửi ngay, mức nào chỉ vào bản tổng hợp: mặc định theo bảng ở mục 5.4. Chính sách nằm trong config, đổi được mà không sửa code.

**Không chặn MVP:** Git hosting, cách deploy server truyền thống, ngưỡng SLA, yêu cầu SBOM / VEX. (Filament hay React: đã chọn React — D2.)

**Về giao diện (mục 10), cần chủ dự án xác nhận:**

4. Bản dịch tiếng Nhật trong `public/prototypes/admin-v2/i18n.js` do AI viết, cần người dùng tiếng Nhật duyệt. Thuật ngữ cần chốt: Finding → 検出結果, Đã nhận → 確認済み, Email leo thang → エスカレーション先メール.
5. ~~Custom theme của Filament cần Tailwind + Vite~~ — đã làm ở M1; từ 24/10/2026 dùng Tailwind + Vite cho UI React.

## 5. Logic phát hiện kịp thời (lõi của MVP)

### 5.1 Nguyên tắc

- Phát hiện theo **hai chiều**: dữ liệu của mình đổi (admin sửa version, upload lockfile) → đánh giá lại ngay service đó; dữ liệu bên ngoài đổi (có bản vá mới, có tin cảnh báo, có advisory mới, đến ngày EOL) → đánh giá lại mọi service bị ảnh hưởng.
- Mọi đánh giá đều **idempotent**: chạy lại bao nhiêu lần cũng ra cùng tập finding, không gửi lại tin cũ.
- **Im lặng vì lỗi là lỗi nghiêm trọng nhất.** Nguồn nào không đồng bộ thành công quá ngưỡng thì báo admin; runtime phụ thuộc nguồn đó chuyển `unknown`, không giữ `ok` cũ.

### 5.2 Tín hiệu và tần suất

| Tín hiệu | Nguồn | Tần suất | Độ trễ tối đa | Kết quả |
|---|---|---|---|---|
| Admin sửa version / lockfile | Màn hình admin | Ngay khi lưu (queued job) | Vài giây | Đánh giá lại service đó |
| Lockfile đổi trên GitHub (D12) | GitHub API (GitHub App, chỉ đọc) | 1 lần/ngày lúc 06:30, và khi admin bấm "Lấy lockfile ngay" | 1 ngày | Đánh giá lại service có lockfile đổi |
| Thông báo sớm của hãng | Feed php.net, nodejs.org, ruby-lang.org | 15 phút | ~15 phút | "Cảnh báo chuẩn bị" cho team đang chạy ngôn ngữ đó |
| Bản vá bảo mật mới | Release JSON / YAML của 3 ngôn ngữ | 1 giờ | ~1 giờ | `outdated_security` |
| Có bản mới (không phải bảo mật) | Như trên | 1 giờ | — | `outdated`, chỉ vào bản tổng hợp |
| EOL | endoflife.date | Đồng bộ 1 lần/ngày; đánh giá theo ngày lúc 00:05 | 1 ngày | `eol`; sắp EOL trong 30 ngày → bản tổng hợp |
| Advisory package mới | Packagist, RubySec, OSV | 6 giờ, và ngay khi lockfile đổi | 6 giờ | Finding package |
| Nguồn lỗi / im lặng | `source_heartbeats` | Kiểm tra 15 phút | Theo ngưỡng từng nguồn | Tin vào space admin |

### 5.3 Quy tắc đánh giá runtime (`RuntimeEvaluator`)

Xét theo thứ tự, dừng ở quy tắc đầu tiên khớp:

1. `unknown`: thiếu version, không chuẩn hóa được, danh mục chưa đồng bộ hoặc quá ngưỡng heartbeat, không có nhánh khớp, hoặc version chỉ ghi nhánh (`8.3`).
2. `eol`: nhánh có `is_eol`, hoặc hôm nay > `security_support_until`.
3. `unknown`: version mới hơn bản mới nhất đã biết, hoặc (nguồn chính thức) version không có trong danh sách của hãng.
4. `outdated_security`: trong nhánh có bản mới hơn **là bản vá bảo mật**. Cờ bảo mật: php.net và nodejs.org có. Khi nguồn không có cờ (Ruby): coi là bản vá bảo mật nếu nhánh chỉ còn vá bảo mật **hoặc không biết nhánh đang ở giai đoạn nào** (Ruby không có ngày hết hỗ trợ tích cực) — **chọn báo thừa hơn bỏ sót, xem lại khi pilot**.
5. `outdated`: `latest_version` của nhánh mới hơn version đang dùng.
6. `ok`.

### 5.4 Finding và chống trùng

- **Fingerprint** = hash(quy tắc, deployment, ngôn ngữ hoặc package, nhánh hoặc mã advisory). **Không** chứa version đang chạy: nâng từ 8.3.10 lên 8.3.11 mà vẫn thiếu bản vá thì vẫn là finding cũ, không gửi lại.
- Đánh giá xong: finding mới → `open`; finding đang `open` mà không còn khớp → `resolved` (tự động). Quy tắc đổi (ví dụ `outdated_security` → `eol`) → đóng finding cũ, mở finding mới.
- Mức và cách gửi **mặc định** (cấu hình được, chốt sau — xem mục 4):

| Vấn đề | Mức | Gửi |
|---|---|---|
| Thông báo sớm của hãng | — | Ngay |
| `eol` | High | Ngay |
| `outdated_security` | High (Critical nếu tin của hãng ghi critical) | Ngay |
| Package Critical / High | Theo OSV | Ngay |
| Package Medium / Low, `outdated`, sắp EOL, `unknown` | — | Chỉ bản tổng hợp |

### 5.5 Gửi Google Chat

- **Gửi ngay**: gom theo team và theo vấn đề (cùng advisory, hoặc cùng quy tắc + ngôn ngữ + version đích) → 1 card liệt kê N service.
- **Bản tổng hợp** (giờ và ngày gửi cấu hình được, mặc định 08:00 mọi ngày): mỗi team một card gồm finding mới từ hôm qua, finding đang mở, finding đã tự đóng, số runtime `unknown`. Không có gì thì không gửi.
- **Gửi lại** chỉ khi mức tăng, hoặc có thêm service bị cùng vấn đề. Không nhắc lặp tin gửi ngay; bản tổng hợp hằng ngày là lời nhắc.
- **Gửi tin chắc chắn**: queued job, retry có backoff; `chat_notifications.dedupe_key` unique để không gửi hai lần; thất bại sau khi hết lượt thử → báo space admin.
- Team chưa có webhook → không mất tin: ghi `chat_notifications` với lỗi, đưa vào cảnh báo admin.
- Nút trên card: mở trang sửa service (`/admin/services/{id}/edit`), mở link nguồn tin gốc.

### 5.6 Cách kiểm chứng

- Fixture cho từng nguồn (JSON / YAML / Atom đã lưu), `Http::fake()` cho nguồn và cho webhook Google Chat, `travelTo()` cho ngày EOL.
- Kịch bản đầu cuối (test Pest):
  1. Service PHP 8.3.x → có bản vá bảo mật mới → đúng 1 tin cho đúng team; chạy lại sync → không có tin thứ hai.
  2. Đổi version sang nhánh EOL → finding cũ đóng, finding `eol` mở, đúng 1 tin. Sửa lại version → finding đóng, xuất hiện trong bản tổng hợp hôm sau.
  3. 3 service của cùng team cùng bị → 1 tin liệt kê 3 service.
  4. Nguồn php.net lỗi quá ngưỡng → runtime PHP thành `unknown`, admin nhận 1 tin.
- Chỉ số trong pilot: **độ trễ từ lúc nguồn công bố đến lúc tin tới Google Chat**, số tin / tuần / team, tỷ lệ báo sai, tỷ lệ `unknown`.

## 6. Kế hoạch tiếp theo (MVP rút gọn, ước lượng khoảng 3 tuần)

| Mốc | Nội dung | Xong khi |
|---|---|---|
| **M0** Nền tảng (1–2 ngày) — **xong 23/10/2026** | `git init` (hỏi trước khi commit); Pint; `phpstan.neon` + Larastan; chỉnh data model theo mục 3.4; factory còn thiếu; seeder tài khoản admin và dữ liệu demo (3 team, 8 service); test migration và quan hệ model | `migrate:fresh --seed` chạy được; đăng nhập `/admin` bằng tài khoản seed; test xanh |
| **M1** Quản lý thông tin project + giao diện (3 ngày) — **xong 23/10/2026** | Filament resource: Team (webhook, nút "Gửi thử"), Project, Service kèm môi trường production (version runtime từng ngôn ngữ, upload lockfile). Lưu → dispatch job đánh giá. Giao diện theo mục 10: custom theme (hỏi trước khi cài, mục 4.5), 4 theme theo `users.theme`, VI / JA theo `users.locale`, `lang/vi.json` + `lang/ja.json`, nhãn enum qua `__()` | Tạo được team / project / service; "Gửi thử" thành công (test dùng `Http::fake()`, thử tay bằng một webhook bất kỳ); đổi được 4 theme và VI / JA trong `/admin`, lựa chọn giữ lại sau khi đăng nhập lại |
| **M2** Danh mục runtime + đánh giá (3 ngày) — **xong 23/10/2026**, mở rộng theo D11 | `psm:sync-runtimes` (1 giờ/lần, EOL 1 lần/ngày); chuẩn hóa version; `RuntimeEvaluator` (mục 5.3); heartbeat theo nguồn | Fixture 3 ngôn ngữ ra đủ 4 trạng thái + `unknown` |
| **M3** Finding + Google Chat (3 ngày) — **xong 23/10/2026** | Đồng bộ finding theo fingerprint (mục 5.4); gửi ngay + gộp tin; bản tổng hợp; chính sách gửi và lịch gửi đọc từ `config/psm.php`; retry, chống trùng; cảnh báo admin khi nguồn lỗi | 4 kịch bản ở mục 5.6 pass |
| **M4** Watcher thông báo sớm (2 ngày) — **xong 23/10/2026** | Feed 3 ngôn ngữ (15 phút/lần); lọc từ khóa; đối chiếu ngôn ngữ đang dùng; "Cảnh báo chuẩn bị" | Fixture tin php.net 20/10/2026 → đúng các team chạy PHP, mỗi team 1 tin, chạy lại không gửi lại |
| **M5** Lỗ hổng package (3 ngày) — **xong 23/10/2026**, chọn tự parse + OSV API (không thêm dependency) | Parse lockfile upload → component (PURL); `VulnerabilityMatcher` qua OSV; quét lại 6 giờ/lần và khi lockfile đổi; đánh dấu dev dependency. Đầu M5 chọn: tự parse + OSV API `querybatch`, hay cài `osv-scanner` (cần chủ dự án đồng ý vì thêm dependency) | Lockfile fixture có package lỗ hổng → finding đúng team, gộp đúng; advisory mới xuất hiện → có tin ở lần quét sau |
| **M6** Lockfile từ GitHub (D12) — **xong 24/10/2026** (chưa thử với GitHub thật) | GitHub App chỉ đọc; service có repo (chọn từ danh sách) + branch (kiểm tra khi lưu) + thư mục; đọc lockfile mỗi ngày + "Lấy lockfile ngay"; báo admin khi cấu hình sai hoặc GitHub lỗi | Fixture repo giả: lockfile đúng thư mục, đúng commit; lockfile không đổi → không đánh giá lại; thư mục sai → giữ lockfile cũ + 1 tin admin; GitHub lỗi → heartbeat |
| **Pilot** (3–5 ngày) | Nhập tay 5–10 service thật, đủ 3 ngôn ngữ | Đo các chỉ số ở mục 5.6; chỉnh ngưỡng và mức gửi |

M5 có thể cắt nếu hết thời gian; M0–M4 đã đủ kiểm chứng đường ống phát hiện → Google Chat.

## 7. Để sau MVP

| Tính năng | Ghi chú |
|---|---|
| Màn hình Findings (inbox, gom nhóm, phím tắt), trang chi tiết, dashboard, ma trận runtime, dòng thời gian nhánh | Làm bằng React (D2). Theo spec mục 10; màn Tổng quan trong prototype v2 là mẫu. ⌘K tìm project / service / team / công nghệ đã có. |
| Cài đặt gửi tin theo từng team, email leo thang, tab thành viên của team | Có trong prototype v2 (mục 10.6) nhưng ngoài MVP: MVP dùng chính sách chung trong `config/psm.php` và chỉ admin đăng nhập. |
| Vòng đời thủ công: Đã nhận, Đã vá, Đã xác minh theo instance, Không ảnh hưởng, Chấp nhận rủi ro; SLA | MVP chỉ `open` / `resolved` tự động. |
| Google SSO, policy theo team, dev đăng nhập xem team mình | MVP chỉ admin đăng nhập. |
| Collector trên server, reporter cho CI (ECS), `POST /api/ingest`, token theo asset, hàng chờ asset chưa có chủ | Thay cho nhập tay. Cần trả lời câu hỏi về deploy và tỷ lệ ECS. |
| **CI/CD đẩy SBOM lên Hub** (phương án 1 của D12, để sau 24/10/2026) | Mỗi repo thêm 1 bước CI (vd. Syft / Trivy tạo CycloneDX) gửi lên `POST /api/ingest` kèm token theo project. Ưu điểm: Hub không cần quyền đọc source code; quét được cả image / OS (giai đoạn container). Nhược: cấu hình từng repo, tốn thời gian CI, cần parser CycloneDX / SPDX. GitLab self-host + runner riêng thì không tốn phí theo phút, chỉ tốn máy chạy runner. Làm khi tới giai đoạn quét OS / container hoặc khi có repo ngoài GitHub. Lưu thêm `lockfiles.source = ci`. |
| Đọc lockfile tại **commit đang deploy** (GitHub Deployments / Releases / tag), lockfile ở thư mục con, yarn / pnpm từ repo | M6 đọc đầu branch, chỉ ngay trong thư mục service. |
| GitLab self-host | Thêm một `RepositoryReader` (Group Access Token `read_repository`, `GET /projects/:id/repository/files/:path/raw?ref=`), chọn reader theo host của repository URL. Cùng mạng nội bộ nên dùng được webhook. |
| Runtime `vulnerable` theo dải CVE (nodejs/security-wg, rubysec) | `outdated_security` đã đủ để báo kịp thời; phần này bổ sung mã CVE. |
| Email dự phòng | MVP dùng space admin. |
| Framework EOL (Laravel, Rails), CISA KEV, yarn / pnpm | Thêm nhanh ở M5 nếu còn thời gian. |
| OS / container (Trivy, dữ liệu distro), Jira, AI phân loại tin, xem lại Dependency-Track, SBOM / VEX | Như kế hoạch cũ. |

## 8. Tham chiếu kỹ thuật

### Nguồn dữ liệu runtime

| | PHP | Node.js | Ruby |
|---|---|---|---|
| Release (M2 dùng) | `https://www.php.net/releases/?json&version={major}&max=1000` (nhãn `security`) | `https://nodejs.org/dist/index.json` (cờ `security`) | `https://cache.ruby-lang.org/pub/ruby/index.txt` (không có cờ bảo mật) |
| Nhánh, EOL (M2 dùng) | `https://endoflife.date/api/v1/products/php/` | `.../products/nodejs/` | `.../products/ruby/` (không có `eoasFrom`) |

Thêm công nghệ mới (D11): cần mã sản phẩm `https://endoflife.date/api/v1/products/{product}/`, một nguồn liệt kê mọi bản và một feed tin bảo mật sớm của hãng.

| | PHP | Node.js | Ruby |
|---|---|---|---|
| CVE theo dải version (sau MVP) | Chưa có nguồn có cấu trúc tốt | `nodejs/security-wg` `vuln/core/index.json` | `rubysec/ruby-advisory-db` thư mục `rubies/ruby` |
| Thông báo sớm | `https://www.php.net/feed.atom` | `https://nodejs.org/en/feed/vulnerability.xml` | `https://www.ruby-lang.org/en/feeds/news.rss` |
| Framework EOL (sau MVP) | endoflife.date `laravel` | — | endoflife.date `rails` |

### Những chỗ dễ sai

- Version nhập tay có thể có tiền tố / hậu tố (`v20.11.0`, `8.3.12-1+deb.sury.org+1`) → chuẩn hóa khi lưu, giữ `raw_version`.
- Package PHP của distro (`8.1.2-1ubuntu2.x`) là bản vá ngược; gắn `source: distro_package` và hạ độ tin cậy. Xử lý đúng thuộc giai đoạn OS.
- Thiếu lockfile → `unknown`, không phải `ok`.
- Dependency chỉ dùng khi build frontend → `scope: build`, ưu tiên thấp.
- Package private không có trong OSV → ghi rõ giới hạn.
- Dải version: Node dùng semver (`composer/semver` xử lý được phần lớn); Ruby có `~>` cần tự chuyển (ví dụ `~> 3.2.3` → `>=3.2.3 <3.3.0`).
- Không chạy code của repo; nếu phải cài dependency thì dùng `--no-scripts`.
- Sau MVP, khi có collector: một server có thể có nhiều version (`php` CLI khác `php-fpm`; nvm / rbenv). Collector phải đọc **binary mà từng app khai báo**, không dùng `which php`. Image `php` chính thức không có trong `dpkg` → đọc `PHP_VERSION` hoặc `php -v`.

## 9. Cách làm việc đã thống nhất

- **Không làm vượt phạm vi.** Mỗi bước lớn (scaffold, cài package, đổi cấu trúc thư mục, viết nhiều file) phải được chủ dự án đồng ý trước.
- Làm từng phần nhỏ, dùng được ngay, có tiêu chí "xong khi".
- Không để AI tự viết auth / crypto; dùng Socialite, policy, gate.
- Sau mỗi phần lớn: Pint, Larastan, test, security review.
- Commit chỉ khi được yêu cầu.

## 10. Spec giao diện đã chốt (prototype v2)

> Chốt ngày 23/10/2026. Nguồn sự thật về giao diện là `public/prototypes/admin-v2/`. Khi spec này và prototype khác nhau, theo prototype rồi cập nhật lại mục này.

### 10.1 Prototype

| File | Nội dung |
|---|---|
| `index.html` | So sánh 4 theme trên cùng màn Tổng quan |
| `dashboard.html` | Tổng quan (sau MVP) |
| `teams.html`, `team.html` | Danh sách team; sửa team gồm các tab Cài đặt / Thành viên / Projects |
| `project.html` | Xem project; các tab Services / Deployments / Findings |
| `styles.css` | Token màu theo theme, token cỡ chữ theo ngôn ngữ, component |
| `i18n.js` | Từ điển Việt → Nhật (khớp nguyên câu + mẫu regex), nguồn cho `lang/ja.json` |
| `app.js` | Sidebar, topbar, menu giao diện, modal, toast, ⌘K |

Tham số URL: `?theme=graphite|ocean|forest|night`, `?lang=vi|ja`, `?state=` (trạng thái demo). Ô "Prototype" ở góc phải dưới chỉ dùng để xem trạng thái demo (rỗng, đang tải, lỗi, nguồn lỗi, webhook lỗi) và **không làm** trong sản phẩm.

### 10.2 Nguyên tắc thị giác

- Flat, tối giản: một màu nhấn (primary), viền 1px, bo góc 8px, không đổ bóng (trừ menu, toast, modal), không gradient.
- Sidebar cùng màu nền trang và lùi về sau nội dung. Chỉ mục Findings có số đếm (màu đỏ).
- Mức độ: badge nền nhạt **kèm icon** chỉ cho Critical (đỏ) và High (cam). Medium / Low dùng badge xám kèm icon.
- Trạng thái: chấm nhỏ + chữ, không có nền. `unknown` / "Thiếu dữ liệu" dùng **chấm viền đứt**, không bao giờ trông giống "Đạt".
- Hạn SLA chỉ đỏ khi đã quá hạn; còn hạn thì dùng chữ xám.
- Màu ngữ nghĩa (đỏ, cam, vàng, xanh lá) **không đổi theo theme**; theme chỉ đổi màu nhấn, nền và thang xám.
- Mỗi màn có: tiêu đề + mô tả ngắn, nút hành động chính ở bên phải, nội dung chính là bảng hoặc section.

### 10.3 Theme

Người dùng chọn trong menu **Giao diện** trên topbar. Lựa chọn lưu theo người dùng (`users.theme`; prototype lưu `localStorage['psm-theme']`). Mặc định là Graphite.

| Theme | Nền / bề mặt | Viền | Chữ | Primary |
|---|---|---|---|---|
| Graphite | `#fafafa` / `#ffffff` | `#e4e4e7` | `#18181b` | `#18181b` (nút đen) |
| Ocean | `#f8fafc` / `#ffffff` | `#e2e8f0` | `#0f172a` | `#2563eb` |
| Forest | `#fafaf9` / `#ffffff` | `#e7e5e4` | `#1c1917` | `#0f766e` (teal-700, đạt AA với chữ trắng) |
| Night | `#09090b` / `#111113` | `#26262b` | `#f4f4f5` | `#4f46e5` (indigo-600); link `#a5b4fc` |

Cách làm (UI React):

- Server đặt `<html data-theme="…">` theo `users.theme` (`resources/views/app.blade.php`); token màu của mỗi theme là biến CSS trong `resources/css/app.css`, Tailwind đọc qua `@theme inline`.
- Night là theme tối duy nhất (`dark:` = `[data-theme='night']`). Không có nút light / dark riêng.
- Menu Giao diện trên topbar đổi `data-theme` ngay rồi lưu `PUT /admin/preferences` ở nền.

### 10.4 Chữ và song ngữ Việt / Nhật

Chuyển ngôn ngữ bằng nút **VI / 日本語** trên topbar. Lựa chọn lưu theo người dùng (`users.locale`; prototype lưu `localStorage['psm-lang']`). Trang đặt `<html lang="vi|ja">`, và toàn bộ token chữ đổi theo `:root:lang(ja)`.

| Token | Tiếng Việt | Tiếng Nhật |
|---|---|---|
| Font | Inter | Inter (Latin) + **Noto Sans JP** (kana / kanji); dự phòng Hiragino Sans, Yu Gothic UI, Meiryo |
| Chữ thường (`--fs-base`) | 15px | 14.5px |
| Chữ phụ (`--fs-sm`) | 14px | 13.5px |
| Chữ nhỏ (`--fs-xs`) | 12.5px | 12px |
| Sidebar (`--fs-nav`) | 14.5px | 14px |
| Tiêu đề trang (`--fs-h1`) | 26px | 24px |
| Số liệu (`--fs-stat`) | 30px | 30px |
| Line-height | 1.55 | 1.7 |
| Letter-spacing | 0 | 0.02em |

- Lý do: kanji / kana trông to và đặc hơn chữ Latin cùng cỡ, nên chữ Nhật nhỏ hơn nửa bậc nhưng giãn dòng cao hơn.
- Tiếng Nhật: tiêu đề dùng `font-feature-settings: 'palt'`; nội dung dùng `line-break: strict` và `word-break: auto-phrase`.
- Font tự host trong sản phẩm (`@fontsource-variable/inter`, `@fontsource-variable/noto-sans-jp`; Noto Sans JP chỉ nạp khi dùng tiếng Nhật). Prototype tải font từ Google Fonts chỉ để xem thử.
- Bản dịch nằm trong `lang/ja.json` (lấy từ `i18n.js`), dùng chung cho server (`__()`) và React (`t()`); key là câu tiếng Việt. Nhãn enum bọc `__()`.
- Middleware `ApplyUserPreferences` đặt `App::setLocale(auth()->user()->locale)`; `<html lang>` theo locale của app.
- Định dạng theo ngôn ngữ: ngày `23/10/2026` ↔ `2026/10/23`; thời gian tương đối `12 phút trước` ↔ `12 分前`; `Còn 14 giờ` ↔ `残り 14 時間`; `Quá 2 ngày` ↔ `2 日超過`.
- Giữ nguyên, không dịch: mức độ (Critical, High, Medium, Low), SLA, CVE, tên môi trường (production, staging), Webhook URL, Google Chat, tên service, version.

### 10.5 Component → React

| Prototype | UI React | Ghi chú |
|---|---|---|
| Sidebar có nhóm (không tên / Danh mục / Cài đặt), người dùng ở chân | `layouts/admin-layout.tsx` | Số đếm Findings khi có màn Findings |
| Topbar: tìm kiếm ⌘K | `components/admin/command-palette.tsx` (cmdk) + `GET /admin/search` | Có |
| Topbar: menu Giao diện, VI / 日本語 | `components/admin/appearance-menu.tsx` | Có |
| Breadcrumb + tiêu đề + mô tả + nút hành động | `components/admin/page-header.tsx` | Có |
| Bảng, dòng phụ dưới tên, tìm / sắp xếp / phân trang | `components/admin/data-table.tsx` + `hooks/use-table-query.ts` | Có |
| Chấm trạng thái + chữ, chấm viền đứt cho `unknown` | `components/admin/status-dot.tsx` (`.status-dot` trong `app.css`) | Có |
| Section mô tả bên trái, field bên phải | `components/admin/form-section.tsx` | Có |
| Ô chọn có tìm kiếm, nhận giá trị mới | `components/admin/combobox.tsx` | Lọc trong trình duyệt |
| Tabs dạng pill | `components/admin/tabs.tsx` | Có |
| Khối thông tin nhãn – giá trị 3 cột | `<dl>` trong `pages/projects/show.tsx` | Có |
| Modal có form, xác nhận xóa | `components/ui/dialog.tsx`, `components/admin/confirm-button.tsx` | Có |
| Toast | sonner (`components/ui/sonner.tsx`) + `Inertia::flash('toast')` | Có |
| Badge mức độ có icon, khối 4 số liệu, callout nguồn lỗi, toggle, j / k trong bảng | Chưa làm | Sau MVP |

### 10.6 Màn hình và phạm vi MVP

| Màn | Trong prototype | Làm ở MVP |
|---|---|---|
| Danh sách Teams | Bảng: tên + slug, thành viên, số project, trạng thái Google Chat (Hoạt động / Lỗi gửi / Chưa cấu hình), số finding mở | M1. Cột finding mở thêm ở M3. |
| Sửa team — Thông tin chung | Tên, slug (chữ thường, số, gạch ngang) | M1 |
| Sửa team — Google Chat | URL webhook chỉ hiện một phần; nút "Gửi thử"; modal thay webhook kiểm tra tiền tố `https://chat.googleapis.com/v1/spaces/`; dòng trạng thái gửi gần nhất | M1. Dòng trạng thái đọc từ bảng `chat_notifications` (M3). |
| Sửa team — Khi nào gửi, Email leo thang | Toggle theo team; email nhóm | **Sau MVP.** MVP dùng chính sách chung trong `config/psm.php` (mục 4). |
| Sửa team — Thành viên | Vai trò, bật / tắt nhận cảnh báo | **Sau MVP** (cần SSO, cần thêm cột vai trò vào `team_user`). |
| Sửa team — Projects, Xóa team | Bảng project; nút xóa bị khóa khi team còn project (`restrictOnDelete`) | M1 |
| Xem project | Infolist (repository, team, kênh cảnh báo, ngày tạo) + tab Services | M1. Cột trạng thái runtime có từ M2, cột packages từ M5, cột finding mở từ M3. |
| Project — Deployments | Commit, release, instance theo môi trường | MVP chỉ có `production` nhập tay (D8): hiện version runtime, lockfile, thời điểm cập nhật. Commit / instance để sau. |
| Project — Findings | Mức, vấn đề, service, hạn, trạng thái | MVP chỉ `open` / `resolved` (D6). Không hiện hạn SLA. |
| Tổng quan | Callout, 4 số liệu, bảng "Cần xử lý", nguồn dữ liệu, theo team | **Sau MVP** (D9). |

### 10.7 Trạng thái hiển thị

- **Rỗng:** một câu nói rõ lý do và một nút hành động. Ví dụ: "Không còn việc cần xử lý — lần quét gần nhất 12 phút trước".
- **Đang tải:** skeleton theo đúng bố cục.
- **Lỗi tải trang:** khối lỗi có nút "Thử lại", và nói rõ việc gửi tin Google Chat có bị ảnh hưởng hay không.
- **Nguồn dữ liệu lỗi quá ngưỡng:** callout đỏ trên cùng trang kèm nút "Chạy lại". Đây là trạng thái "hệ thống đang im lặng vì lỗi" (mục 5.1).
- **Thiếu dữ liệu** (không có lockfile, version không rõ): chấm viền đứt + lý do. Không tính là "Đạt".

### 10.8 Khả năng truy cập

- Mức độ và trạng thái luôn có màu + icon (hoặc hình dạng chấm) + chữ.
- Chữ trên nền đạt WCAG AA ở cả 4 theme. Primary của Forest và Night đã chọn đậm hơn để chữ trắng trên nút đạt AA.
- Có viền focus rõ ràng; Esc đóng modal và menu; nút ngôn ngữ có `lang` riêng để trình đọc màn hình đọc đúng.

---

## Phụ lục A — Prompt tạo prototype bằng Open Design

> **Đã thay bằng prototype v2 (D10, mục 10).** Giữ lại để tham khảo nội dung màn hình và dữ liệu mẫu khi làm UI đầy đủ sau MVP.

Dùng nguyên văn. Không cần Mobbin Pro; có thể đính kèm ảnh chụp màn hình tham khảo.

````markdown
# Vai trò
Bạn là product designer cho một công cụ nội bộ về bảo mật. Hãy tạo một prototype web có thể click được, với UX rõ ràng và dễ dùng cho dev và tech lead.

# Sản phẩm
**Project Security Manager** — trang web nội bộ của một công ty phần mềm, theo dõi lỗ hổng của:
1. **Runtime ngôn ngữ** đang chạy thật: PHP, Ruby, Node.js. Có 5 quy tắc trạng thái:
   - Có lỗ hổng (version nằm trong dải CVE đã biết)
   - Hết hỗ trợ (nhánh EOL)
   - Thiếu bản vá bảo mật (đã có bản patch bảo mật mới hơn)
   - Có bản mới hơn
   - Đạt
   - Ngoài ra có trạng thái **Thiếu dữ liệu**, và trạng thái này **không bao giờ được hiển thị như "Đạt"**.
2. **Package của ứng dụng** từ lockfile: Composer, Bundler, npm/yarn/pnpm (dữ liệu OSV).
3. **Thông báo sớm** từ hãng khi chưa có CVE. Ví dụ thật: ngày 20/10/2026 php.net báo trước sẽ phát hành bản vá cho 2 lỗ hổng critical (CVSS 9.2) vào 21/10/2026 lúc 14:00 UTC.

Hệ điều hành (OS) **chưa** thuộc phạm vi MVP.
Kênh nhận cảnh báo chính là **Google Chat**. Người dùng thường bấm link từ Chat để mở trang chi tiết, kể cả trên điện thoại.

# Người dùng
- **Security owner / admin** (1–2 người): xem toàn cảnh, triage, cấu hình nguồn dữ liệu, team, asset.
- **Dev / tech lead của từng team**: chỉ quan tâm finding của team mình. Muốn biết trong 5 giây: *cái gì, ở đâu, sửa thế nào, hạn khi nào*.
- (Sau MVP) Quản lý: xem xu hướng.

# Khái niệm và dữ liệu
Team → Project → Service (kiểu: Server hoặc ECS; exposure: Public hoặc Nội bộ) → Deployment (môi trường production/staging, commit, image digest) → Asset (host hoặc ECS task, có `last_seen`).
Finding gắn với một deployment. Vòng đời finding:
- Mới → Đã nhận → Đã vá → Đã xác minh (chỉ xác minh khi **mọi instance** đã báo bản đã vá)
- Hai nhánh ngoại lệ: Không ảnh hưởng (bắt buộc ghi lý do), Chấp nhận rủi ro (bắt buộc lý do, người duyệt, **ngày hết hạn**; hết hạn thì tự mở lại)

Mức ưu tiên và SLA:

| Mức | Hạn xử lý |
|---|---|
| Critical / có trong CISA KEV, trên production | 48 giờ |
| High | 7 ngày |
| Medium / Low | 30 ngày |
| Thông báo sớm | Chỉ là "cảnh báo chuẩn bị" |

# Pattern tham khảo
Lấy cảm hứng từ cách các developer tool quen thuộc giải quyết từng vấn đề. Chỉ mượn pattern, không sao chép thương hiệu, màu sắc hay logo. Nếu tôi đính kèm ảnh chụp màn hình tham khảo, ưu tiên theo ảnh.

- **Layout chung (kiểu Linear / Vercel):**
  - Sidebar trái gọn: nhóm điều hướng, số đếm nhỏ cạnh mục (ví dụ "Findings 12").
  - Vùng nội dung có thanh tiêu đề gồm breadcrumb, nút hành động chính ở bên phải.
  - Command palette mở bằng ⌘K để tìm service, finding, CVE.
- **Inbox triage (kiểu Linear Inbox + GitHub Dependabot alerts):**
  - Mỗi dòng gồm icon mức độ, tiêu đề ngắn, chip service/team, hạn SLA dạng tương đối ("còn 18 giờ", "quá 2 ngày").
  - Thanh filter dạng chip phía trên, có thể thêm hoặc xóa từng điều kiện. Saved views nằm dạng tab.
  - Chọn nhiều dòng thì hiện thanh bulk action nổi ở dưới.
  - Phím tắt j/k để di chuyển, Enter để mở.
- **Gom nhóm (kiểu Sentry issues):**
  - Một dòng đại diện một lỗ hổng, kèm "ảnh hưởng 5 service". Mở ra thấy danh sách con.
- **Trang chi tiết (kiểu Sentry issue detail + GitHub security advisory):**
  - Header cố định chứa mức độ, trạng thái, SLA và nút hành động chính.
  - Nội dung chia khối rõ ràng: Vì sao / Ở đâu / Cách sửa.
  - Cột phải chứa metadata (team, môi trường, lần đầu phát hiện, nguồn).
  - Timeline hoạt động ở cuối.
- **Đổi trạng thái có điều kiện (kiểu GitHub "Dismiss alert"):**
  - Dropdown chọn lý do, ô ghi chú bắt buộc.
  - Riêng "Chấp nhận rủi ro" có date picker cho ngày hết hạn.
- **Dashboard việc cần làm (kiểu GitHub home / Vercel overview):**
  - Danh sách hành động sắp theo độ khẩn cấp đặt ở trên cùng.
  - Một hàng stat card nhỏ, mỗi card bấm được để mở danh sách đã lọc.
- **Ma trận trạng thái (kiểu status page / bảng CI matrix):**
  - Ô gọn, chỉ có chip trạng thái và version. Hover thì hiện chi tiết.
- **Dòng thời gian vòng đời (kiểu endoflife.date):**
  - Thanh ngang theo năm, mỗi nhánh một hàng: đoạn active support, đoạn security support, mốc EOL.
  - Có đường "hôm nay" và số service đang ở mỗi nhánh.
- **Onboarding chờ kết nối (kiểu Stripe CLI / Vercel "waiting for first deployment"):**
  - Các bước dạng checklist, khối code có nút Copy.
  - Trạng thái "Đang chờ báo cáo đầu tiên…" có hiệu ứng nhẹ, tự chuyển sang "Đã kết nối".
- **Quản lý token (kiểu Stripe / GitHub API keys):**
  - Token chỉ hiện đầy đủ một lần, kèm cảnh báo.
  - Danh sách token có lần dùng gần nhất, cùng nút xoay vòng và thu hồi (có xác nhận).
- **Sức khỏe hệ thống (kiểu status page):**
  - Mỗi nguồn dữ liệu một dòng có chấm trạng thái và "thành công gần nhất 12 phút trước".
  - Nguồn lỗi được đẩy lên đầu, có banner đỏ toàn trang.
- **Empty / error state:** câu ngắn giải thích lý do, kèm một nút hành động tiếp theo.

# Màn hình cần thiết kế
1. **Tổng quan** — trả lời câu hỏi "hôm nay cần làm gì":
   - Banner thông báo sớm, ví dụ: "PHP sẽ phát hành bản vá critical lúc 21:00 giờ VN hôm nay — 7 service đang chạy PHP".
   - Danh sách việc cần làm, sắp theo hạn SLA.
   - Các số liệu nhỏ: finding đang mở theo mức, số quá SLA, runtime EOL, asset mất liên lạc, nguồn dữ liệu lỗi. Mọi con số đều bấm được để mở danh sách đã lọc.
2. **Findings (inbox)**:
   - Mặc định gom theo lỗ hổng: 1 lỗ hổng × N service thành 1 dòng, mở ra được. Có nút chuyển sang dạng danh sách phẳng.
   - Filter: team, mức, trạng thái, loại quy tắc, ngôn ngữ, môi trường, quá SLA.
   - Saved views: "Team của tôi", "Quá SLA", "Critical production".
   - Bulk action. Phím tắt j/k để di chuyển.
3. **Chi tiết finding**:
   - Header: mức, trạng thái, đếm ngược SLA.
   - Khối **Vì sao**: version đang chạy, dải version bị ảnh hưởng, nguồn và link.
   - Khối **Ở đâu**: danh sách deployment và asset, kèm tiến độ "3/5 instance đã vá".
   - Khối **Cách sửa**: version đích và gợi ý lệnh, ví dụ `composer update guzzlehttp/guzzle`, hoặc nâng PHP lên 8.3.x.
   - Timeline sự kiện.
   - Các action, mỗi action có form và validation riêng.
   - **Phải dùng tốt trên mobile.**
4. **Runtimes**:
   - Ma trận: hàng là service, cột là PHP / Ruby / Node.js. Mỗi ô có version, chip trạng thái và `last_seen`.
   - Một view "vòng đời nhánh" cho từng ngôn ngữ: dòng thời gian các nhánh với ngày EOL và số service đang ở mỗi nhánh.
5. **Tin bảo mật**: feed gồm thông báo sớm và bản phát hành bảo mật. Mỗi tin có nguồn, thời gian, số service khớp và trạng thái.
6. **Services**: danh sách và trang chi tiết (team sở hữu, repo, deployment theo môi trường, runtime, số package, finding).
7. **Assets**:
   - Danh sách có độ "tươi" của dữ liệu.
   - Hàng chờ "Asset chưa có chủ" để gán vào service.
   - Quản lý token: tạo token và chỉ hiện một lần, xoay vòng, thu hồi.
8. **Thêm service (wizard)**: tạo service → chọn Server hoặc ECS → copy đoạn cài collector hoặc bước CI (đã kèm token) → màn hình chờ báo cáo đầu tiên (trạng thái live) → hoàn tất.
9. **Teams**: thành viên, webhook Google Chat (có nút "Gửi thử"), email leo thang.
10. **Sức khỏe hệ thống**:
    - Mỗi nguồn dữ liệu (php.net, nodejs.org, rubysec, OSV, các feed) có `last_success`, ngưỡng, lỗi gần nhất và nút "Chạy lại".
    - Phải nổi bật trạng thái "hệ thống đang im lặng vì lỗi".
11. **Mockup card Google Chat** (4 loại): finding critical mới, thông báo sớm, bản tổng hợp hằng ngày lúc 08:00, cảnh báo một nguồn dữ liệu bị lỗi. Mỗi card có nút mở thẳng trang chi tiết.

Mỗi màn hình cần thể hiện các trạng thái: có dữ liệu, rỗng, đang tải, lỗi, và **dữ liệu cũ** (ví dụ: "Báo cáo gần nhất 26 giờ trước").

# Dữ liệu mẫu
- 3 team: Commerce, Booking, Internal Tools.
- Khoảng 8 service, ví dụ:
  - shop-api (PHP 8.3, ECS, Public)
  - shop-web (Node.js 22)
  - booking-api (Ruby 3.3, Rails)
  - partner-portal (PHP 8.4)
  - realtime-gateway (Node.js 20, EOL)
  - legacy-cms (PHP 8.1, EOL)
  - hr-internal (PHP 8.2)
  - booking-worker (Ruby 3.2, EOL)
- Có một asset "Thiếu dữ liệu" và một asset chưa có chủ.
- Mã lỗ hổng mẫu phải có tiền tố `DEMO-` (ví dụ `DEMO-CVE-0001`, `DEMO-GHSA-0002`). **Không bịa mã CVE thật.** Riêng thông báo php.net ngày 20/10/2026 là sự kiện thật, được dùng nguyên văn.

# Nguyên tắc UX
- Mở bất kỳ màn hình nào ra là biết ngay việc cần làm tiếp theo.
- Mức nghiêm trọng và trạng thái luôn có **màu + icon + chữ**, không chỉ dùng màu.
- Không có ngõ cụt: từ con số mở được danh sách, từ danh sách mở được chi tiết, từ chi tiết làm được action.
- Giao diện tiếng Việt, câu ngắn. Giữ nguyên thuật ngữ kỹ thuật (CVE, SLA, commit, lockfile).
- Ưu tiên desktop 1280–1440px với mật độ thông tin vừa phải. Trang chi tiết finding và card Chat phải dùng tốt trên mobile 390px.
- Phong cách tĩnh, gọn, dạng developer tool. Chọn một design system trung tính trong thư viện. Có light mode, dark mode là tùy chọn. Độ tương phản đạt WCAG AA.

# Ràng buộc triển khai
Prototype sẽ được dựng lại bằng **Filament (Laravel)**, hoặc **React** nếu Filament không đáp ứng được. Vì vậy:
- Dùng component chuẩn bất cứ khi nào có thể: bảng, filter, form, tabs, badge, modal, timeline, stat card.
- Chỉ tự thiết kế component riêng khi nó giúp UX tốt hơn rõ rệt, và phải ghi lý do.

# Kết quả cần giao
1. Ghi chú ngắn cho từng màn hình: đã áp dụng pattern nào ở mục "Pattern tham khảo", và chỗ nào làm khác thì vì sao.
2. Prototype HTML click được, điều hướng bằng sidebar, có đủ các màn hình và trạng thái ở trên.
3. 4 mockup card Google Chat.
4. **Bảng component**:

   | Component | Dùng ở màn hình | Chuẩn hay tự làm | Độ phức tạp tương tác | Ghi chú triển khai |
   |---|---|---|---|---|

   Đánh dấu rõ các component có thể khó làm bằng Filament (ma trận runtime, dòng thời gian nhánh, inbox gom nhóm có phím tắt, tiến độ xác minh, wizard chờ trạng thái live).
5. Danh sách câu hỏi còn mở về UX.

# Thứ tự làm
- Vòng 1: Tổng quan, Findings inbox, Chi tiết finding, card Google Chat.
- Vòng 2: Runtimes, Tin bảo mật, Services.
- Vòng 3: Assets, wizard thêm service, Teams, Sức khỏe hệ thống.

Dừng sau mỗi vòng để tôi góp ý.
````
