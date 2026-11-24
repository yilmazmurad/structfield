<?php

declare(strict_types=1);

namespace YilmazMurad\StructField;

use YilmazMurad\StructField\Exception\ParseException;

/**
 * Static facade for parsing and serializing Structured Field Values (RFC 9651).
 */
final class StructuredField
{
    private function __construct() {}

    /**
     * @param string|list<string> $httpValue
     *
     * @throws ParseException
     */
    public static function parseItem(string|array $httpValue): Item
    {
        return Parser::parseItem($httpValue);
    }

    /**
     * @param string|list<string> $httpValue
     *
     * @throws ParseException
     */
    public static function parseList(string|array $httpValue): OuterList
    {
        return Parser::parseList($httpValue);
    }

    /**
     * @param string|list<string> $httpValue
     *
     * @throws ParseException
     */
    public static function parseDictionary(string|array $httpValue): Dictionary
    {
        return Parser::parseDictionary($httpValue);
    }

    /**
     * Parses an Item, returning null instead of throwing (the RFC says to ignore invalid fields).
     *
     * @param string|list<string>|null $httpValue
     */
    public static function tryParseItem(string|array|null $httpValue): ?Item
    {
        return self::attempt($httpValue, Parser::parseItem(...));
    }

    /**
     * @param string|list<string>|null $httpValue
     */
    public static function tryParseList(string|array|null $httpValue): ?OuterList
    {
        return self::attempt($httpValue, Parser::parseList(...));
    }

    /**
     * @param string|list<string>|null $httpValue
     */
    public static function tryParseDictionary(string|array|null $httpValue): ?Dictionary
    {
        return self::attempt($httpValue, Parser::parseDictionary(...));
    }

    /**
     * Serializes any structured value to its canonical text.
     */
    public static function serialize(StructuredValue $value): string
    {
        return $value->toHttpValue();
    }

    /**
     * @template T
     *
     * @param string|list<string>|null          $httpValue
     * @param callable(string|list<string>): T $parse
     *
     * @return T|null
     */
    private static function attempt(string|array|null $httpValue, callable $parse): mixed
    {
        if ($httpValue === null) {
            return null;
        }
        try {
            return $parse($httpValue);
        } catch (ParseException) {
            return null;
        }
    }
}
