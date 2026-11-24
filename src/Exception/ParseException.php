<?php

declare(strict_types=1);

namespace YilmazMurad\StructField\Exception;

use InvalidArgumentException;

/**
 * Thrown when a field value is not a valid Structured Field.
 *
 * Per RFC 9651, a parse failure invalidates the whole field; callers should
 * ignore the field (or treat it as absent) when this is thrown.
 */
final class ParseException extends InvalidArgumentException implements StructFieldException
{
    public function __construct(
        string $message,
        public readonly string $input = '',
        public readonly int $position = 0,
    ) {
        parent::__construct($message);
    }

    public static function at(string $input, int $position, string $reason): self
    {
        return new self(
            sprintf('Invalid structured field at offset %d: %s', $position, $reason),
            $input,
            $position,
        );
    }
}
