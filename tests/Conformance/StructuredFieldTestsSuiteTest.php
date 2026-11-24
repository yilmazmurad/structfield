<?php

declare(strict_types=1);

namespace YilmazMurad\StructField\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the official httpwg/structured-field-tests JSON suite (vendored in tests/fixtures).
 *
 * @phpstan-import-type TestRecord from FixtureRunner
 */
final class StructuredFieldTestsSuiteTest extends TestCase
{
    /**
     * @return iterable<string, array{string, TestRecord}>
     */
    public static function parseProvider(): iterable
    {
        return FixtureRunner::parseRecords();
    }

    /**
     * @return iterable<string, array{string, TestRecord}>
     */
    public static function serialiseProvider(): iterable
    {
        return FixtureRunner::serialisationRecords();
    }

    /**
     * @param TestRecord $test
     */
    #[DataProvider('parseProvider')]
    public function testParseAndSerialise(string $file, array $test): void
    {
        $failure = FixtureRunner::runParse($test);
        self::assertNull($failure, $file . ' / ' . $test['name'] . ': ' . $failure);
    }

    /**
     * @param TestRecord $test
     */
    #[DataProvider('serialiseProvider')]
    public function testSerialiseOnly(string $file, array $test): void
    {
        $failure = FixtureRunner::runSerialise($test);
        self::assertNull($failure, $file . ' / ' . $test['name'] . ': ' . $failure);
    }
}
