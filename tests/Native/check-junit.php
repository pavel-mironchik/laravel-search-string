<?php

$report = simplexml_load_file($argv[1]);
if ($report === false) {
    throw new RuntimeException('Cannot read JUnit report');
}
$cases = $report->xpath('//testcase');
$problems = $report->xpath('//failure | //error | //skipped');
if (count($cases) !== 9 || count($problems) !== 0) {
    throw new RuntimeException('Expected exactly nine native tests without failures, errors or skips');
}
echo "Verified nine native tests without failures, errors or skips.\n";
