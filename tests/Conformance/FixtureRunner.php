<?php

declare(strict_types=1);

namespace YilmazMurad\StructField\Tests\Conformance;

use RuntimeException;
use YilmazMurad\StructField\ByteSequence;
use YilmazMurad\StructField\Date;
use YilmazMurad\StructField\Dictionary;
use YilmazMurad\StructField\DisplayString;
use YilmazMurad\StructField\Exception\StructFieldException;
use YilmazMurad\StructField\InnerList;
use YilmazMurad\StructField\Item;
use YilmazMurad\StructField\OuterList;
use YilmazMurad\StructField\Parameters;
use YilmazMurad\StructField\Parser;
use YilmazMurad\StructField\StructuredValue;
use YilmazMurad\StructField\Token;

/**
 * Runs records from the httpwg/structured-field-tests suite.
 *
 * @see https://github.com/httpwg/structured-field-tests
 *
 * @phpstan-type TestRecord array{name: string, header_type: string, raw?: list<string>, expected?: mixed, must_fail?: bool, can_fail?: bool, canonical?: list<string>}
 */
final class FixtureRunner
{
    public const string FIXTURE_DIR = __DIR__ . '/../fixtures';

    /**
     * @return iterable<string, array{string, TestRecord}>
     */
    public static function parseRecords(): iterable
    {
        return self::records(glob(self::FIXTURE_DIR . '/*.json') ?: []);
    }

    /**
     * @return iterable<string, array{string, TestRecord}>
     */
    public static function serialisationRecords(): iterable
    {
        return self::records(glob(self::FIXTURE_DIR . '/serialisation-tests/*.json') ?: []);
    }

    /**
     * Runs a parse record. Returns null on success or a failure description.
     *
     * @param TestRecord $test
     */
    public static function runParse(array $test): ?string
    {
        $mustFail = $test['must_fail'] ?? false;
        $canFail = $test['can_fail'] ?? false;
        $raw = $test['raw'] ?? [];

        try {
            $parsed = self::parse($test['header_type'], $raw);
        } catch (StructFieldException $e) {
            return $mustFail || $canFail ? null : 'unexpected parse failure: ' . $e->getMessage();
        }

        if ($mustFail) {
            return 'expected parse failure, got ' . var_export($parsed->toHttpValue(), true);
        }

        $actual = self::toJson($parsed);
        if ($actual !== self::normalizeExpected($test['expected'] ?? null)) {
            return 'parsed structure mismatch: ' . json_encode($actual);
        }

        $canonical = $test['canonical'] ?? $raw;
        $expectedText = $canonical === [] ? '' : $canonical[0];
        $serialized = $parsed->toHttpValue();
        if ($serialized !== $expectedText) {
            return sprintf('serialization mismatch: expected %s, got %s', var_export($expectedText, true), var_export($serialized, true));
        }

        return null;
    }

    /**
     * Runs a serialisation-only record. Returns null on success or a failure description.
     *
     * @param TestRecord $test
     */
    public static function runSerialise(array $test): ?string
    {
        $mustFail = $test['must_fail'] ?? false;
        try {
            $serialized = self::build($test['header_type'], $test['expected'] ?? null)->toHttpValue();
        } catch (StructFieldException $e) {
            return $mustFail ? null : 'unexpected failure: ' . $e->getMessage();
        }
        if ($mustFail) {
            return 'expected serialization failure, got ' . var_export($serialized, true);
        }
        $canonical = $test['canonical'] ?? [];
        $expectedText = $canonical === [] ? '' : $canonical[0];

        return $serialized === $expectedText
            ? null
            : sprintf('expected %s, got %s', var_export($expectedText, true), var_export($serialized, true));
    }

    /**
     * @param list<string> $files
     *
     * @return iterable<string, array{string, TestRecord}>
     */
    private static function records(array $files): iterable
    {
        sort($files);
        foreach ($files as $file) {
            $json = file_get_contents($file);
            if ($json === false) {
                throw new RuntimeException("Cannot read $file");
            }
            /** @var list<TestRecord> $records */
            $records = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $base = basename($file);
            foreach ($records as $i => $record) {
                yield sprintf('%s #%d: %s', $base, $i, $record['name']) => [$base, $record];
            }
        }
    }

    /**
     * @param list<string> $raw
     */
    private static function parse(string $type, array $raw): Item|OuterList|Dictionary
    {
        return match ($type) {
            'item' => Parser::parseItem($raw),
            'list' => Parser::parseList($raw),
            'dictionary' => Parser::parseDictionary($raw),
            default => throw new RuntimeException("Unknown header type $type"),
        };
    }

    // ---- data model → JSON form -------------------------------------------------

    private static function toJson(StructuredValue $value): mixed
    {
        return match (true) {
            $value instanceof Item => [self::bareToJson($value->value), self::paramsToJson($value->parameters)],
            $value instanceof InnerList => [
                array_map(static fn(Item $i) => self::toJson($i), $value->items),
                self::paramsToJson($value->parameters),
            ],
            $value instanceof OuterList => array_map(static fn($m) => self::toJson($m), $value->members),
            $value instanceof Dictionary => array_map(
                static fn(string $k, $m) => [$k, self::toJson($m)],
                $value->keys(),
                array_values($value->toArray()),
            ),
            default => throw new RuntimeException('Unexpected value'),
        };
    }

    /**
     * @return list<array{string, mixed}>
     */
    private static function paramsToJson(Parameters $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            $out[] = [$k, self::bareToJson($v)];
        }

        return $out;
    }

    private static function bareToJson(int|float|string|bool|Token|ByteSequence|Date|DisplayString $v): mixed
    {
        return match (true) {
            $v instanceof Token => ['__type' => 'token', 'value' => $v->value],
            $v instanceof ByteSequence => ['__type' => 'binary', 'value' => self::base32Encode($v->bytes)],
            $v instanceof Date => ['__type' => 'date', 'value' => $v->timestamp],
            $v instanceof DisplayString => ['__type' => 'displaystring', 'value' => $v->value],
            default => $v,
        };
    }

    /**
     * Base32 strings in the fixtures may use any padding; compare decoded bytes instead.
     */
    private static function normalizeExpected(mixed $expected): mixed
    {
        if (is_array($expected)) {
            if (($expected['__type'] ?? null) === 'binary' && is_string($expected['value'] ?? null)) {
                return ['__type' => 'binary', 'value' => self::base32Encode(self::base32Decode($expected['value']))];
            }

            return array_map(self::normalizeExpected(...), $expected);
        }

        return $expected;
    }

    // ---- JSON form → data model -------------------------------------------------

    private static function build(string $type, mixed $expected): StructuredValue
    {
        if (!is_array($expected)) {
            throw new RuntimeException('Invalid expected structure');
        }

        return match ($type) {
            'item' => self::buildItem($expected),
            'list' => new OuterList(array_map(static fn($m) => self::buildMember(self::arr($m)), $expected)),
            'dictionary' => self::buildDictionary($expected),
            default => throw new RuntimeException("Unknown header type $type"),
        };
    }

    /**
     * @param array<mixed> $pairs
     */
    private static function buildDictionary(array $pairs): Dictionary
    {
        $dict = new Dictionary();
        foreach ($pairs as $pair) {
            $pair = self::arr($pair);
            $dict = $dict->with(self::str($pair[0]), self::buildMember(self::arr($pair[1])));
        }

        return $dict;
    }

    /**
     * @param array<mixed> $member
     */
    private static function buildMember(array $member): Item|InnerList
    {
        $first = $member[0] ?? null;
        if (is_array($first) && array_is_list($first)) {
            return new InnerList(
                array_map(static fn($i) => self::buildItem(self::arr($i)), $first),
                self::buildParams(self::arr($member[1] ?? [])),
            );
        }

        return self::buildItem($member);
    }

    /**
     * @param array<mixed> $item
     */
    private static function buildItem(array $item): Item
    {
        return new Item(self::buildBare($item[0] ?? null), self::buildParams(self::arr($item[1] ?? [])));
    }

    /**
     * @param array<mixed> $pairs
     */
    private static function buildParams(array $pairs): Parameters
    {
        $params = new Parameters();
        foreach ($pairs as $pair) {
            $pair = self::arr($pair);
            $params = $params->with(self::str($pair[0]), self::buildBare($pair[1] ?? null));
        }

        return $params;
    }

    private static function buildBare(mixed $v): int|float|string|bool|Token|ByteSequence|Date|DisplayString
    {
        if (is_int($v) || is_float($v) || is_string($v) || is_bool($v)) {
            return $v;
        }
        $v = self::arr($v);
        $value = $v['value'] ?? null;

        return match ($v['__type'] ?? null) {
            'token' => new Token(self::str($value)),
            'binary' => new ByteSequence(self::base32Decode(self::str($value))),
            'date' => new Date(is_int($value) ? $value : throw new RuntimeException('bad date')),
            'displaystring' => new DisplayString(self::str($value)),
            default => throw new RuntimeException('Unknown __type'),
        };
    }

    /**
     * @return array<mixed>
     */
    private static function arr(mixed $v): array
    {
        return is_array($v) ? $v : throw new RuntimeException('Expected array');
    }

    private static function str(mixed $v): string
    {
        return is_string($v) ? $v : throw new RuntimeException('Expected string');
    }

    // ---- base32 (RFC 4648) ---------------------------------------------------------

    private const string B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function base32Decode(string $input): string
    {
        $input = rtrim(strtoupper($input), '=');
        $bits = '';
        foreach (str_split($input) as $c) {
            $idx = strpos(self::B32, $c);
            if ($idx === false) {
                throw new RuntimeException('Invalid base32');
            }
            $bits .= str_pad(decbin($idx), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }

        return $out;
    }

    public static function base32Encode(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $out . str_repeat('=', (8 - strlen($out) % 8) % 8);
    }
}
