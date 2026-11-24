<?php

declare(strict_types=1);

namespace YilmazMurad\StructField\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YilmazMurad\StructField\BareType;
use YilmazMurad\StructField\ByteSequence;
use YilmazMurad\StructField\Dictionary;
use YilmazMurad\StructField\DisplayString;
use YilmazMurad\StructField\InnerList;
use YilmazMurad\StructField\Item;
use YilmazMurad\StructField\OuterList;
use YilmazMurad\StructField\StructuredField;
use YilmazMurad\StructField\Token;

final class RealHeadersTest extends TestCase
{
    public function testPriorityHeader(): void
    {
        // RFC 9218: Priority: u=3, i
        $priority = Dictionary::fromHttpValue('u=3, i');

        self::assertSame(3, $priority->item('u')?->value);
        self::assertSame(3, $priority->value('u'));
        self::assertTrue($priority->item('i')?->asBool());
        self::assertSame(7, $priority->item('missing')?->asInt() ?? 7);
        self::assertSame('u=3, i', $priority->toHttpValue());

        $updated = $priority->with('u', 1)->without('i');
        self::assertSame('u=1', (string) $updated);
        self::assertSame('u=3, i', (string) $priority, 'original must be unchanged');
    }

    public function testRateLimitHeaders(): void
    {
        // draft-ietf-httpapi-ratelimit-headers
        $policy = OuterList::fromHttpValue('"burst";q=100;w=60, "daily";q=1000;w=86400;pk=:Y2xpZW50LTE=:');
        self::assertCount(2, $policy);
        $first = $policy->get(0);
        self::assertInstanceOf(Item::class, $first);
        self::assertSame('burst', $first->asString());
        self::assertSame(100, $first->parameters->int('q'));
        self::assertSame(60, $first->param('w'));
        $daily = $policy->get(1);
        self::assertInstanceOf(Item::class, $daily);
        self::assertInstanceOf(ByteSequence::class, $daily->param('pk'));
        self::assertSame('client-1', $daily->param('pk')->bytes);
        self::assertNull(StructuredField::tryParseDictionary('"burst";q=100'), 'quoted strings are not dictionary keys');

        $limit = OuterList::fromHttpValue('"default";r=50;t=30');
        $item = $limit->get(0);
        self::assertInstanceOf(Item::class, $item);
        self::assertSame(50, $item->parameters->int('r'));
        self::assertSame(30, $item->parameters->int('t'));

        $built = new OuterList([Item::string('default', ['r' => 0, 't' => 12])]);
        self::assertSame('"default";r=0;t=12', $built->toHttpValue());
    }

    public function testCacheStatusHeader(): void
    {
        // RFC 9211
        $status = OuterList::fromHttpValue('ExampleCache; hit, OriginCache; fwd=uri-miss; stored');
        self::assertCount(2, $status);

        $edge = $status->get(0);
        $origin = $status->get(-1);
        self::assertInstanceOf(Item::class, $edge);
        self::assertInstanceOf(Item::class, $origin);
        self::assertSame('ExampleCache', $edge->asToken());
        self::assertTrue($edge->parameters->bool('hit'));
        self::assertInstanceOf(Token::class, $origin->param('fwd'));
        self::assertSame('uri-miss', (string) $origin->param('fwd'));
        self::assertTrue($origin->param('stored'));
        self::assertSame('ExampleCache;hit, OriginCache;fwd=uri-miss;stored', $status->toHttpValue());
    }

    public function testAcceptChLikeListAndContains(): void
    {
        $list = OuterList::fromHttpValue('Sec-CH-UA-Model, Sec-CH-UA-Platform-Version');
        self::assertTrue($list->contains(new Token('Sec-CH-UA-Model')));
        self::assertFalse($list->contains('Sec-CH-UA-Model'), 'a String is not a Token');
    }

    public function testSignatureInputLikeDictionaryWithInnerList(): void
    {
        // RFC 9421
        $header = 'sig1=("@method" "@authority" "content-digest");created=1618884473;keyid="test-key-rsa-pss"';
        $dict = StructuredField::parseDictionary($header);
        $sig = $dict->innerList('sig1');

        self::assertInstanceOf(InnerList::class, $sig);
        self::assertSame(['@method', '@authority', 'content-digest'], $sig->values());
        self::assertSame(1618884473, $sig->param('created'));
        self::assertSame('test-key-rsa-pss', $sig->parameters->get('keyid'));
        self::assertNull($dict->item('sig1'));
        self::assertSame($header, $dict->toHttpValue());
    }

    public function testProxyStatusAndDates(): void
    {
        $item = Item::fromHttpValue('@1659578233;src=%"f%c3%bc%c3%bc"');
        self::assertSame(BareType::Date, $item->type());
        self::assertSame('2022-08-04T01:57:13+00:00', $item->asDate()->format(DATE_ATOM));
        $src = $item->param('src');
        self::assertInstanceOf(DisplayString::class, $src);
        self::assertSame('füü', $src->value);
    }
}
