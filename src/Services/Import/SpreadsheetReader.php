<?php

namespace Sellinnate\SalesCrm\Services\Import;

use Generator;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;
use Sellinnate\SalesCrm\Exceptions\ImportException;

/**
 * Reads the rows of a CSV or XLSX file stored on a disk. CSV needs nothing (delimiter, BOM and
 * Windows-1252 encoding detected); XLSX needs openspout/openspout and may have more sheets, read by
 * name. Rows are numbered as in the sheet (1 = the header).
 */
class SpreadsheetReader
{
    private ?string $local = null;

    private ?string $temporary = null;

    public function __construct(private string $disk, private string $path, private ?string $sheet = null) {}

    public static function supportsXlsx(): bool
    {
        return class_exists(XlsxReader::class);
    }

    /**
     * The extensions an import accepts with the installed libraries.
     *
     * @return list<string>
     */
    public static function extensions(): array
    {
        return self::supportsXlsx() ? ['csv', 'txt', 'xlsx'] : ['csv', 'txt'];
    }

    public function isXlsx(): bool
    {
        return strtolower(pathinfo($this->path, PATHINFO_EXTENSION)) === 'xlsx';
    }

    /**
     * The names of the sheets of an XLSX file (a CSV has none).
     *
     * @return list<string>
     */
    public function sheets(): array
    {
        if (! $this->isXlsx()) {
            return [];
        }

        $reader = $this->xlsx();
        $names = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $names[] = $sheet->getName();
            }
        } finally {
            $reader->close();
        }

        return $names;
    }

    /**
     * The header and the first rows after it.
     *
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public function preview(int $rows = 10): array
    {
        $headers = [];
        $preview = [];

        foreach ($this->rows() as $number => $cells) {
            if ($number === 1) {
                $headers = $cells;

                continue;
            }

            $preview[] = $cells;

            if (count($preview) >= $rows) {
                break;
            }
        }

        return ['headers' => $headers, 'rows' => $preview];
    }

    /**
     * Every row, by its number in the sheet (1 = the header). Empty rows are skipped.
     *
     * @return Generator<int, list<string>>
     */
    public function rows(): Generator
    {
        return $this->isXlsx() ? $this->xlsxRows() : $this->csvRows();
    }

    /**
     * @return Generator<int, list<string>>
     */
    private function csvRows(): Generator
    {
        $handle = fopen($this->localPath(), 'rb');

        if ($handle === false) {
            throw new ImportException((string) __('sales-crm::validation.import_unreadable'));
        }

        try {
            $first = (string) fgets($handle);
            $delimiter = self::delimiter($first);
            rewind($handle);
            $number = 0;

            while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $number++;
                $cells = array_map(fn (mixed $cell): string => self::utf8(trim((string) $cell)), $cells);

                if ($number === 1) {
                    $cells[0] = (string) preg_replace('/^\x{FEFF}/u', '', $cells[0]);
                }

                if (implode('', $cells) === '') {
                    continue;
                }

                yield $number => $cells;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return Generator<int, list<string>>
     */
    private function xlsxRows(): Generator
    {
        $reader = $this->xlsx();

        try {
            foreach ($reader->getSheetIterator() as $index => $sheet) {
                if ($this->sheet !== null ? $sheet->getName() !== $this->sheet : $index !== 1) {
                    continue;
                }

                $number = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $number++;
                    $cells = [];

                    foreach ($row->toArray() as $value) {
                        $cells[] = trim(match (true) {
                            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                            is_float($value) && floor($value) === $value && abs($value) < 1e15 => (string) (int) $value,
                            is_bool($value) => $value ? '1' : '0',
                            is_scalar($value) => (string) $value,
                            default => '',
                        });
                    }

                    if (implode('', $cells) === '') {
                        continue;
                    }

                    yield $number => $cells;
                }

                return;
            }
        } finally {
            $reader->close();
        }
    }

    private function xlsx(): XlsxReader
    {
        if (! self::supportsXlsx()) {
            throw new ImportException((string) __('sales-crm::validation.import_xlsx_unsupported'));
        }

        $reader = new XlsxReader;

        try {
            $reader->open($this->localPath());
        } catch (\Throwable) {
            throw new ImportException((string) __('sales-crm::validation.import_unreadable'));
        }

        return $reader;
    }

    /**
     * A local copy of the file (disks may be remote).
     */
    private function localPath(): string
    {
        if ($this->local !== null) {
            return $this->local;
        }

        $storage = Storage::disk($this->disk);

        try {
            $path = $storage->path($this->path);
        } catch (RuntimeException) {
            $path = '';
        }

        if ($path !== '' && is_file($path)) {
            return $this->local = $path;
        }

        // A remote disk: a temporary local copy, removed with the reader.
        $stream = $storage->readStream($this->path);
        $local = sys_get_temp_dir().'/crm-import-'.bin2hex(random_bytes(8)).'.'.pathinfo($this->path, PATHINFO_EXTENSION);

        if ($stream === null || file_put_contents($local, $stream) === false) {
            throw new ImportException((string) __('sales-crm::validation.import_unreadable'));
        }

        $this->temporary = $local;

        return $this->local = $local;
    }

    public function __destruct()
    {
        if ($this->temporary !== null && is_file($this->temporary)) {
            @unlink($this->temporary);
        }
    }

    private static function delimiter(string $line): string
    {
        $counts = ['semicolon' => substr_count($line, ';'), 'comma' => substr_count($line, ','), 'tab' => substr_count($line, "\t")];
        arsort($counts);

        return match (array_key_first($counts)) {
            'semicolon' => $counts['semicolon'] > 0 ? ';' : ',',
            'tab' => $counts['tab'] > 0 ? "\t" : ',',
            default => ',',
        };
    }

    private static function utf8(string $text): string
    {
        if ($text === '' || mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        return mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }
}
