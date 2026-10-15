<?php

namespace Sellinnate\SalesCrm\Services;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of rows as CSV (always) or XLSX (with openspout/openspout). Cells that a spreadsheet would
 * read as formulas (=, +, -, @, tab, CR) are neutralised with a leading apostrophe.
 */
class SpreadsheetExport
{
    /**
     * @return list<string>
     */
    public static function formats(): array
    {
        return class_exists(XlsxWriter::class) ? ['csv', 'xlsx'] : ['csv'];
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function download(string $name, string $format, array $headers, iterable $rows): StreamedResponse
    {
        $format = in_array($format, self::formats(), true) ? $format : 'csv';
        $filename = $name.'.'.$format;

        return new StreamedResponse(function () use ($format, $headers, $rows): void {
            $format === 'xlsx' ? self::xlsx($headers, $rows) : self::csv($headers, $rows);
        }, 200, [
            'Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public static function neutralize(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';

        // Numbers and phone numbers (+39 333…, -12.5) stay as they are: no formula can hide in them.
        if ($text === '' || preg_match('/^[+-]?[\d\s().\/-]+$/', $text) === 1) {
            return $text;
        }

        return in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }

    /**
     * A cell exported by neutralize() read back (the file of the rejected rows imported again).
     */
    public static function restore(string $text): string
    {
        return strlen($text) > 1 && $text[0] === "'" && in_array($text[1], ['=', '+', '-', '@', "\t", "\r"], true) ? substr($text, 1) : $text;
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    private static function csv(array $headers, iterable $rows): void
    {
        $out = fopen('php://output', 'wb');

        if ($out === false) {
            return;
        }

        fwrite($out, "\u{FEFF}");
        fputcsv($out, array_map(self::neutralize(...), $headers), ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($out, array_map(self::neutralize(...), array_values($row)), ',', '"', '');
        }

        fclose($out);
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    private static function xlsx(array $headers, iterable $rows): void
    {
        $writer = new XlsxWriter;
        $writer->openToFile('php://output');
        $writer->addRow(Row::fromValues(array_map(self::neutralize(...), $headers)));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map(self::neutralize(...), array_values($row))));
        }

        $writer->close();
    }
}
