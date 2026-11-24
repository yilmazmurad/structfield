English | [Türkçe](README.tr.md)

# structfield

[![CI](https://github.com/yilmazmurad/structfield/actions/workflows/ci.yml/badge.svg)](https://github.com/yilmazmurad/structfield/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/php-8.3%20%7C%208.4-777bb4)
![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)
![License](https://img.shields.io/badge/license-MIT-blue)

A zero-dependency PHP 8.3+ implementation of **[RFC 9651 – Structured Field Values for HTTP](https://www.rfc-editor.org/rfc/rfc9651.html)**
(the successor of RFC 8941). Parse and serialize the typed header format used by
`Priority`, `RateLimit`, `Cache-Status`, `Proxy-Status`, `Signature-Input`,
`Accept-CH`, client hints and most new HTTP headers.

It passes **100 % (2,160 / 2,160)** of the official
[httpwg/structured-field-tests](https://github.com/httpwg/structured-field-tests) suite.

```php
use YilmazMurad\StructField\Dictionary;

$priority = Dictionary::fromHttpValue('u=3, i');

$priority->get('u')->value;          // 3
$priority->item('i')?->asBool();      // true
(string) $priority->with('u', 1);     // "u=1, i"
```

## Features

- **Complete RFC 9651 data model** – Items, Lists, Dictionaries, Inner Lists and Parameters.
- **All bare item types** – Integer, Decimal, String, Token, Byte Sequence, Boolean,
  **Date** (`@1659578233`) and **Display String** (`%"f%c3%bc%c3%bc"`).
- **Strict parsing** – any syntax error throws a typed `ParseException` (with offset), because the
  RFC requires the *whole* field to be ignored. `tryParse*()` helpers return `null` instead.
- **Canonical serialization** – including correct round-half-to-even decimal rounding and
  lower-case percent-encoding of Display Strings.
- **Immutable value objects** with fluent `with*()` / `without*()` helpers.
- **Always valid** – invalid values (out-of-range integers, control characters, bad keys…) are
  rejected at construction time with `InvalidValueException`.
- **Typed accessors** – `asInt()`, `asFloat()`, `asString()`, `asToken()`, `asBytes()`, `asBool()`,
  `asDate()`, `asDisplayString()`, `asText()`, plus `Parameters::int()` / `bool()`.
- Multiple field lines are combined as HTTP requires (`parseList(['a', 'b'])`).
- Zero runtime dependencies, PHPStan level **max**, `declare(strict_types=1)` everywhere.

## Installation

```bash
composer require yilmazmurad/structfield
```

> The package is not on Packagist yet. Until then, add the repository as a VCS source:
>
> ```bash
> composer config repositories.structfield vcs https://github.com/yilmazmurad/structfield
> composer require yilmazmurad/structfield:^1.0
> ```

Requires PHP **8.3** or newer. No extensions beyond the PHP core (PCRE) are needed.

## Usage

### Parsing

Choose the parser that matches the header's definition (each header spec says whether it is an
Item, a List or a Dictionary):

```php
use YilmazMurad\StructField\Dictionary;
use YilmazMurad\StructField\Item;
use YilmazMurad\StructField\OuterList;
use YilmazMurad\StructField\StructuredField;

$item = Item::fromHttpValue('"hello";lang=en');
$list = OuterList::fromHttpValue('gzip;q=1.0, br;q=0.8');
$dict = Dictionary::fromHttpValue('u=3, i');

// or via the facade
$dict = StructuredField::parseDictionary('u=3, i');

// several field lines with the same name are combined with ", "
$list = StructuredField::parseList(['gzip', 'br, zstd']);
```

### Priority (RFC 9218)

```php
$priority = Dictionary::fromHttpValue('u=3, i');

$urgency     = $priority->item('u')?->asInt() ?? 3;      // 3
$incremental = $priority->item('i')?->asBool() ?? false; // true
$priority->value('u');                                   // 3 (bare value shortcut)
```

### RateLimit / RateLimit-Policy

```php
$policies = OuterList::fromHttpValue('"burst";q=100;w=60, "daily";q=1000;w=86400');

foreach ($policies as $policy) {
    echo $policy->asString(), ': ', $policy->parameters->int('q'), ' per ', $policy->param('w'), "s\n";
}
// burst: 100 per 60s
// daily: 1000 per 86400s

echo new OuterList([Item::string('burst', ['r' => 42, 't' => 17])]);
// "burst";r=42;t=17
```

### Cache-Status (RFC 9211)

```php
$status = OuterList::fromHttpValue('ExampleCache; hit; ttl=376, OriginCache; fwd=uri-miss; stored');

$edge = $status->get(0);
$edge->asToken();                 // "ExampleCache"
$edge->parameters->bool('hit');   // true
$edge->param('ttl');              // 376

$origin = $status->get(-1);
(string) $origin->param('fwd');   // "uri-miss" (a Token)

echo $status;  // ExampleCache;hit;ttl=376, OriginCache;fwd=uri-miss;stored
```

### Building and serializing

```php
use YilmazMurad\StructField\InnerList;
use YilmazMurad\StructField\Token;

$header = new Dictionary([
    'sig1'    => new InnerList(['@method', '@authority'], ['created' => 1618884473]),
    'created' => Item::date(new DateTimeImmutable('2026-11-24T12:00:00Z')),
    'label'   => Item::displayString('Merhaba dünya'),
    'alg'     => new Token('ed25519'),
    'debug'   => true,
]);

echo $header;
// sig1=("@method" "@authority");created=1618884473, created=@1795521600,
// label=%"Merhaba d%c3%bcnya", alg=ed25519, debug
```

Plain PHP values are wrapped automatically: `int` → Integer, `float` → Decimal, `string` → String,
`bool` → Boolean. Use `Token`, `ByteSequence`, `Date` and `DisplayString` for the other types.

### Error handling

```php
use YilmazMurad\StructField\Exception\ParseException;

try {
    Dictionary::fromHttpValue('u=3, I');       // uppercase keys are invalid
} catch (ParseException $e) {
    $e->getMessage();  // Invalid structured field at offset 5: key must start with lcalpha or "*"
    $e->position;      // 5
}

StructuredField::tryParseDictionary('u=3, I');  // null – ignore the field
StructuredField::tryParseDictionary(null);      // null – header absent
```

| Exception | When |
|---|---|
| `ParseException` | Input is not a valid Structured Field. |
| `InvalidValueException` | A value cannot be represented (e.g. `Item::integer(10**15)`, `new Token('1x')`, `Item::string("a\n")`). |
| `TypeMismatchException` | A typed accessor was called on another type (e.g. `asInt()` on a String). |

All three implement `YilmazMurad\StructField\Exception\StructFieldException`.

## API overview

| Class | Purpose | Key methods |
|---|---|---|
| `StructuredField` | Static facade | `parseItem()`, `parseList()`, `parseDictionary()`, `tryParse*()`, `serialize()` |
| `Item` | Bare value + parameters | `fromHttpValue()`, `integer()`, `decimal()`, `string()`, `token()`, `bytes()`, `boolean()`, `date()`, `displayString()`, `type()`, `is()`, `as*()`, `param()`, `withValue()`, `withParameter()`, `withoutParameter()`, `withParameters()`, `equals()` |
| `OuterList` | RFC "List" (`list` is reserved in PHP) | `fromHttpValue()`, `get()`, `values()`, `contains()`, `append()`, `prepend()`, `remove()` |
| `Dictionary` | Ordered key → member map | `fromHttpValue()`, `get()`, `item()`, `innerList()`, `value()`, `has()`, `with()`, `without()`, `merge()`, `keys()`, `toArray()` |
| `InnerList` | `(a b c);param` | `get()`, `values()`, `append()`, `remove()`, `param()`, `with*Parameter*()` |
| `Parameters` | Ordered `;key=value` map | `get()`, `has()`, `item()`, `int()`, `bool()`, `with()`, `without()`, `merge()`, `keys()`, `toArray()` |
| `Token`, `ByteSequence`, `Date`, `DisplayString` | Bare item wrappers | `->value` / `->bytes` / `->timestamp`, `equals()`, `ByteSequence::fromBase64()`, `Date::fromDateTime()`, `Date::toDateTime()` |
| `BareType` | Enum of the 8 bare types | `BareType::of($value)` |

Every structure implements `StructuredValue` (`toHttpValue()` + `__toString()`); lists, inner lists,
dictionaries and parameters are also `Countable` and `IteratorAggregate`.

Bare values map to PHP as: Integer → `int`, Decimal → `float`, String → `string`,
Boolean → `bool`, Token → `Token`, Byte Sequence → `ByteSequence`, Date → `Date`,
Display String → `DisplayString`.

### Notes

- Empty Lists and Dictionaries serialize to `''`; per the RFC the header should then be omitted.
- Decimals are rounded to 3 fractional digits using round-half-to-even on the float's shortest
  decimal representation (`0.0025` → `0.002`, `9.9995` → `10.0`).
- Dictionary/parameter keys that appear twice keep their first position and last value, as the RFC
  specifies; `with()` behaves the same way.

## Testing

```bash
composer install
composer test        # PHPUnit: unit tests + httpwg conformance suite
composer analyse     # PHPStan, level max
composer lint        # php-cs-fixer (dry run)
composer check       # all of the above
php bin/conformance.php      # per-file conformance summary
php examples/real-headers.php
```

The official test suite is vendored in [`tests/fixtures`](tests/fixtures) (BSD-style license, see
[`tests/fixtures/LICENSE-structured-field-tests.md`](tests/fixtures/LICENSE-structured-field-tests.md));
refresh it with `bin/update-fixtures.sh`.

## License

[MIT](LICENSE) © Murat Yılmaz
