<?php

if ($argc !== 4) {
    fwrite(STDERR, "usage: report.php <junit.xml> <expected-classes> <skip-count>\n");
    exit(2);
}

$junit = $argv[1];
$expectedClasses = filter_var($argv[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$skipCount = filter_var($argv[3], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

if ($expectedClasses === false || $skipCount === false || !is_file($junit)) {
    fwrite(STDERR, "php74-suite: invalid report inputs\n");
    exit(2);
}

$document = new DOMDocument();
if (!$document->load($junit)) {
    fwrite(STDERR, "php74-suite: cannot parse JUnit report {$junit}\n");
    exit(2);
}

$xpath = new DOMXPath($document);
$classes = [];
$tests = 0;
$unexpectedSkippedTests = 0;

foreach ($xpath->query('//testcase') as $testcase) {
    $class = $testcase->attributes->getNamedItem('class');
    if ($class !== null && $class->nodeValue !== '') {
        $classes[$class->nodeValue] = true;
    }
    $tests++;
    if ($xpath->query('./skipped', $testcase)->length > 0) {
        $unexpectedSkippedTests++;
    }
}

$executedClasses = count($classes);
if ($executedClasses !== $expectedClasses) {
    fwrite(
        STDERR,
        "php74-suite: executed {$executedClasses} classes, expected {$expectedClasses}; JUnit coverage is incomplete\n"
    );
    exit(1);
}

if ($unexpectedSkippedTests !== 0) {
    fwrite(STDERR, "php74-suite: PHPUnit skipped {$unexpectedSkippedTests} test methods outside skip-list.txt\n");
    exit(1);
}

printf(
    "CORESYNC-SUITE runtime=%s classes_executed=%d classes_skipped=%d tests=%d unexpected_skips=0\n",
    PHP_VERSION,
    $executedClasses,
    $skipCount,
    $tests
);
