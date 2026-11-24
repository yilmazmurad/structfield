<?php

declare(strict_types=1);

namespace YilmazMurad\StructField\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YilmazMurad\StructField\Exception\ParseException;
use YilmazMurad\StructField\Item;
use YilmazMurad\StructField\StructuredField;

final class ParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidItems(): iterable
    {
        yield 'empty' => ['', 'unrecognised bare item'];
        yield 'non-ascii' => ['"ü"', 'must be ASCII'];
        yield 'trailing garbage' => ['1 2', 'trailing'];
        yield 'bad escape' => ['"a\\b"', 'invalid escape'];
        yield 'unterminated string' => ['"abc', 'unterminated string'];
        yield 'control char' => ["\"a\tb\"", 'invalid character'];
        yield 'too many integer digits' => ['1234567890123456', 'more than 15'];
        yield 'too many decimal int digits' => ['1234567890123.1', 'more than 12'];
        yield 'too many fraction digits' => ['1.1234', 'more than 3'];
        yield 'dangling dot' => ['1.', 'fractional digits'];
        yield 'lonely minus' => ['-', 'expected digit'];
        yield 'bad boolean' => ['?2', 'boolean'];
        yield 'decimal date' => ['@1.5', 'date must be an integer'];
        yield 'empty date' => ['@', 'after "@"'];
        yield 'bad date' => ['@x', 'after "@"'];
        yield 'unterminated bytes' => [':aGk=', 'unterminated byte sequence'];
        yield 'bad base64' => [':a*b=:', 'invalid base64'];
        yield 'display string uppercase hex' => ['%"%C3%BC"', 'lowercase hex'];
        yield 'display string invalid utf8' => ['%"%ff"', 'UTF-8'];
        yield 'display string no quote' => ['%abc', 'after "%"'];
        yield 'display string unterminated' => ['%"abc', 'unterminated display string'];
        yield 'display string control' => ["%\"a\tb\"", 'invalid character'];
        yield 'bad param key' => ['1;A=2', 'key must start'];
    }

    #[DataProvider('invalidItems')]
    public function testInvalidItems(string $input, string $message): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage($message);
        Item::fromHttpValue($input);
    }

    public function testParseExceptionCarriesInputAndPosition(): void
    {
        try {
            StructuredField::parseList('a, b,');
            self::fail('expected exception');
        } catch (ParseException $e) {
            self::assertSame('a, b,', $e->input);
            self::assertSame(5, $e->position);
            self::assertStringContainsString('trailing comma', $e->getMessage());
        }
    }

    public function testListAndDictionaryErrors(): void
    {
        foreach (['a b', '(a b', '(a,b)', 'a,,b'] as $bad) {
            self::assertNull(StructuredField::tryParseList($bad), $bad);
        }
        foreach (['a=1 b=2', 'A=1', 'a=1,', 'a=(1'] as $bad) {
            self::assertNull(StructuredField::tryParseDictionary($bad), $bad);
        }
        self::assertNull(StructuredField::tryParseItem('a, b'));
        self::assertNull(StructuredField::tryParseItem(null));
        self::assertNull(StructuredField::tryParseList(null));
        self::assertNull(StructuredField::tryParseDictionary(null));
    }

    public function testMultipleFieldLinesAreCombined(): void
    {
        self::assertSame('1, 2, 3', StructuredField::parseList(['1', '2, 3'])->toHttpValue());
        self::assertSame('a=1, b=3', StructuredField::parseDictionary(['a=1, b=2', 'b=3'])->toHttpValue());
    }

    public function testWhitespaceHandling(): void
    {
        self::assertSame('1, 2', StructuredField::parseList("  1 \t,\t 2  ")->toHttpValue());
        self::assertSame('(1 2);a', StructuredField::parseList('(  1   2  );a')->toHttpValue());
        self::assertSame('a;b=1;c', StructuredField::parseItem('a; b=1;  c')->toHttpValue());
        self::assertSame(1, StructuredField::tryParseItem(' 1 ')?->asInt());
    }

    public function testDuplicateKeysKeepFirstPositionLastValue(): void
    {
        $dict = StructuredField::parseDictionary('a=1, b=2, a=3');
        self::assertSame(['a', 'b'], $dict->keys());
        self::assertSame('a=3, b=2', (string) $dict);

        $item = StructuredField::parseItem('1;a=1;b=2;a=3');
        self::assertSame('1;a=3;b=2', (string) $item);
    }

    public function testAllBareTypesRoundTrip(): void
    {
        $header = '42, -1.5, "str", tok/en:x, :AQID:, ?0, @-86400, %"%e2%9c%93"';
        $list = StructuredField::parseList($header);
        self::assertSame($header, $list->toHttpValue());
        self::assertSame("\x01\x02\x03", $list->get(4) instanceof Item ? $list->get(4)->asBytes() : null);
        self::assertSame('✓', $list->get(7) instanceof Item ? $list->get(7)->asDisplayString() : null);
    }
}
