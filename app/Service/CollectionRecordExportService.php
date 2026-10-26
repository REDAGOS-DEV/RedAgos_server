<?php

namespace App\Service;

use App\Enums\DonorType;
use App\Models\User;
use App\Support\SimpleXlsx;
use Carbon\CarbonImmutable;

/**
 * The centre's collection record (SNBC record) for a period, as an Excel
 * workbook laid out like the sheet the centre fills in by hand: the title
 * block, then SEX, AGE BRACKET, TYPE OF DONOR and DONOR STATUS over one row a
 * month and the total. Under TYPE OF DONOR, WALK-IN is everyone who came to
 * the centre, with its TS and NTS donors beneath it; MOBILE DRIVE stands apart.
 *
 * The figures are the Collection report's donor profile
 * (CollectionReportService), so the download and the screen always agree.
 */
class CollectionRecordExportService
{
    /**
     * Each column, left to right: its group, its sub-group (null for none),
     * its heading, and where its count lives in the profile. WALK-IN's total
     * is every donor who came to the centre: voluntary, TS and NTS together.
     */
    private const COLUMNS = [
        ['SEX', null, 'FEMALE', 'sex', 'female'],
        ['SEX', null, 'MALE', 'sex', 'male'],
        ['AGE BRACKET', null, '16-17', 'age', '16-17'],
        ['AGE BRACKET', null, '18-24', 'age', '18-24'],
        ['AGE BRACKET', null, '25-44', 'age', '25-44'],
        ['AGE BRACKET', null, '45-64', 'age', '45-64'],
        ['AGE BRACKET', null, '65+', 'age', '65+'],
        ['TYPE OF DONOR', 'WALK-IN', 'TOTAL', 'kind', 'walk_in_total'],
        ['TYPE OF DONOR', 'WALK-IN', 'TS', 'kind', 'ts'],
        ['TYPE OF DONOR', 'WALK-IN', 'NTS', 'kind', 'nts'],
        ['TYPE OF DONOR', null, 'MOBILE DRIVE', 'kind', 'drive'],
        ['DONOR STATUS', null, 'FIRST TIME', 'type', DonorType::FirstTime->value],
        ['DONOR STATUS', null, 'NEW TO CENTRE', 'type', DonorType::NewToCentre->value],
        ['DONOR STATUS', null, 'REPEAT', 'type', DonorType::RepeatRetained->value],
        ['DONOR STATUS', null, 'LAPSED', 'type', DonorType::Lapsed->value],
    ];

    public function __construct(
        private readonly CollectionReportService $collectionReportService
    ) {}

    /**
     * @return array{filename: string, bytes: string}
     */
    public function export(User $staff, string $fromDate, string $toDate): array
    {
        $report = $this->collectionReportService->report($staff, $fromDate, $toDate);
        $profile = $report['donor_profile'];
        $staff->loadMissing('facility');

        $from = CarbonImmutable::parse($report['period']['from']);
        $to = CarbonImmutable::parse($report['period']['to']);
        $last = count(self::COLUMNS) + 1;
        $lastLetter = SimpleXlsx::columnLetter($last);

        $rows = [
            1 => [0 => ['Department of Health', SimpleXlsx::STYLE_TITLE]],
            2 => [0 => [(string) ($staff->facility?->name ?? 'Blood centre'), SimpleXlsx::STYLE_SUBTITLE]],
            3 => [0 => ['COLLECTION RECORD (SNBC RECORD)', SimpleXlsx::STYLE_TITLE]],
            4 => [0 => [strtoupper($this->periodLabel($from, $to)), SimpleXlsx::STYLE_SUBTITLE]],
        ];
        $merges = ["A1:{$lastLetter}1", "A2:{$lastLetter}2", "A3:{$lastLetter}3", "A4:{$lastLetter}4", 'A6:A8', "{$lastLetter}6:{$lastLetter}8"];

        // Three rows of headings: the group on row 6, then either a heading
        // over rows 7 and 8, or a sub-group on 7 with its headings on 8.
        $rows[6][0] = ['MONTH', SimpleXlsx::STYLE_GROUP];
        $rows[7][0] = $rows[8][0] = ['', SimpleXlsx::STYLE_GROUP];
        $groupStart = $subStart = 1;
        foreach (self::COLUMNS as $i => [$group, $sub, $heading]) {
            $col = $i + 1;
            $letter = SimpleXlsx::columnLetter($col);
            $rows[6][$col] = [$group, SimpleXlsx::STYLE_GROUP];

            if ($sub === null) {
                $rows[7][$col] = [$heading, SimpleXlsx::STYLE_HEADER];
                $rows[8][$col] = ['', SimpleXlsx::STYLE_HEADER];
                $merges[] = "{$letter}7:{$letter}8";
            } else {
                $rows[7][$col] = [$sub, SimpleXlsx::STYLE_HEADER];
                $rows[8][$col] = [$heading, SimpleXlsx::STYLE_HEADER];
                if ((self::COLUMNS[$i + 1][1] ?? null) !== $sub) {
                    if ($col > $subStart) {
                        $merges[] = SimpleXlsx::columnLetter($subStart).'7:'.$letter.'7';
                    }
                }
                if ((self::COLUMNS[$i - 1][1] ?? null) !== $sub) {
                    $subStart = $col;
                }
            }

            if ((self::COLUMNS[$i + 1][0] ?? null) !== $group) {
                if ($col > $groupStart) {
                    $merges[] = SimpleXlsx::columnLetter($groupStart).'6:'.$letter.'6';
                }
                $groupStart = $col + 1;
            }
        }
        $rows[6][$last] = ['TOTAL', SimpleXlsx::STYLE_GROUP];
        $rows[7][$last] = $rows[8][$last] = ['', SimpleXlsx::STYLE_GROUP];

        // A row a month, then the total.
        $r = 9;
        foreach ($profile['months'] as $month) {
            $rows[$r++] = $this->row(CarbonImmutable::parse($month['month'].'-01')->format('F Y'), $month, SimpleXlsx::STYLE_TEXT_CELL, SimpleXlsx::STYLE_CELL);
        }
        if ($profile['months'] === []) {
            $rows[$r] = [0 => ['No donations collected in this period.', SimpleXlsx::STYLE_TEXT_CELL]];
            $merges[] = "A{$r}:{$lastLetter}{$r}";
            $r++;
        }
        $rows[$r++] = $this->row('TOTAL', $profile['total'], SimpleXlsx::STYLE_TOTAL, SimpleXlsx::STYLE_TOTAL);

        // What the columns mean, and what they leave out.
        $notes = [];
        $sexMissing = $profile['total']['sex']['not_stated'] ?? 0;
        $ageMissing = $profile['total']['age']['not_stated'] ?? 0;
        if ($sexMissing || $ageMissing) {
            $notes[] = "Not counted under SEX or AGE BRACKET: {$sexMissing} with no sex recorded, {$ageMissing} with no birth date.";
        }
        $notes[] = 'TYPE OF DONOR as picked at the counter: WALK-IN counts everyone who came to the centre, TS (for a patient of the same blood type) and NTS (for a patient of another) among them. MOBILE DRIVE counts the donors at drives.';
        $notes[] = 'DONOR STATUS: NEW TO CENTRE donated before, elsewhere; LAPSED donated here before, over '.intdiv($profile['lapsed_after_days'], 30).' months earlier.';
        $notes[] = 'Generated by RedAgos on '.CarbonImmutable::now((string) config('blood_center.timezone', 'Asia/Manila'))->format('j F Y, g:i A').'.';

        $r++;
        foreach ($notes as $note) {
            $rows[$r] = [0 => [$note, SimpleXlsx::STYLE_NOTE]];
            $merges[] = "A{$r}:{$lastLetter}{$r}";
            $r++;
        }

        $widths = array_fill(0, $last + 1, 9.5);
        $widths[0] = 16;
        $widths[$last] = 10;

        return [
            'filename' => 'redagos-snbc-record-'.$from->format('Y-m-d').'-to-'.$to->format('Y-m-d').'.xlsx',
            'bytes' => SimpleXlsx::write('SNBC record', $rows, $merges, $widths, 8, [7 => 16, 8 => 16]),
        ];
    }

    /**
     * @param  array<string, mixed>  $counts
     * @return array<int, array{0: string|int, 1: int}>
     */
    private function row(string $label, array $counts, int $labelStyle, int $cellStyle): array
    {
        $row = [0 => [$label, $labelStyle]];
        foreach (self::COLUMNS as $i => [, , , $group, $key]) {
            $value = $key === 'walk_in_total'
                ? ($counts['kind']['walk_in'] ?? 0) + ($counts['kind']['ts'] ?? 0) + ($counts['kind']['nts'] ?? 0)
                : ($counts[$group][$key] ?? 0);
            $row[$i + 1] = [(int) $value, $cellStyle];
        }
        $row[count(self::COLUMNS) + 1] = [(int) $counts['total'], $cellStyle === SimpleXlsx::STYLE_TOTAL ? SimpleXlsx::STYLE_TOTAL : SimpleXlsx::STYLE_CELL];

        return $row;
    }

    /** "October 2026", or "August 2026 to October 2026", or the days when they are not whole months. */
    private function periodLabel(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $wholeMonths = $from->day === 1 && $to->isSameDay($to->endOfMonth());

        if ($wholeMonths) {
            return $from->isSameMonth($to)
                ? $from->format('F Y')
                : $from->format('F Y').' to '.$to->format('F Y');
        }

        return $from->format('j F Y').' to '.$to->format('j F Y');
    }
}
