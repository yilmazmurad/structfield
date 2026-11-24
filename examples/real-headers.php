<?php

declare(strict_types=1);

/*
 * Run: php examples/real-headers.php
 */

use YilmazMurad\StructField\Dictionary;
use YilmazMurad\StructField\Item;
use YilmazMurad\StructField\OuterList;
use YilmazMurad\StructField\StructuredField;
use YilmazMurad\StructField\Token;

require __DIR__ . '/../vendor/autoload.php';

// --- Priority (RFC 9218) ----------------------------------------------------
$priority = Dictionary::fromHttpValue('u=3, i');
$urgency = $priority->item('u')?->asInt() ?? 3;          // default urgency is 3
$incremental = $priority->item('i')?->asBool() ?? false;
printf("Priority: urgency=%d incremental=%s\n", $urgency, $incremental ? 'yes' : 'no');
printf("  re-serialized with u=1: %s\n", $priority->with('u', 1));

// --- RateLimit / RateLimit-Policy (draft-ietf-httpapi-ratelimit-headers) -----
$policies = OuterList::fromHttpValue('"burst";q=100;w=60, "daily";q=1000;w=86400');
foreach ($policies as $policy) {
    if ($policy instanceof Item) {
        printf("RateLimit-Policy %s: quota=%d window=%ds\n", $policy->asString(), $policy->parameters->int('q'), $policy->parameters->int('w'));
    }
}
$rateLimit = new OuterList([Item::string('burst', ['r' => 42, 't' => 17])]);
printf("RateLimit: %s\n", $rateLimit);

// --- Cache-Status (RFC 9211) -------------------------------------------------
$cacheStatus = OuterList::fromHttpValue('ExampleCache; hit; ttl=376, OriginCache; fwd=uri-miss; stored');
foreach ($cacheStatus as $cache) {
    if (!$cache instanceof Item) {
        continue;
    }
    $fwd = $cache->param('fwd');
    $outcome = $cache->parameters->bool('hit') ? 'hit' : 'forward (' . ($fwd instanceof Token ? $fwd->value : '?') . ')';
    printf("Cache-Status %s: %s\n", $cache->asToken(), $outcome);
}

// --- Building a header from scratch -----------------------------------------
$header = new Dictionary([
    'sig1' => Item::bytes(random_bytes(8)),
    'created' => Item::date(new DateTimeImmutable('2026-11-24T12:00:00Z')),
    'label' => Item::displayString('Merhaba dünya'),
    'alg' => new Token('ed25519'),
]);
printf("Built: %s\n", $header);

// --- Invalid input: the whole field must be ignored --------------------------
var_dump(StructuredField::tryParseDictionary('u=3, I'));   // NULL (uppercase key is invalid)
