<?php

declare(strict_types=1);

namespace YilmazMurad\StructField;

use YilmazMurad\StructField\Exception\InvalidValueException;

/**
 * A Byte Sequence (RFC 9651, Section 3.3.5): arbitrary binary data, serialized as `:base64:`.
 */
final readonly class ByteSequence
{
    /**
     * @param string $bytes The raw (decoded) bytes.
     */
    public function __construct(public string $bytes) {}

    public static function fromBase64(string $encoded): self
    {
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw new InvalidValueException('Invalid base64 data.');
        }

        return new self($decoded);
    }

    public function toBase64(): string
    {
        return base64_encode($this->bytes);
    }

    public function equals(self $other): bool
    {
        return $this->bytes === $other->bytes;
    }
}
