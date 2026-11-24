<?php

declare(strict_types=1);

namespace YilmazMurad\StructField\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YilmazMurad\StructField\BareType;
use YilmazMurad\StructField\ByteSequence;
use YilmazMurad\StructField\Date;
use YilmazMurad\StructField\Dictionary;
use YilmazMurad\StructField\DisplayString;
use YilmazMurad\StructField\Exception\InvalidValueException;
use YilmazMurad\StructField\Exception\StructFieldException;
use YilmazMurad\StructField\Exception\TypeMismatchException;
use YilmazMurad\StructField\InnerList;
use YilmazMurad\StructField\Item;
use YilmazMurad\StructField\OuterList;
use YilmazMurad\StructField\Parameters;
use YilmazMurad\StructField\StructuredField;
use YilmazMurad\StructField\Token;

final class ValueObjectsTest extends TestCase
{
    /**
     * @return iterable<string, array{Item, BareType, string}>
     */
    public static function namedConstructors(): iterable
    {
        yield 'integer' => [Item::integer(-42), BareType::Integer, '-42'];
        yield 'decimal from int' => [Item::decimal(2), BareType::Decimal, '2.0'];
        yield 'decimal' => [Item::decimal(3.14159), BareType::Decimal, '3.142'];
        yield 'string' => [Item::string('say "hi" \\o/'), BareType::String, '"say \"hi\" \\\\o/"'];
        yield 'token' => [Item::token('text/html'), BareType::Token, 'text/html'];
        yield 'bytes' => [Item::bytes('hello'), BareType::ByteSequence, ':aGVsbG8=:'];
        yield 'true' => [Item::boolean(true), BareType::Boolean, '?1'];
        yield 'false' => [Item::boolean(false), BareType::Boolean, '?0'];
        yield 'date int' => [Item::date(0), BareType::Date, '@0'];
        yield 'date object' => [Item::date(new DateTimeImmutable('2026-11-24T00:00:00Z')), BareType::Date, '@1795478400'];
        yield 'display string' => [Item::displayString('Merhaba dünya %"'), BareType::DisplayString, '%"Merhaba d%c3%bcnya %25%22"'];
    }

    #[DataProvider('namedConstructors')]
    public function testNamedConstructors(Item $item, BareType $type, string $serialized): void
    {
        self::assertSame($type, $item->type());
        self::assertTrue($item->is($type));
        self::assertSame($serialized, $item->toHttpValue());
        self::assertTrue(Item::fromHttpValue($serialized)->equals($item));
    }

    public function testTypedAccessors(): void
    {
        self::assertSame(5, Item::integer(5)->asInt());
        self::assertSame(5.0, Item::integer(5)->asFloat());
        self::assertSame(1.5, Item::decimal(1.5)->asFloat());
        self::assertSame('x', Item::string('x')->asString());
        self::assertSame('gzip', Item::token('gzip')->asToken());
        self::assertSame("\x00\xff", Item::bytes("\x00\xff")->asBytes());
        self::assertTrue(Item::boolean(true)->asBool());
        self::assertSame(86400, Item::date(86400)->asDate()->getTimestamp());
        self::assertSame('ğüş', Item::displayString('ğüş')->asDisplayString());
        self::assertSame('a', Item::string('a')->asText());
        self::assertSame('b', Item::token('b')->asText());
        self::assertSame('ç', Item::displayString('ç')->asText());
    }

    /**
     * @return iterable<string, array{callable(): mixed}>
     */
    public static function mismatches(): iterable
    {
        yield 'int' => [static fn() => Item::string('1')->asInt()];
        yield 'float' => [static fn() => Item::string('1')->asFloat()];
        yield 'string' => [static fn() => Item::token('a')->asString()];
        yield 'token' => [static fn() => Item::string('a')->asToken()];
        yield 'bytes' => [static fn() => Item::string('a')->asBytes()];
        yield 'bool' => [static fn() => Item::integer(1)->asBool()];
        yield 'date' => [static fn() => Item::integer(1)->asDate()];
        yield 'display' => [static fn() => Item::string('a')->asDisplayString()];
        yield 'text' => [static fn() => Item::integer(1)->asText()];
        yield 'param int' => [static fn() => (new Parameters(['a' => 'x']))->int('a')];
        yield 'param bool' => [static fn() => (new Parameters(['a' => 1]))->bool('a')];
    }

    #[DataProvider('mismatches')]
    public function testTypeMismatch(callable $fn): void
    {
        $this->expectException(TypeMismatchException::class);
        $fn();
    }

    /**
     * @return iterable<string, array{callable(): mixed}>
     */
    public static function invalidValues(): iterable
    {
        yield 'integer too large' => [static fn() => Item::integer(1_000_000_000_000_000)];
        yield 'integer too small' => [static fn() => Item::integer(-1_000_000_000_000_000)];
        yield 'decimal too large' => [static fn() => Item::decimal(1_000_000_000_000.0)];
        yield 'decimal rounds too large' => [static fn() => Item::decimal(999_999_999_999.9996)];
        yield 'decimal NaN' => [static fn() => Item::decimal(NAN)];
        yield 'decimal INF' => [static fn() => Item::decimal(INF)];
        yield 'string control char' => [static fn() => Item::string("a\nb")];
        yield 'string non-ascii' => [static fn() => Item::string('ü')];
        yield 'token start digit' => [static fn() => new Token('1abc')];
        yield 'token space' => [static fn() => new Token('a b')];
        yield 'empty token' => [static fn() => new Token('')];
        yield 'display string invalid utf8' => [static fn() => new DisplayString("\xff")];
        yield 'date out of range' => [static fn() => new Date(PHP_INT_MAX)];
        yield 'bad base64' => [static fn() => ByteSequence::fromBase64('!!')];
        yield 'param key uppercase' => [static fn() => new Parameters(['Key' => 1])];
        yield 'dict key empty' => [static fn() => new Dictionary(['' => 1])];
        yield 'with invalid key' => [static fn() => Item::integer(1)->withParameter('A', 1)];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesThrow(callable $fn): void
    {
        $this->expectException(InvalidValueException::class);
        $fn();
    }

    public function testExceptionsShareMarkerInterface(): void
    {
        try {
            Item::integer(PHP_INT_MAX);
            self::fail('expected exception');
        } catch (StructFieldException $e) {
            self::assertInstanceOf(\InvalidArgumentException::class, $e);
        }
    }

    public function testItemImmutabilityAndFluentHelpers(): void
    {
        $item = Item::token('gzip');
        $withQ = $item->withParameter('q', 0.8)->withParameter('x', true);

        self::assertSame('gzip', $item->toHttpValue());
        self::assertSame('gzip;q=0.8;x', $withQ->toHttpValue());
        self::assertSame('gzip;x', $withQ->withoutParameter('q')->toHttpValue());
        self::assertSame('br;q=0.8;x', $withQ->withValue(new Token('br'))->toHttpValue());
        self::assertSame('gzip;a=1', $withQ->withParameters(['a' => 1])->toHttpValue());
        self::assertSame(0.8, $withQ->param('q'));
        self::assertSame('dflt', $withQ->param('nope', 'dflt'));
    }

    public function testParametersApi(): void
    {
        $p = new Parameters(['a' => 1, 'b' => true]);
        $p2 = $p->with('a', 2)->with('c', 'x');

        self::assertSame(['a', 'b'], $p->keys());
        self::assertSame(['a' => 2, 'b' => true, 'c' => 'x'], $p2->toArray(), 'overwrite keeps position');
        self::assertSame(';a=2;b;c="x"', (string) $p2);
        self::assertSame(';b', $p->without('a')->toHttpValue());
        self::assertSame(';a=1;b;z=?0', $p->merge(['z' => false])->toHttpValue());
        self::assertTrue($p->has('a'));
        self::assertFalse($p->has('zz'));
        self::assertCount(2, $p);
        self::assertFalse($p->isEmpty());
        self::assertTrue((new Parameters())->isEmpty());
        self::assertSame(1, $p->item('a')?->asInt());
        self::assertNull($p->item('zz'));
        self::assertSame(9, $p->int('zz', 9));
        self::assertTrue($p->bool('b'));
        self::assertSame(['a' => 1, 'b' => true], iterator_to_array($p));
        self::assertSame($p, Parameters::from($p));
    }

    public function testInnerListApi(): void
    {
        $inner = new InnerList(['a', new Token('b'), Item::integer(1, ['x' => true])], ['lvl' => 5]);

        self::assertSame('("a" b 1;x);lvl=5', $inner->toHttpValue());
        self::assertCount(3, $inner);
        self::assertSame(1, $inner->get(-1)?->asInt());
        self::assertNull($inner->get(10));
        self::assertSame('("a" b 1;x 2);lvl=5', (string) $inner->append(2));
        self::assertSame('("a" 1;x);lvl=5', (string) $inner->remove(1));
        self::assertSame('("a" b 1;x);lvl=6;y', (string) $inner->withParameter('lvl', 6)->withParameter('y', true));
        self::assertSame('("a" b 1;x)', (string) $inner->withoutParameter('lvl'));
        self::assertSame('("a" b 1;x);z=1', (string) $inner->withParameters(['z' => 1]));
        self::assertSame(5, $inner->param('lvl'));
        self::assertSame('()', (string) new InnerList());
        self::assertTrue((new InnerList())->isEmpty());
        self::assertCount(3, iterator_to_array($inner));
    }

    public function testOuterListApi(): void
    {
        $list = new OuterList([1, 'two', new InnerList([3])]);

        self::assertSame('1, "two", (3)', $list->toHttpValue());
        self::assertSame([1, 'two', [3]], $list->values());
        self::assertSame('0, 1, "two", (3), ?1', (string) $list->prepend(0)->append(true));
        self::assertSame('1, (3)', (string) $list->remove(1));
        self::assertTrue($list->contains(1));
        self::assertFalse($list->contains(3), 'inner list members are not matched');
        self::assertSame('', (string) new OuterList(), 'empty list => omit field');
        self::assertTrue((new OuterList())->isEmpty());
        self::assertCount(3, iterator_to_array($list));
    }

    public function testDictionaryApi(): void
    {
        $dict = new Dictionary(['a' => 1, 'b' => true, 'c' => new InnerList([1, 2])]);

        self::assertSame('a=1, b, c=(1 2)', $dict->toHttpValue());
        self::assertSame(['a', 'b', 'c'], $dict->keys());
        self::assertSame(1, $dict->value('a'));
        self::assertSame('d', $dict->value('c', 'd'), 'inner lists have no bare value');
        self::assertSame('a=2, b, c=(1 2)', (string) $dict->with('a', 2));
        self::assertSame('a=1, c=(1 2), z=?0', (string) $dict->without('b')->merge(['z' => false]));
        self::assertSame('a=1, b, c=(1 2)', (string) $dict->merge(new Dictionary(['b' => true])));
        self::assertSame('b;p=1', (string) new Dictionary(['b' => Item::boolean(true, ['p' => 1])]));
        self::assertSame('b=?0;p=1', (string) new Dictionary(['b' => Item::boolean(false, ['p' => 1])]));
        self::assertTrue($dict->has('c'));
        self::assertCount(3, $dict);
        self::assertCount(3, $dict->toArray());
        self::assertCount(3, iterator_to_array($dict));
        self::assertTrue((new Dictionary())->isEmpty());
        self::assertSame('', StructuredField::serialize(new Dictionary()));
    }

    public function testDateAndByteSequenceHelpers(): void
    {
        $date = Date::fromDateTime(new DateTimeImmutable('1970-01-02T00:00:00Z'));
        self::assertSame(86400, $date->timestamp);
        self::assertSame('UTC', $date->toDateTime()->getTimezone()->getName());
        self::assertTrue($date->equals(new Date(86400)));
        self::assertEqualsWithDelta(time(), Date::now()->timestamp, 5);

        $bytes = ByteSequence::fromBase64('aGVsbG8=');
        self::assertSame('hello', $bytes->bytes);
        self::assertSame('aGVsbG8=', $bytes->toBase64());
        self::assertTrue($bytes->equals(new ByteSequence('hello')));

        self::assertTrue((new Token('a'))->equals(new Token('a')));
        self::assertTrue((new DisplayString('ü'))->equals(new DisplayString('ü')));
        self::assertSame('ü', (string) new DisplayString('ü'));
        self::assertSame('gzip', (string) new Token('gzip'));
        self::assertSame(BareType::Token, BareType::of(new Token('x')));
    }
}
