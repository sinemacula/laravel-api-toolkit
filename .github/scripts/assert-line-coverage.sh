#!/usr/bin/env bash

set -euo pipefail

# usage: assert-line-coverage.sh <clover.xml> <floor percent>
php -- "$1" "$2" <<'PHP'
<?php

$clover  = simplexml_load_file($argv[1]);
$metrics = $clover->project->metrics;
$percent = 100 * (int) $metrics['coveredstatements'] / max(1, (int) $metrics['statements']);

printf("Line coverage is %.2f%%, the floor %s%%\n", $percent, $argv[2]);

if ($percent >= (float) $argv[2]) {
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
