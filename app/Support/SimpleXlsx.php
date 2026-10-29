<?php

namespace App\Support;

use RuntimeException;

/**
 * Just enough of the .xlsx format to hand out a formatted sheet: no package,
 * and no zip extension either (see zip()).
 *
 * One sheet of text and number cells, a fixed set of styles (STYLE_*), merged
 * ranges, column widths, row heights and frozen headings.
 */
final class SimpleXlsx
{
    public const STYLE_TITLE = 1;

    public const STYLE_SUBTITLE = 2;

    public const STYLE_NOTE = 3;

    public const STYLE_GROUP = 4;

    public const STYLE_HEADER = 5;

    public const STYLE_CELL = 6;

    public const STYLE_TEXT_CELL = 7;

    public const STYLE_TOTAL = 8;

    /**
     * @param  array<int, array<int, array{0: string|int, 1: int}|string|int|null>>  $rows  by row number (1-based), cells by column (0-based): a value, or [value, style]; integers are written as numbers
     * @param  array<int, string>  $merges  ranges such as "A1:T1"
     * @param  array<int, float>  $widths  by column (0-based)
     * @param  array<int, float>  $heights  by row number, in points
     */
    public static function write(string $sheetName, array $rows, array $merges = [], array $widths = [], int $freezeBelowRow = 0, array $heights = []): string
    {
        return self::zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="'.self::xml(mb_substr($sheetName, 0, 31)).'" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',
            'xl/styles.xml' => self::styles(),
            'xl/worksheets/sheet1.xml' => self::sheet($rows, $merges, $widths, $freezeBelowRow, $heights),
        ]);
    }

    public static function columnLetter(int $index): string
    {
        $letters = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26).$letters;
        }

        return $letters;
    }

    /**
     * @param  array<int, array<int, array{0: string|int, 1: int}|string|int|null>>  $rows
     * @param  array<int, string>  $merges
     * @param  array<int, float>  $widths
     * @param  array<int, float>  $heights
     */
    private static function sheet(array $rows, array $merges, array $widths, int $freezeBelowRow, array $heights): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';

        $xml .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0">';
        if ($freezeBelowRow > 0) {
            $xml .= '<pane ySplit="'.$freezeBelowRow.'" topLeftCell="A'.($freezeBelowRow + 1).'" activePane="bottomLeft" state="frozen"/>';
        }
        $xml .= '</sheetView></sheetViews>';

        if ($widths !== []) {
            $xml .= '<cols>';
            foreach ($widths as $col => $width) {
                $xml .= '<col min="'.($col + 1).'" max="'.($col + 1).'" width="'.$width.'" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        ksort($rows);
        foreach ($rows as $number => $cells) {
            $xml .= isset($heights[$number])
                ? '<row r="'.$number.'" ht="'.$heights[$number].'" customHeight="1">'
                : '<row r="'.$number.'">';
            ksort($cells);
            foreach ($cells as $col => $cell) {
                [$value, $style] = is_array($cell) ? $cell : [$cell ?? '', 0];
                $ref = self::columnLetter($col).$number;
                $xml .= match (true) {
                    is_int($value) => '<c r="'.$ref.'" s="'.$style.'"><v>'.$value.'</v></c>',
                    $value === '' => '<c r="'.$ref.'" s="'.$style.'"/>',
                    default => '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.self::xml((string) $value).'</t></is></c>',
                };
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';

        if ($merges !== []) {
            $xml .= '<mergeCells count="'.count($merges).'">';
            foreach ($merges as $range) {
                $xml .= '<mergeCell ref="'.$range.'"/>';
            }
            $xml .= '</mergeCells>';
        }

        return $xml.'<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
            .'<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
    }

    /** The fixed styles, in STYLE_* order. */
    private static function styles(): string
    {
        $thin = '<left style="thin"><color rgb="FF9CA3AF"/></left><right style="thin"><color rgb="FF9CA3AF"/></right>'
            .'<top style="thin"><color rgb="FF9CA3AF"/></top><bottom style="thin"><color rgb="FF9CA3AF"/></bottom><diagonal/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="7">'
            .'<font><sz val="10"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="14"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'<font><i/><sz val="9"/><color rgb="FF6B7280"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="9"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="10"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="4">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF3F9C35"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFEEF5EC"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border>'.$thin.'</border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="9">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            .'<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="top" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="4" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="5" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            .'<xf numFmtId="49" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left"/></xf>'
            .'<xf numFmtId="0" fontId="6" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            .'</cellXfs>'
            .'</styleSheet>';
    }

    /**
     * The parts as a zip archive, written here rather than with ext-zip.
     *
     * The extension is not on every PHP build, and without it the export
     * failed outright (2026-10-26). Each part is deflated with zlib; a
     * workbook is a handful of small parts, so there is no zip64 and no data
     * descriptor.
     *
     * @param  array<string, string>  $parts  contents by path inside the archive
     */
    private static function zip(array $parts): string
    {
        $now = now();
        $time = ($now->hour << 11) | ($now->minute << 5) | intdiv($now->second, 2);
        $date = (($now->year - 1980) << 9) | ($now->month << 5) | $now->day;

        $body = '';
        $directory = '';

        foreach ($parts as $name => $contents) {
            $deflated = gzdeflate($contents);
            if ($deflated === false) {
                throw new RuntimeException('The workbook could not be built.');
            }

            // Version 2.0, no flags, deflate, time, date, CRC, both sizes.
            $fields = pack('vvvvvVVV', 20, 0, 8, $time, $date, crc32($contents), strlen($deflated), strlen($contents));
            $offset = strlen($body);

            $body .= pack('V', 0x04034B50).$fields.pack('vv', strlen($name), 0).$name.$deflated;
            // Made by 2.0, then no extra, comment, disk, or attributes.
            $directory .= pack('Vv', 0x02014B50, 20).$fields
                .pack('vvvvvVV', strlen($name), 0, 0, 0, 0, 0, $offset).$name;
        }

        return $body.$directory
            .pack('VvvvvVVv', 0x06054B50, 0, 0, count($parts), count($parts), strlen($directory), strlen($body), 0);
    }

    private static function xml(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
