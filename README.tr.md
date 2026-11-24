[English](README.md) | Türkçe

# structfield

[![CI](https://github.com/yilmazmurad/structfield/actions/workflows/ci.yml/badge.svg)](https://github.com/yilmazmurad/structfield/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/php-8.3%20%7C%208.4-777bb4)
![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)
![Lisans](https://img.shields.io/badge/lisans-MIT-blue)

**[RFC 9651 – HTTP için Yapılandırılmış Alan Değerleri (Structured Field Values)](https://www.rfc-editor.org/rfc/rfc9651.html)**
standardının (RFC 8941'in halefi) bağımlılıksız bir PHP 8.3+ uygulaması. `Priority`, `RateLimit`,
`Cache-Status`, `Proxy-Status`, `Signature-Input`, `Accept-CH`, client hint'ler ve yeni HTTP
başlıklarının çoğunun kullandığı tipli başlık formatını ayrıştırır (parse) ve serileştirir.

Resmi [httpwg/structured-field-tests](https://github.com/httpwg/structured-field-tests) test
setinin **%100'ünü (2.160 / 2.160)** geçer.

```php
use YilmazMurad\StructField\Dictionary;

$priority = Dictionary::fromHttpValue('u=3, i');

$priority->get('u')->value;          // 3
$priority->item('i')?->asBool();      // true
(string) $priority->with('u', 1);     // "u=1, i"
```

## Özellikler

- **Eksiksiz RFC 9651 veri modeli** – Item, List, Dictionary, Inner List ve Parameters.
- **Tüm temel (bare) öğe tipleri** – Integer, Decimal, String, Token, Byte Sequence, Boolean,
  **Date** (`@1659578233`) ve **Display String** (`%"f%c3%bc%c3%bc"`).
- **Katı ayrıştırma** – her sözdizimi hatası, konum bilgisiyle tipli bir `ParseException` fırlatır;
  çünkü RFC *tüm* alanın yok sayılmasını şart koşar. `tryParse*()` yardımcıları ise `null` döner.
- **Kanonik serileştirme** – ondalıklarda doğru "yarıyı çifte yuvarla" (round-half-to-even) ve
  Display String'lerde küçük harfli yüzde kodlaması dahil.
- **Değiştirilemez (immutable) değer nesneleri** ve akıcı `with*()` / `without*()` yardımcıları.
- **Her zaman geçerli** – geçersiz değerler (aralık dışı tamsayılar, kontrol karakterleri, hatalı
  anahtarlar…) oluşturma anında `InvalidValueException` ile reddedilir.
- **Tipli erişimciler** – `asInt()`, `asFloat()`, `asString()`, `asToken()`, `asBytes()`, `asBool()`,
  `asDate()`, `asDisplayString()`, `asText()` ve ayrıca `Parameters::int()` / `bool()`.
- Birden fazla başlık satırı HTTP'nin gerektirdiği şekilde birleştirilir (`parseList(['a', 'b'])`).
- Çalışma zamanı bağımlılığı yok, PHPStan **max** seviye, her yerde `declare(strict_types=1)`.

## Kurulum

```bash
composer require yilmazmurad/structfield
```

> Paket henüz Packagist'te değil. O zamana kadar depoyu VCS kaynağı olarak ekleyin:
>
> ```bash
> composer config repositories.structfield vcs https://github.com/yilmazmurad/structfield
> composer require yilmazmurad/structfield:^1.0
> ```

PHP **8.3** veya üstü gerekir. PHP çekirdeği (PCRE) dışında eklenti gerekmez.

## Kullanım

### Ayrıştırma

Başlığın tanımına uyan ayrıştırıcıyı seçin (her başlık spesifikasyonu, değerin Item, List veya
Dictionary olduğunu belirtir):

```php
use YilmazMurad\StructField\Dictionary;
use YilmazMurad\StructField\Item;
use YilmazMurad\StructField\OuterList;
use YilmazMurad\StructField\StructuredField;

$item = Item::fromHttpValue('"merhaba";lang=tr');
$list = OuterList::fromHttpValue('gzip;q=1.0, br;q=0.8');
$dict = Dictionary::fromHttpValue('u=3, i');

// veya facade üzerinden
$dict = StructuredField::parseDictionary('u=3, i');

// aynı isimli birden fazla başlık satırı ", " ile birleştirilir
$list = StructuredField::parseList(['gzip', 'br, zstd']);
```

### Priority (RFC 9218)

```php
$priority = Dictionary::fromHttpValue('u=3, i');

$urgency     = $priority->item('u')?->asInt() ?? 3;      // 3
$incremental = $priority->item('i')?->asBool() ?? false; // true
$priority->value('u');                                   // 3 (bare değer kısayolu)
```

### RateLimit / RateLimit-Policy

```php
$policies = OuterList::fromHttpValue('"burst";q=100;w=60, "daily";q=1000;w=86400');

foreach ($policies as $policy) {
    echo $policy->asString(), ': ', $policy->parameters->int('q'), ' / ', $policy->param('w'), "sn\n";
}
// burst: 100 / 60sn
// daily: 1000 / 86400sn

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
(string) $origin->param('fwd');   // "uri-miss" (bir Token)

echo $status;  // ExampleCache;hit;ttl=376, OriginCache;fwd=uri-miss;stored
```

### Oluşturma ve serileştirme

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

Düz PHP değerleri otomatik sarılır: `int` → Integer, `float` → Decimal, `string` → String,
`bool` → Boolean. Diğer tipler için `Token`, `ByteSequence`, `Date` ve `DisplayString` kullanın.

### Hata yönetimi

```php
use YilmazMurad\StructField\Exception\ParseException;

try {
    Dictionary::fromHttpValue('u=3, I');       // büyük harfli anahtar geçersizdir
} catch (ParseException $e) {
    $e->getMessage();  // Invalid structured field at offset 5: key must start with lcalpha or "*"
    $e->position;      // 5
}

StructuredField::tryParseDictionary('u=3, I');  // null – alanı yok say
StructuredField::tryParseDictionary(null);      // null – başlık yok
```

| İstisna | Ne zaman |
|---|---|
| `ParseException` | Girdi geçerli bir Structured Field değil. |
| `InvalidValueException` | Değer temsil edilemiyor (ör. `Item::integer(10**15)`, `new Token('1x')`, `Item::string("a\n")`). |
| `TypeMismatchException` | Tipli bir erişimci başka bir tip üzerinde çağrıldı (ör. String üzerinde `asInt()`). |

Üçü de `YilmazMurad\StructField\Exception\StructFieldException` arayüzünü uygular.

## API özeti

| Sınıf | Amaç | Önemli metotlar |
|---|---|---|
| `StructuredField` | Statik facade | `parseItem()`, `parseList()`, `parseDictionary()`, `tryParse*()`, `serialize()` |
| `Item` | Bare değer + parametreler | `fromHttpValue()`, `integer()`, `decimal()`, `string()`, `token()`, `bytes()`, `boolean()`, `date()`, `displayString()`, `type()`, `is()`, `as*()`, `param()`, `withValue()`, `withParameter()`, `withoutParameter()`, `withParameters()`, `equals()` |
| `OuterList` | RFC'deki "List" (`list` PHP'de ayrılmış kelime) | `fromHttpValue()`, `get()`, `values()`, `contains()`, `append()`, `prepend()`, `remove()` |
| `Dictionary` | Sıralı anahtar → üye eşlemesi | `fromHttpValue()`, `get()`, `item()`, `innerList()`, `value()`, `has()`, `with()`, `without()`, `merge()`, `keys()`, `toArray()` |
| `InnerList` | `(a b c);param` | `get()`, `values()`, `append()`, `remove()`, `param()`, `with*Parameter*()` |
| `Parameters` | Sıralı `;anahtar=değer` eşlemesi | `get()`, `has()`, `item()`, `int()`, `bool()`, `with()`, `without()`, `merge()`, `keys()`, `toArray()` |
| `Token`, `ByteSequence`, `Date`, `DisplayString` | Bare öğe sarmalayıcıları | `->value` / `->bytes` / `->timestamp`, `equals()`, `ByteSequence::fromBase64()`, `Date::fromDateTime()`, `Date::toDateTime()` |
| `BareType` | 8 bare tipin enum'u | `BareType::of($value)` |

Tüm yapılar `StructuredValue` arayüzünü (`toHttpValue()` + `__toString()`) uygular; listeler, iç
listeler, sözlükler ve parametreler ayrıca `Countable` ve `IteratorAggregate`'tir.

Bare değerlerin PHP karşılıkları: Integer → `int`, Decimal → `float`, String → `string`,
Boolean → `bool`, Token → `Token`, Byte Sequence → `ByteSequence`, Date → `Date`,
Display String → `DisplayString`.

### Notlar

- Boş List ve Dictionary `''` olarak serileştirilir; RFC'ye göre bu durumda başlık hiç gönderilmemelidir.
- Ondalıklar, float'ın en kısa ondalık gösterimi üzerinde round-half-to-even ile 3 kesir basamağına
  yuvarlanır (`0.0025` → `0.002`, `9.9995` → `10.0`).
- İki kez geçen sözlük/parametre anahtarları, RFC'nin belirttiği gibi ilk konumunu ve son değerini
  korur; `with()` da aynı şekilde davranır.

## Test

```bash
composer install
composer test        # PHPUnit: birim testleri + httpwg uyumluluk test seti
composer analyse     # PHPStan, max seviye
composer lint        # php-cs-fixer (dry run)
composer check       # hepsi birden
php bin/conformance.php      # dosya bazında uyumluluk özeti
php examples/real-headers.php
```

Resmi test seti [`tests/fixtures`](tests/fixtures) dizinine dahil edilmiştir (BSD tarzı lisans,
bkz. [`tests/fixtures/LICENSE-structured-field-tests.md`](tests/fixtures/LICENSE-structured-field-tests.md));
`bin/update-fixtures.sh` ile güncelleyebilirsiniz.

## Lisans

[MIT](LICENSE) © Murat Yılmaz
