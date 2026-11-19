#!/usr/bin/env bash

set -euo pipefail

# usage: assert-line-coverage.sh <clover.xml> <floor percent>
php -- "$1" "$2" <<'PHP'
<?php

$clover     = simplexml_load_file($argv[1]);
$metrics    = $clover->project->metrics;
$covered    = (int) $metrics['coveredstatements'];
$statements = (int) $metrics['statements'];

// Truncated, so a shortfall never prints as the floor itself
printf("Line coverage is %.2f%% (%d of %d lines), the floor %s%%\n", floor(10000 * $covered / max(1, $statements)) / 100, $covered, $statements, $argv[2]);

if ($statements > 0 && 100 * $covered >= (float) $argv[2] * $statements) {
    exit(0);
}

foreach ($clover->xpath('//file') as $file) {
    foreach ($file->line as $line) {
        if ((string) $line['type'] === 'stmt' && (int) $line['count'] === 0) {
            printf("::error file=%s,line=%d::No test covers this line\n", substr((string) $file['name'], strlen(getcwd()) + 1), $line['num']);
        }
    }
}

exit(1);
PHP
