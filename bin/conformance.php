<?php

declare(strict_types=1);

/*
 * Prints a per-file summary of the httpwg/structured-field-tests suite.
 *
 * Usage: php bin/conformance.php
 */

use YilmazMurad\StructField\Tests\Conformance\FixtureRunner;

require __DIR__ . '/../vendor/autoload.php';

$totals = ['pass' => 0, 'fail' => 0];
$byFile = [];
$failures = [];
$suites = [
    '' => FixtureRunner::parseRecords(),
    'serialisation-tests/' => FixtureRunner::serialisationRecords(),
];

foreach ($suites as $prefix => $records) {
    foreach ($records as $label => [$file, $test]) {
        $failure = $prefix === '' ? FixtureRunner::runParse($test) : FixtureRunner::runSerialise($test);
        $key = $prefix . $file;
        $byFile[$key] ??= ['pass' => 0, 'fail' => 0];
        $result = $failure === null ? 'pass' : 'fail';
        ++$byFile[$key][$result];
        ++$totals[$result];
        if ($failure !== null) {
            $failures[] = "$label: $failure";
        }
    }
}

foreach ($byFile as $file => $r) {
    printf("%-42s %4d / %4d\n", $file, $r['pass'], $r['pass'] + $r['fail']);
}
printf("%s\n%-42s %4d / %4d\n", str_repeat('-', 54), 'TOTAL', $totals['pass'], $totals['pass'] + $totals['fail']);

foreach ($failures as $f) {
    fwrite(STDERR, "FAIL $f\n");
}

exit($totals['fail'] === 0 ? 0 : 1);
