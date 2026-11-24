<?php

declare(strict_types=1);

namespace YilmazMurad\StructField;

/**
 * The eight Bare Item types defined by RFC 9651, Section 3.3.
 */
enum BareType: string
{
    case Integer = 'integer';
    case Decimal = 'decimal';
    case String = 'string';
    case Token = 'token';
    case ByteSequence = 'byte-sequence';
    case Boolean = 'boolean';
    case Date = 'date';
    case DisplayString = 'display-string';

    /**
     */
    public static function of(int|float|string|bool|Token|ByteSequence|Date|DisplayString $value): self
    {
        return match (true) {
            is_int($value) => self::Integer,
            is_float($value) => self::Decimal,
            is_string($value) => self::String,
            is_bool($value) => self::Boolean,
            $value instanceof Token => self::Token,
            $value instanceof ByteSequence => self::ByteSequence,
            $value instanceof Date => self::Date,
            $value instanceof DisplayString => self::DisplayString,
        };
    }
}
