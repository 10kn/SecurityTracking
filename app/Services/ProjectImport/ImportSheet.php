<?php

namespace App\Services\ProjectImport;

/**
 * The layout of the import workbook, shared by the template (ImportTemplate) and the reader (ImportSheetReader): one
 * service per row of the "Services" sheet, named after its repository, the columns found by position so a template in
 * either language reads the same, and a hidden "Lists" sheet with the dropdown sources and the marker of the template
 * version.
 */
final class ImportSheet
{
    /**
     * Bumped when the columns change, so a template filled in before is refused instead of read wrongly.
     */
    public const string MARKER = 'psm-project-import:2';

    public const string DATA_SHEET = 'Services';

    public const string LISTS_SHEET = 'Lists';

    public const string MARKER_CELL = 'K1';

    public const int HEADER_ROW = 1;

    public const int FIRST_ROW = 2;

    public const int MAX_ROWS = 500;

    public const string TEAM = 'A';

    public const string PROJECT = 'B';

    public const string REPOSITORY = 'C';

    public const string BRANCH = 'D';

    public const string REPOSITORY_PATH = 'E';

    public const string KIND = 'F';

    public const string EXPOSURE = 'G';

    public const string OS = 'H';

    /**
     * The technology and version columns of each of the three slots, e.g. ['I', 'J'] for "Công nghệ 1" and "Version 1".
     *
     * @var list<array{0: string, 1: string}>
     */
    public const array TECHNOLOGY_SLOTS = [['I', 'J'], ['K', 'L'], ['M', 'N']];

    public const string LAST_COLUMN = 'N';

    /**
     * The last row the dropdowns cover.
     */
    public static function lastRow(): int
    {
        return self::FIRST_ROW + self::MAX_ROWS - 1;
    }

    /**
     * Every column in order with its header in the current locale, whether it is required and how to fill it.
     *
     * @return array<string, array{label: string, required: bool, help: string}>
     */
    public static function columns(): array
    {
        $columns = [
            self::TEAM => ['label' => __('Team'), 'required' => true, 'help' => __('Chọn trong danh sách.')],
            self::PROJECT => ['label' => __('Tên project'), 'required' => true, 'help' => __('Các dòng cùng team và cùng tên project được gom vào một project. Team đã có project trùng tên thì service được thêm vào project đó.')],
            self::REPOSITORY => ['label' => __('Repository'), 'required' => true, 'help' => __('Link repository trên GitHub, vd. :example. Tên service là tên repository, và hệ thống tự đọc lockfile từ đó. Project đã có service cùng repository hoặc cùng tên thì dòng được bỏ qua.', ['example' => 'https://'.config('psm.github.host').'/acme/shop'])],
            self::BRANCH => ['label' => __('Branch'), 'required' => false, 'help' => __('Để trống để dùng branch mặc định. Khi lưu, hệ thống kiểm tra branch có trên GitHub không.')],
            self::REPOSITORY_PATH => ['label' => __('Thư mục trong repository'), 'required' => false, 'help' => __('Dùng cho monorepo: mỗi thư mục một dòng, tên service kèm thư mục, vd. :example. Để trống hoặc "/" nếu service nằm ở gốc repository.', ['example' => 'shop (apps/api)'])],
            self::KIND => ['label' => __('Loại'), 'required' => true, 'help' => __('Chọn trong danh sách.')],
            self::EXPOSURE => ['label' => __('Phạm vi truy cập'), 'required' => true, 'help' => __('Chọn trong danh sách.')],
            self::OS => ['label' => __('Hệ điều hành'), 'required' => false, 'help' => __('Tin bảo mật chỉ ảnh hưởng hệ điều hành khác không cảnh báo service này. Không rõ thì coi như có thể bị ảnh hưởng.')],
        ];

        foreach (self::TECHNOLOGY_SLOTS as $index => [$technologyColumn, $versionColumn]) {
            $columns[$technologyColumn] = ['label' => __('Công nghệ :number', ['number' => $index + 1]), 'required' => false, 'help' => __('Chọn trong danh sách. Mỗi công nghệ chỉ chọn một lần.')];
            $columns[$versionColumn] = ['label' => __('Version :number', ['number' => $index + 1]), 'required' => false, 'help' => __('Gõ version đầy đủ của công nghệ bên trái, vd. 8.3.12 hoặc bản build của distro như 8.1.2-1ubuntu2.14.')];
        }

        return $columns;
    }
}
