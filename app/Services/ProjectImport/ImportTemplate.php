<?php

namespace App\Services\ProjectImport;

use App\Enums\Exposure;
use App\Enums\OperatingSystem;
use App\Enums\ServiceKind;
use App\Enums\Technology;
use App\Models\Team;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The workbook teams fill in, in the locale of the admin who downloads it: the "Services" sheet with a dropdown on
 * every column that has a fixed list, a guide sheet, and the hidden "Lists" sheet the dropdowns read from. Versions are
 * typed: a dropdown that follows the technology on its left needs INDIRECT, which Google Sheets does not run.
 */
class ImportTemplate
{
    /**
     * Names of the ranges on the "Lists" sheet.
     */
    private const string TEAMS = 'psm_teams';

    private const string KINDS = 'psm_kinds';

    private const string EXPOSURES = 'psm_exposures';

    private const string OPERATING_SYSTEMS = 'psm_operating_systems';

    private const string TECHNOLOGIES = 'psm_technologies';

    public function build(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $data = $spreadsheet->getActiveSheet()->setTitle(ImportSheet::DATA_SHEET);
        $guide = $spreadsheet->createSheet()->setTitle(__('Hướng dẫn'));
        $lists = $spreadsheet->createSheet()->setTitle(ImportSheet::LISTS_SHEET);

        $teamNames = Team::orderBy('name')->pluck('name')->all();

        $this->writeLists($spreadsheet, $lists, $teamNames);
        $this->writeDataSheet($data);
        $this->writeGuide($guide, $teamNames[0] ?? 'Team A');

        $lists->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * One list per column of "Lists", each a named range, plus the template marker.
     *
     * @param  list<string>  $teamNames
     */
    private function writeLists(Spreadsheet $spreadsheet, Worksheet $lists, array $teamNames): void
    {
        $columns = [
            'A' => [self::TEAMS, $teamNames],
            'B' => [self::KINDS, array_map(fn (ServiceKind $kind): string => $kind->getLabel(), ServiceKind::cases())],
            'C' => [self::EXPOSURES, array_map(fn (Exposure $exposure): string => $exposure->getLabel(), Exposure::cases())],
            'D' => [self::OPERATING_SYSTEMS, array_map(fn (OperatingSystem $system): string => $system->getLabel(), OperatingSystem::cases())],
            'E' => [self::TECHNOLOGIES, array_map(fn (Technology $technology): string => $technology->getLabel(), Technology::cases())],
        ];

        foreach ($columns as $column => [$name, $values]) {
            $lists->setCellValue("{$column}1", $name);

            foreach (array_values($values) as $offset => $value) {
                $lists->setCellValueExplicit($column.($offset + 2), $value, 'str');
            }

            $spreadsheet->addNamedRange(new NamedRange($name, $lists, sprintf('$%s$2:$%s$%d', $column, $column, max(count($values), 1) + 1)));
        }

        $lists->setCellValue(ImportSheet::MARKER_CELL, ImportSheet::MARKER);
    }

    private function writeDataSheet(Worksheet $data): void
    {
        $lastRow = ImportSheet::lastRow();
        $lastColumn = ImportSheet::LAST_COLUMN;

        foreach (ImportSheet::columns() as $column => $definition) {
            $data->setCellValue($column.ImportSheet::HEADER_ROW, $definition['label'].($definition['required'] ? ' *' : ''));
            $data->getColumnDimension($column)->setWidth(match ($column) {
                ImportSheet::PROJECT, ImportSheet::REPOSITORY => 32,
                default => 18,
            });
        }

        $data->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $data->getStyle("A1:{$lastColumn}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEF0F3');
        $data->freezePane('A'.ImportSheet::FIRST_ROW);

        // Text cells, so a version such as "8.10" is not turned into the number 8.1.
        $data->getStyle('A'.ImportSheet::FIRST_ROW.":{$lastColumn}{$lastRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        $this->addList($data, ImportSheet::TEAM, self::TEAMS);
        $this->addList($data, ImportSheet::KIND, self::KINDS);
        $this->addList($data, ImportSheet::EXPOSURE, self::EXPOSURES);
        $this->addList($data, ImportSheet::OS, self::OPERATING_SYSTEMS);

        foreach (ImportSheet::TECHNOLOGY_SLOTS as [$technologyColumn]) {
            $this->addList($data, $technologyColumn, self::TECHNOLOGIES);
        }
    }

    /**
     * A dropdown on the whole column that refuses other values.
     */
    private function addList(Worksheet $data, string $column, string $source): void
    {
        $validation = (new DataValidation)
            ->setType(DataValidation::TYPE_LIST)
            ->setErrorStyle(DataValidation::STYLE_STOP)
            ->setAllowBlank(true)
            ->setShowDropDown(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle(__('Giá trị không hợp lệ'))
            ->setError(__('Chọn một giá trị trong danh sách.'))
            ->setFormula1($source);

        $data->setDataValidation(sprintf('%s%d:%s%d', $column, ImportSheet::FIRST_ROW, $column, ImportSheet::lastRow()), $validation);
    }

    private function writeGuide(Worksheet $guide, string $exampleTeam): void
    {
        $guide->setCellValue('A1', __('Import project và service'));
        $guide->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $notes = [
            __('Điền ở sheet ":sheet", mỗi dòng một service. Tối đa :max dòng.', ['sheet' => ImportSheet::DATA_SHEET, 'max' => ImportSheet::MAX_ROWS]),
            __('Tên service lấy từ tên repository. Slug của project và service do hệ thống tự tạo.'),
            __('Import chỉ tạo mới, không sửa project hay service đã có.'),
            __('Không đổi thứ tự cột, không đổi tên hay xóa các sheet.'),
        ];

        foreach ($notes as $offset => $note) {
            $guide->setCellValue('A'.(3 + $offset), '• '.$note);
        }

        $row = 4 + count($notes);
        $guide->fromArray([__('Cột'), __('Bắt buộc'), __('Cách điền')], null, "A{$row}");
        $guide->getStyle("A{$row}:C{$row}")->getFont()->setBold(true);

        foreach (ImportSheet::columns() as $definition) {
            $row++;
            $guide->fromArray([$definition['label'], $definition['required'] ? '✔' : '', $definition['help']], null, "A{$row}");
        }

        $guide->getStyle("C1:C{$row}")->getAlignment()->setWrapText(true);

        $row += 2;
        $guide->setCellValue("A{$row}", __('Ví dụ'));
        $guide->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        $guide->fromArray(array_map(fn (array $definition): string => $definition['label'], array_values(ImportSheet::columns())), null, "A{$row}");
        $guide->getStyle("A{$row}:".ImportSheet::LAST_COLUMN.$row)->getFont()->setBold(true);
        $guide->fromArray([
            [$exampleTeam, __('Cổng thanh toán'), 'https://'.config('psm.github.host').'/acme/payment-api', 'main', '', ServiceKind::Server->getLabel(), Exposure::Public->getLabel(), OperatingSystem::Linux->getLabel(), Technology::Php->getLabel(), '8.3.12', '', '', '', ''],
            [$exampleTeam, __('Cổng thanh toán'), 'acme/payment-web', '', 'apps/admin', ServiceKind::Ecs->getLabel(), Exposure::Internal->getLabel(), OperatingSystem::Linux->getLabel(), Technology::Nodejs->getLabel(), '20.11.1', '', '', '', ''],
        ], null, 'A'.($row + 1), true);

        $guide->getColumnDimension('A')->setWidth(26);
        $guide->getColumnDimension('B')->setWidth(18);
        $guide->getColumnDimension('C')->setWidth(90);
    }
}
