<?php

namespace Tests\Unit;

use App\Enums\ValidIdType;
use App\Rules\ValidIdNumber;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * An ID number in the shape its ID type prints it, however it was spaced.
 */
class ValidIdNumberTest extends TestCase
{
    /**
     * @return array<string, array{0: ValidIdType, 1: string}>
     */
    public static function numbers(): array
    {
        return [
            'a PhilSys number' => [ValidIdType::PhilSys, '1234567890123456'],
            'a PhilSys number as printed' => [ValidIdType::PhilSys, '1234-5678-9012-3456'],
            "a driver's license" => [ValidIdType::DriversLicense, 'N01-12-123456'],
            "a driver's license in small letters" => [ValidIdType::DriversLicense, 'n0112123456'],
            'a 9-digit TIN' => [ValidIdType::Tin, '123-456-789'],
            'a 12-digit TIN' => [ValidIdType::Tin, '123-456-789-000'],
            'an SSS number' => [ValidIdType::SssId, '34-1234567-8'],
            'a UMID CRN' => [ValidIdType::Umid, '0003-1234567-8'],
            'a PhilHealth number' => [ValidIdType::PhilHealth, '12-345678901-2'],
            'a Pag-IBIG number' => [ValidIdType::PagIbig, '1212 3456 7890'],
            'a passport' => [ValidIdType::Passport, 'P1234567A'],
            'a Postal ID, as printed' => [ValidIdType::PostalId, 'PRN 100141234567 P'],
            "a Voter's ID, as printed" => [ValidIdType::VotersId, '7501-0012A-B1234CDE5678'],
        ];
    }

    /**
     * @return array<string, array{0: ValidIdType, 1: string, 2: string}>
     */
    public static function wrongShapes(): array
    {
        return [
            'a PhilSys number one short' => [ValidIdType::PhilSys, '123456789012345', 'PhilSys number: 16 digits, e.g. 1234567890123456.'],
            "a driver's license with no letter" => [ValidIdType::DriversLicense, '01-12-123456', "Driver's license number: 1 letter and 10 digits, e.g. A1234567890."],
            'a 10-digit TIN' => [ValidIdType::Tin, '1234567890', 'TIN: 9 or 12 digits, e.g. 123456789 or 123456789000.'],
            'an SSS number with a letter' => [ValidIdType::SssId, '34-1234567-X', 'SSS number: 10 digits, e.g. 1234567890.'],
            'a UMID one long' => [ValidIdType::Umid, '0003-1234567-89', 'UMID number (CRN): 12 digits, e.g. 123456789012.'],
            'a PhilHealth number one short' => [ValidIdType::PhilHealth, '12-34567890-1', 'PhilHealth number: 12 digits, e.g. 123456789012.'],
            'a Pag-IBIG number one short' => [ValidIdType::PagIbig, '1212-3456-789', 'Pag-IBIG number: 12 digits, e.g. 123456789012.'],
            'an old passport' => [ValidIdType::Passport, 'EB1234567', 'Passport number: 1 letter, 7 digits and 1 letter, e.g. P1234567A.'],
            'separators alone' => [ValidIdType::PhilSys, '- -', 'PhilSys number: 16 digits, e.g. 1234567890123456.'],
        ];
    }

    #[DataProvider('numbers')]
    public function test_a_number_in_its_types_shape_passes(ValidIdType $type, string $number): void
    {
        $this->assertTrue(Validator::make(['number' => $number], ['number' => [new ValidIdNumber($type->value)]])->passes());
    }

    #[DataProvider('wrongShapes')]
    public function test_a_number_of_another_shape_is_refused_with_an_example(ValidIdType $type, string $number, string $message): void
    {
        $validator = Validator::make(['number' => $number], ['number' => [new ValidIdNumber($type->value)]]);

        $this->assertTrue($validator->fails());
        $this->assertSame($message, $validator->errors()->first('number'));
    }

    public function test_no_type_or_an_unknown_one_leaves_the_number_alone(): void
    {
        foreach ([null, '', 'library_card', ['philsys']] as $type) {
            $this->assertTrue(Validator::make(['number' => 'anything'], ['number' => [new ValidIdNumber($type)]])->passes());
        }
    }
}
