<?php

declare(strict_types=1);

namespace YilmazMurad\StructField\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YilmazMurad\StructField\Item;

final class DecimalTest extends TestCase
{
    /**
     * @return iterable<string, array{float, string}>
     */
    public static function decimals(): iterable
    {
        yield 'zero' => [0.0, '0.0'];
        yield 'negative zero' => [-0.0, '0.0'];
        yield 'integer value' => [10.0, '10.0'];
        yield 'trailing zeros stripped' => [1.500, '1.5'];
        yield 'half even down' => [0.0025, '0.002'];
        yield 'half even up' => [0.0015, '0.002'];
        yield 'above half' => [0.00251, '0.003'];
        yield 'below half' => [0.00249, '0.002'];
        yield 'carry into integer' => [9.9995, '10.0'];
        yield 'carry across all digits' => [99.9999, '100.0'];
        yield 'tiny positive (exponent form)' => [1.0E-7, '0.0'];
        yield 'tiny negative keeps sign' => [-1.0E-7, '-0.0'];
        yield 'max' => [999_999_999_999.999, '999999999999.999'];
        yield 'negative' => [-123.456, '-123.456'];
        yield 'large exponent form' => [1.0E+11, '100000000000.0'];
    }

    #[DataProvider('decimals')]
    public function testDecimalSerialization(float $value, string $expected): void
    {
        self::assertSame($expected, Item::decimal($value)->toHttpValue());
    }
}
