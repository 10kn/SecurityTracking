<?php

namespace App\Services\ProjectImport;

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Throwable;

/**
 * Reads the rows of the "Services" sheet of a filled-in template, skipping blank ones. A workbook that is not an import
 * template of this version, or that has more rows than an import takes, is refused as a whole.
 */
class ImportSheetReader
{
    /**
     * Rows below this one are not read; anything past MAX_ROWS is refused anyway.
     */
    private const int SCAN_LIMIT = 5000;

    /**
     * @return list<array{row: int, cells: array<string, string>}> Trimmed cell texts keyed by column letter, e.g. "A".
     *
     * @throws UnreadableImportException
     */
    public function read(string $path): array
    {
        try {
            $reader = new Xlsx;
            $reader->setReadDataOnly(true);
            $reader->setLoadSheetsOnly([ImportSheet::DATA_SHEET, ImportSheet::LISTS_SHEET]);
            $spreadsheet = $reader->load($path);
        } catch (Throwable $exception) {
            report($exception);

            throw new UnreadableImportException(__('Không đọc được file. Lưu file ở dạng Excel (.xlsx) rồi tải lên lại.'), previous: $exception);
        }

        $lists = $spreadsheet->getSheetByName(ImportSheet::LISTS_SHEET);
        $data = $spreadsheet->getSheetByName(ImportSheet::DATA_SHEET);

        if ($lists === null || $data === null || trim((string) $lists->getCell(ImportSheet::MARKER_CELL)->getValue()) !== ImportSheet::MARKER) {
            throw new UnreadableImportException(__('File không phải template import của hệ thống, hoặc là template cũ. Tải template mới rồi điền lại.'));
        }

        $lastRow = min($data->getHighestDataRow(), self::SCAN_LIMIT);
        $rows = [];

        if ($lastRow < ImportSheet::FIRST_ROW) {
            return [];
        }

        $range = 'A'.ImportSheet::FIRST_ROW.':'.ImportSheet::LAST_COLUMN.$lastRow;

        foreach ($data->rangeToArray($range, null, false, true, true) as $rowNumber => $cells) {
            $cells = array_map(fn (mixed $value): string => trim(preg_replace('/\s+/u', ' ', (string) $value) ?? ''), $cells);

            if (implode('', $cells) === '') {
                continue;
            }

            $rows[] = ['row' => (int) $rowNumber, 'cells' => $cells];
        }

        if (count($rows) > ImportSheet::MAX_ROWS || $data->getHighestDataRow() > self::SCAN_LIMIT) {
            throw new UnreadableImportException(__('File có quá :max dòng. Chia thành nhiều file rồi import từng file.', ['max' => ImportSheet::MAX_ROWS]));
        }

        return $rows;
    }
}
