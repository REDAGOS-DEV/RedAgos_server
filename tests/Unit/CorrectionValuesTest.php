<?php

namespace Tests\Unit;

use App\Support\CorrectionValues;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A value that merely prints differently must not look like a change.
 */
class CorrectionValuesTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: mixed, 2: string}>
     */
    public static function equalForms(): array
    {
        return [
            'a date with and without a time' => ['2026-10-09', '2026-10-09 00:00:00', CorrectionValues::DATE],
            'a date as an ISO string' => ['2026-10-09', '2026-10-09T00:00:00Z', CorrectionValues::DATE],
            'an instant in two timezones' => ['2026-10-08T02:00:00Z', '2026-10-08T10:00:00+08:00', CorrectionValues::DATETIME],
            'an instant with and without microseconds' => ['2026-10-08T02:00:00Z', '2026-10-08 02:00:00.123456', CorrectionValues::DATETIME],
            'an instant as a Carbon object' => ['2026-10-08T02:00:00Z', new CarbonImmutable('2026-10-08 02:00:00', 'UTC'), CorrectionValues::DATETIME],
            'an integer and a two-decimal amount' => [1500, '1500.00', CorrectionValues::MONEY],
            'a float and a stored decimal' => [1500.0, '1500.00', CorrectionValues::MONEY],
            'an empty string and null' => ['', null, CorrectionValues::STRING],
            'padded and trimmed text' => ['  Freezer B ', 'Freezer B', CorrectionValues::STRING],
        ];
    }

    #[DataProvider('equalForms')]
    public function test_different_spellings_of_one_value_are_the_same(mixed $a, mixed $b, string $type): void
    {
        $this->assertTrue(CorrectionValues::same($a, $b, $type));
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed, 2: string}>
     */
    public static function differentValues(): array
    {
        return [
            'another day' => ['2026-10-09', '2026-10-10', CorrectionValues::DATE],
            'another second' => ['2026-10-08T02:00:00Z', '2026-10-08T02:00:01Z', CorrectionValues::DATETIME],
            'another cent' => ['1500.00', '1500.01', CorrectionValues::MONEY],
            'text and nothing' => ['Freezer B', null, CorrectionValues::STRING],
            'different text' => ['Freezer B', 'Freezer C', CorrectionValues::STRING],
        ];
    }

    #[DataProvider('differentValues')]
    public function test_a_real_difference_is_still_a_difference(mixed $a, mixed $b, string $type): void
    {
        $this->assertFalse(CorrectionValues::same($a, $b, $type));
    }

    public function test_normalising_puts_each_type_in_one_canonical_form(): void
    {
        $this->assertSame('2026-10-08T02:00:00Z', CorrectionValues::normalize('2026-10-08T10:00:00.5+08:00', CorrectionValues::DATETIME));
        $this->assertSame('2026-10-09', CorrectionValues::normalize('2026-10-09 13:00:00', CorrectionValues::DATE));
        $this->assertSame('1500.50', CorrectionValues::normalize(1500.5, CorrectionValues::MONEY));
        $this->assertNull(CorrectionValues::normalize(null, CorrectionValues::MONEY));
        $this->assertNull(CorrectionValues::normalize('   ', CorrectionValues::STRING));
    }

    public function test_normalise_all_keeps_only_the_declared_fields(): void
    {
        $this->assertSame(
            ['amount_paid' => '10.00', 'reference_number' => null],
            CorrectionValues::normalizeAll(
                ['amount_paid' => 10, 'reference_number' => '', 'status' => 'refunded'],
                ['amount_paid' => CorrectionValues::MONEY, 'reference_number' => CorrectionValues::STRING, 'payment_method' => CorrectionValues::STRING]
            )
        );
    }
}
