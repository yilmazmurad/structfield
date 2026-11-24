<?php

declare(strict_types=1);

namespace YilmazMurad\StructField;

use YilmazMurad\StructField\Exception\InvalidValueException;
use YilmazMurad\StructField\Exception\ParseException;

/**
 * Strict RFC 9651 parser (Section 4.2).
 *
 * Any deviation from the grammar throws a ParseException; per the RFC the
 * entire field must then be ignored.
 *
 * @phpstan-import-type BareValue from Parameters
 */
final class Parser
{
    private int $pos = 0;

    private readonly int $len;

    private function __construct(private readonly string $input)
    {
        $this->len = strlen($input);
    }

    /**
     * @param string|list<string> $httpValue
     *
     * @throws ParseException
     */
    public static function parseItem(string|array $httpValue): Item
    {
        return self::run($httpValue, static fn(self $p): Item => $p->finish($p->item()));
    }

    /**
     * @param string|list<string> $httpValue
     *
     * @throws ParseException
     */
    public static function parseList(string|array $httpValue): OuterList
    {
        return self::run($httpValue, static fn(self $p): OuterList => $p->finish(new OuterList($p->list())));
    }

    /**
     * @param string|list<string> $httpValue
     *
     * @throws ParseException
     */
    public static function parseDictionary(string|array $httpValue): Dictionary
    {
        return self::run($httpValue, static fn(self $p): Dictionary => $p->finish(new Dictionary($p->dictionary())));
    }

    /**
     * @template T
     *
     * @param string|list<string> $httpValue
     * @param callable(self): T   $parse
     *
     * @return T
     */
    private static function run(string|array $httpValue, callable $parse): mixed
    {
        $parser = self::create($httpValue);
        try {
            return $parse($parser);
        } catch (InvalidValueException $e) {
            throw ParseException::at($parser->input, $parser->pos, $e->getMessage());
        }
    }

    /**
     * @param string|list<string> $httpValue
     */
    private static function create(string|array $httpValue): self
    {
        // RFC 9110, Section 5.3: multiple field lines are combined with ", ".
        $input = is_array($httpValue) ? implode(', ', $httpValue) : $httpValue;

        if (preg_match('/[^\x00-\x7F]/', $input) === 1) {
            throw new ParseException('Structured field values must be ASCII.', $input);
        }

        $parser = new self($input);
        $parser->skipSp();

        return $parser;
    }

    /**
     * @template T
     *
     * @param T $value
     *
     * @return T
     */
    private function finish(mixed $value): mixed
    {
        $this->skipSp();
        if ($this->pos < $this->len) {
            throw $this->error('unexpected trailing characters');
        }

        return $value;
    }

    // ---- top-level structures ------------------------------------------------

    /**
     * @return list<Item|InnerList>
     */
    private function list(): array
    {
        $members = [];
        while ($this->pos < $this->len) {
            $members[] = $this->itemOrInnerList();
            $this->skipOws();
            if ($this->pos >= $this->len) {
                return $members;
            }
            if ($this->input[$this->pos] !== ',') {
                throw $this->error('expected "," between list members');
            }
            ++$this->pos;
            $this->skipOws();
            if ($this->pos >= $this->len) {
                throw $this->error('trailing comma in list');
            }
        }

        return $members;
    }

    /**
     * @return array<string, Item|InnerList>
     */
    private function dictionary(): array
    {
        $members = [];
        while ($this->pos < $this->len) {
            $key = $this->key();
            if ($this->pos < $this->len && $this->input[$this->pos] === '=') {
                ++$this->pos;
                $members[$key] = $this->itemOrInnerList();
            } else {
                $members[$key] = new Item(true, $this->parameters());
            }
            $this->skipOws();
            if ($this->pos >= $this->len) {
                return $members;
            }
            if ($this->input[$this->pos] !== ',') {
                throw $this->error('expected "," between dictionary members');
            }
            ++$this->pos;
            $this->skipOws();
            if ($this->pos >= $this->len) {
                throw $this->error('trailing comma in dictionary');
            }
        }

        return $members;
    }

    private function itemOrInnerList(): Item|InnerList
    {
        return ($this->input[$this->pos] ?? '') === '(' ? $this->innerList() : $this->item();
    }

    private function innerList(): InnerList
    {
        ++$this->pos; // "("
        $items = [];
        while ($this->pos < $this->len) {
            $this->skipSp();
            if ($this->pos < $this->len && $this->input[$this->pos] === ')') {
                ++$this->pos;

                return new InnerList($items, $this->parameters());
            }
            $items[] = $this->item();
            $c = $this->input[$this->pos] ?? '';
            if ($c !== ' ' && $c !== ')') {
                throw $this->error('expected SP or ")" in inner list');
            }
        }

        throw $this->error('unterminated inner list');
    }

    private function item(): Item
    {
        $value = $this->bareItem();

        return new Item($value, $this->parameters());
    }

    /**
     * @return array<string, BareValue>
     */
    private function parameters(): array
    {
        $params = [];
        while ($this->pos < $this->len && $this->input[$this->pos] === ';') {
            ++$this->pos;
            $this->skipSp();
            $key = $this->key();
            $value = true;
            if ($this->pos < $this->len && $this->input[$this->pos] === '=') {
                ++$this->pos;
                $value = $this->bareItem();
            }
            $params[$key] = $value;
        }

        return $params;
    }

    private function key(): string
    {
        $c = $this->input[$this->pos] ?? '';
        if ($c !== '*' && ($c < 'a' || $c > 'z')) {
            throw $this->error('key must start with lcalpha or "*"');
        }
        $length = strspn($this->input, 'abcdefghijklmnopqrstuvwxyz0123456789_-.*', $this->pos);
        $key = substr($this->input, $this->pos, $length);
        $this->pos += $length;

        return $key;
    }

    // ---- bare items ------------------------------------------------------------

    /**
     * @return BareValue
     */
    private function bareItem(): int|float|string|bool|Token|ByteSequence|Date|DisplayString
    {
        $c = $this->input[$this->pos] ?? '';

        return match (true) {
            $c === '-' || ($c >= '0' && $c <= '9' && $c !== '') => $this->number(),
            $c === '"' => $this->string(),
            $c === '*' || ($c !== '' && ctype_alpha($c)) => $this->token(),
            $c === ':' => $this->byteSequence(),
            $c === '?' => $this->boolean(),
            $c === '@' => $this->date(),
            $c === '%' => $this->displayString(),
            default => throw $this->error('unrecognised bare item'),
        };
    }

    private function number(): int|float
    {
        $start = $this->pos;
        $negative = false;
        if ($this->input[$this->pos] === '-') {
            $negative = true;
            ++$this->pos;
        }
        $intLen = strspn($this->input, '0123456789', $this->pos);
        if ($intLen === 0) {
            throw $this->error('expected digit');
        }
        $intPart = substr($this->input, $this->pos, $intLen);
        $this->pos += $intLen;

        if ($this->pos < $this->len && $this->input[$this->pos] === '.') {
            if ($intLen > 12) {
                throw $this->error('decimal has more than 12 integer digits');
            }
            ++$this->pos;
            $fracLen = strspn($this->input, '0123456789', $this->pos);
            if ($fracLen === 0) {
                throw $this->error('decimal must have fractional digits');
            }
            if ($fracLen > 3) {
                throw $this->error('decimal has more than 3 fractional digits');
            }
            $this->pos += $fracLen;

            return (float) substr($this->input, $start, $this->pos - $start);
        }

        if ($intLen > 15) {
            throw $this->error('integer has more than 15 digits');
        }
        $value = (int) $intPart;

        return $negative ? -$value : $value;
    }

    private function string(): string
    {
        ++$this->pos; // '"'
        $out = '';
        while ($this->pos < $this->len) {
            $run = strcspn($this->input, "\"\\\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f"
                . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f", $this->pos);
            $out .= substr($this->input, $this->pos, $run);
            $this->pos += $run;
            if ($this->pos >= $this->len) {
                break;
            }
            $c = $this->input[$this->pos++];
            if ($c === '"') {
                return $out;
            }
            if ($c !== '\\') {
                throw $this->error('invalid character in string', $this->pos - 1);
            }
            $next = $this->input[$this->pos] ?? '';
            if ($next !== '"' && $next !== '\\') {
                throw $this->error('invalid escape in string');
            }
            $out .= $next;
            ++$this->pos;
        }

        throw $this->error('unterminated string');
    }

    private function token(): Token
    {
        $length = strspn(
            $this->input,
            "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!#$%&'*+-.^_`|~:/",
            $this->pos,
        );
        $token = substr($this->input, $this->pos, $length);
        $this->pos += $length;

        return new Token($token);
    }

    private function byteSequence(): ByteSequence
    {
        ++$this->pos; // ':'
        $end = strpos($this->input, ':', $this->pos);
        if ($end === false) {
            throw $this->error('unterminated byte sequence');
        }
        $encoded = substr($this->input, $this->pos, $end - $this->pos);
        if (preg_match('/^[A-Za-z0-9+\/]*={0,2}$/D', $encoded) !== 1) {
            throw $this->error('invalid base64 in byte sequence');
        }
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw $this->error('invalid base64 in byte sequence');
        }
        $this->pos = $end + 1;

        return new ByteSequence($decoded);
    }

    private function boolean(): bool
    {
        ++$this->pos; // '?'
        $c = $this->input[$this->pos] ?? '';
        if ($c !== '0' && $c !== '1') {
            throw $this->error('boolean must be ?0 or ?1');
        }
        ++$this->pos;

        return $c === '1';
    }

    private function date(): Date
    {
        ++$this->pos; // '@'
        if ($this->pos >= $this->len) {
            throw $this->error('expected integer after "@"');
        }
        $c = $this->input[$this->pos];
        if ($c !== '-' && !ctype_digit($c)) {
            throw $this->error('expected integer after "@"');
        }
        $value = $this->number();
        if (!is_int($value)) {
            throw $this->error('date must be an integer');
        }

        return new Date($value);
    }

    private function displayString(): DisplayString
    {
        ++$this->pos; // '%'
        if (($this->input[$this->pos] ?? '') !== '"') {
            throw $this->error('expected \'"\' after "%"');
        }
        ++$this->pos;
        $bytes = '';
        while ($this->pos < $this->len) {
            $c = $this->input[$this->pos];
            $o = ord($c);
            if ($o < 0x20 || $o > 0x7E) {
                throw $this->error('invalid character in display string');
            }
            ++$this->pos;
            if ($c === '%') {
                $hex = substr($this->input, $this->pos, 2);
                if (strlen($hex) !== 2 || strspn($hex, '0123456789abcdef') !== 2) {
                    throw $this->error('invalid percent-encoding in display string (must be lowercase hex)');
                }
                $bytes .= chr((int) hexdec($hex));
                $this->pos += 2;
            } elseif ($c === '"') {
                if (preg_match('//u', $bytes) !== 1) {
                    throw $this->error('display string is not valid UTF-8');
                }

                return new DisplayString($bytes);
            } else {
                $bytes .= $c;
            }
        }

        throw $this->error('unterminated display string');
    }

    // ---- helpers -------------------------------------------------------------

    private function skipSp(): void
    {
        $this->pos += strspn($this->input, ' ', $this->pos);
    }

    private function skipOws(): void
    {
        $this->pos += strspn($this->input, " \t", $this->pos);
    }

    private function error(string $reason, ?int $position = null): ParseException
    {
        return ParseException::at($this->input, $position ?? $this->pos, $reason);
    }
}
