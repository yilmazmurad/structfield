<?php

declare(strict_types=1);

namespace YilmazMurad\StructField;

use YilmazMurad\StructField\Exception\InvalidValueException;

/**
 * Internal helpers to validate and serialize Bare Items and Keys.
 *
 * @internal
 */
final class Bare
{
    private function __construct() {}

    /**
     * @throws InvalidValueException
     */
    public static function validate(int|float|string|bool|Token|ByteSequence|Date|DisplayString $value): void
    {
        if (is_int($value)) {
            if ($value > Item::MAX_INTEGER || $value < -Item::MAX_INTEGER) {
                throw new InvalidValueException(sprintf('Integer %d is out of range (±%d).', $value, Item::MAX_INTEGER));
            }
        } elseif (is_float($value)) {
            self::formatDecimal($value);
        } elseif (is_string($value)) {
            if (preg_match('/^[\x20-\x7E]*$/D', $value) !== 1) {
                throw new InvalidValueException(
                    'String may only contain printable ASCII (0x20-0x7E); use DisplayString for Unicode text.',
                );
            }
        }
    }

    /**
     * @throws InvalidValueException
     */
    public static function validateKey(string $key): string
    {
        if (preg_match('/^[a-z*][a-z0-9_\-.*]*$/D', $key) !== 1) {
            throw new InvalidValueException(sprintf('Invalid key "%s".', addcslashes($key, "\0..\37\177..\377")));
        }

        return $key;
    }

    public static function serialize(int|float|string|bool|Token|ByteSequence|Date|DisplayString $value): string
    {
        return match (true) {
            is_int($value) => (string) $value,
            is_float($value) => self::formatDecimal($value),
            is_string($value) => '"' . addcslashes($value, '"\\') . '"',
            is_bool($value) => $value ? '?1' : '?0',
            $value instanceof Token => $value->value,
            $value instanceof ByteSequence => ':' . $value->toBase64() . ':',
            $value instanceof Date => '@' . $value->timestamp,
            $value instanceof DisplayString => '%"' . self::encodeDisplayString($value->value) . '"',
        };
    }

    /**
     * Serializes a Decimal per RFC 9651, Section 4.1.5.
     *
     * The float is first converted to its shortest round-tripping decimal
     * representation, then rounded to three fractional digits using
     * round-half-to-even on those decimal digits (so 0.0025 becomes 0.002
     * regardless of its binary approximation).
     *
     * @throws InvalidValueException
     */
    public static function formatDecimal(float $value): string
    {
        if (!is_finite($value)) {
            throw new InvalidValueException('Decimal must be a finite number.');
        }

        $negative = $value < 0;
        [$int, $frac] = self::splitDecimal(var_export(abs($value), true));

        if (strlen($frac) > 3) {
            $rest = substr($frac, 3);
            $frac = substr($frac, 0, 3);
            $digits = $int . $frac;
            $first = $rest[0];
            $roundUp = $first > '5'
                || ($first === '5' && (rtrim(substr($rest, 1), '0') !== '' || ((int) $digits[-1]) % 2 === 1));
            if ($roundUp) {
                $digits = self::increment($digits);
                $int = substr($digits, 0, -3);
                $frac = substr($digits, -3);
            }
        }

        $int = ltrim($int, '0');
        $int = $int === '' ? '0' : $int;
        $frac = rtrim($frac, '0');
        $frac = $frac === '' ? '0' : $frac;

        if (strlen($int) > 12) {
            throw new InvalidValueException(sprintf('Decimal %s has more than 12 integer digits.', var_export($value, true)));
        }

        return ($negative ? '-' : '') . $int . '.' . $frac;
    }

    /**
     * @return array{string, string} integer digits and fractional digits
     */
    private static function splitDecimal(string $repr): array
    {
        $exponent = 0;
        $ePos = stripos($repr, 'e');
        if ($ePos !== false) {
            $exponent = (int) substr($repr, $ePos + 1);
            $repr = substr($repr, 0, $ePos);
        }
        $dot = strpos($repr, '.');
        $point = ($dot === false ? strlen($repr) : $dot) + $exponent;
        $digits = str_replace('.', '', $repr);
        $len = strlen($digits);

        if ($point <= 0) {
            return ['0', str_repeat('0', -$point) . $digits];
        }
        if ($point >= $len) {
            return [$digits . str_repeat('0', $point - $len), ''];
        }

        return [substr($digits, 0, $point), substr($digits, $point)];
    }

    private static function increment(string $digits): string
    {
        $i = strlen($digits) - 1;
        while ($i >= 0 && $digits[$i] === '9') {
            $digits[$i] = '0';
            --$i;
        }
        if ($i < 0) {
            return '1' . $digits;
        }
        $digits[$i] = (string) ((int) $digits[$i] + 1);

        return $digits;
    }

    private static function encodeDisplayString(string $value): string
    {
        $out = '';
        $len = strlen($value);
        for ($i = 0; $i < $len; ++$i) {
            $c = $value[$i];
            $o = ord($c);
            $out .= ($c === '%' || $c === '"' || $o < 0x20 || $o > 0x7E) ? sprintf('%%%02x', $o) : $c;
        }

        return $out;
    }
}
