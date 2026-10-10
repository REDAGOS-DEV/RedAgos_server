<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Peso amounts become exact centavos, and a fraction of a centavo is refused.
 */
class MoneyTest extends TestCase
{
    /**
     * @return array<string, array{0: int|float|string, 1: int}>
     */
    public static function amounts(): array
    {
        return [
            'one centavo as a float' => [0.01, 1],
            'ten centavos as a float' => [0.10, 10],
            'whole pesos as an integer' => [1500, 150000],
            'whole pesos as a string' => ['1500', 150000],
            'one decimal place' => ['1500.5', 150050],
            'a stored decimal' => ['99999.99', 9999999],
            'the GCash ceiling' => ['100000.00', 10000000],
            'a float sum with binary noise' => [0.1 + 0.2, 30],
            'trailing zeros beyond two places' => ['12.3400', 1234],
            'padded with spaces' => [' 7.50 ', 750],
            'zero' => ['0.00', 0],
            'a negative amount' => ['-12.05', -1205],
            'a negative float' => [-0.5, -50],
        ];
    }

    #[DataProvider('amounts')]
    public function test_an_amount_becomes_exact_centavos(int|float|string $amount, int $centavos): void
    {
        $this->assertSame($centavos, Money::toCentavos($amount));
    }

    /**
     * @return array<string, array{0: float|string}>
     */
    public static function refused(): array
    {
        return [
            'a fraction of a centavo as a string' => ['12.345'],
            'a fraction of a centavo as a float' => [12.345],
            'text' => ['abc'],
            'an empty string' => [''],
            'a thousands separator' => ['1,500.00'],
            'scientific notation as a string' => ['1e3'],
            'infinity' => [INF],
            'not a number' => [NAN],
        ];
    }

    #[DataProvider('refused')]
    public function test_an_inexact_or_malformed_amount_is_refused(float|string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::toCentavos($amount);
    }

    public function test_centavos_render_as_the_stored_decimal(): void
    {
        $this->assertSame('1500.00', Money::toDecimal(150000));
        $this->assertSame('0.05', Money::toDecimal(5));
        $this->assertSame('0.00', Money::toDecimal(0));
        $this->assertSame('-12.05', Money::toDecimal(-1205));
    }

    public function test_an_amount_round_trips_through_its_stored_form(): void
    {
        foreach (['0.01', '0.10', '1070.00', '99999.99', '100000.00'] as $stored) {
            $this->assertSame($stored, Money::toDecimal(Money::toCentavos($stored)));
        }
    }

    public function test_many_small_amounts_sum_without_drift(): void
    {
        $centavos = 0;

        for ($i = 0; $i < 1000; $i++) {
            $centavos += Money::toCentavos(0.1);
        }

        $this->assertSame(10000, $centavos);
        $this->assertSame('100.00', Money::toDecimal($centavos));
    }

    public function test_the_json_form_keeps_the_existing_number_shape(): void
    {
        $this->assertSame(1070.0, Money::toFloat(107000));
        $this->assertSame(0.3, Money::toFloat(Money::toCentavos(0.1) + Money::toCentavos(0.2)));
    }
}
