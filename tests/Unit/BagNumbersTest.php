<?php

namespace Tests\Unit;

use App\Support\BagNumbers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reading a bag number back: [centre]-[barcode]-[component], and -2, -3… for
 * a repeated component (project owner, 2026-11-13).
 */
class BagNumbersTest extends TestCase
{
    public function test_a_bag_number_reads_back_into_its_parts(): void
    {
        $this->assertSame(
            ['centre' => 'SNBC', 'barcode' => '21627990', 'component' => 'PRBC', 'sequence' => 1],
            BagNumbers::parse('SNBC-21627990-PRBC')
        );

        $this->assertSame(
            ['centre' => 'SNBC', 'barcode' => '21627990', 'component' => 'PRBC', 'sequence' => 2],
            BagNumbers::parse(' snbc-21627990-prbc-2 ')
        );
    }

    public function test_a_number_without_its_centre_still_reads(): void
    {
        $this->assertSame(
            ['centre' => null, 'barcode' => '21627990', 'component' => 'FFP', 'sequence' => 1],
            BagNumbers::parse('21627990-FFP')
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notBagNumbers(): array
    {
        return [
            'a sticker alone' => ['21627990'],
            'a name' => ['Maria Santos'],
            'a donation code' => ['DON-000123'],
            'a hospital delivery id' => ['DD-4-12'],
            'letters in the barcode' => ['SNBC-2162A990-PRBC'],
            'a centre too long' => ['ABCDEFG-21627990-PRBC'],
            'empty' => [''],
        ];
    }

    #[DataProvider('notBagNumbers')]
    public function test_other_text_is_not_a_bag_number(string $text): void
    {
        $this->assertNull(BagNumbers::parse($text));
    }

    public function test_a_scanned_sticker_names_its_own_barcode(): void
    {
        // A scanner's trailing return and a typed space are not part of it.
        $this->assertSame('21627990', BagNumbers::barcodeFrom("21627990\r\n", 'SNBC'));
    }

    public function test_a_full_bag_number_names_the_barcode_inside_it(): void
    {
        $this->assertSame('21627990', BagNumbers::barcodeFrom('SNBC-21627990-PRBC-2', 'SNBC'));
        $this->assertSame('21627990', BagNumbers::barcodeFrom('snbc-21627990-ffp', 'SNBC'));
        $this->assertSame('21627990', BagNumbers::barcodeFrom('21627990-PRBC', 'SNBC'));
    }

    public function test_another_centres_bag_names_none_of_this_centres_donations(): void
    {
        // Stickers are unique per centre, not across them.
        $this->assertNull(BagNumbers::barcodeFrom('PRC-21627990-PRBC', 'SNBC'));
    }

    public function test_text_that_is_neither_names_no_barcode(): void
    {
        $this->assertNull(BagNumbers::barcodeFrom('Maria', 'SNBC'));
        $this->assertNull(BagNumbers::barcodeFrom('   ', 'SNBC'));
    }
}
