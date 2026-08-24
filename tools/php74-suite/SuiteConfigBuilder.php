<?php

namespace CoreSyncPhp74Suite;

use DOMDocument;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class SuiteConfigBuilder
{
    /** @var string */
    private $repoRoot;

    public function __construct(string $repoRoot)
    {
        $resolved = realpath($repoRoot);
        if ($resolved === false) {
            throw new InvalidArgumentException("repository root does not exist: {$repoRoot}");
        }
        $this->repoRoot = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    /**
     * @return array{selected: array<int, string>, skipped: array<int, array{path: string, reason: string}>}
     */
    public function build(string $skipFile, string $configFile): array
    {
        $skipped = $this->readSkipList($skipFile);
        $selected = [];
        $testRoot = $this->repoRoot . '/tests/Modules/Format/CoreSync';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($testRoot, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || substr($file->getFilename(), -8) !== 'Test.php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($this->repoRoot) + 1);
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            if (!isset($skipped[$relative])) {
                $selected[] = $relative;
            }
        }
        sort($selected, SORT_STRING);

        if ($selected === []) {
            throw new RuntimeException('no executable CoreSync test classes remain');
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $phpunit = $document->appendChild($document->createElement('phpunit'));
        $phpunit->setAttribute('cacheResult', 'false');
        $phpunit->setAttribute('colors', 'false');
        $phpunit->setAttribute('convertDeprecationsToExceptions', 'true');
        $phpunit->setAttribute('convertNoticesToExceptions', 'true');
        $phpunit->setAttribute('convertWarningsToExceptions', 'true');
        $testsuites = $phpunit->appendChild($document->createElement('testsuites'));
        $testsuite = $testsuites->appendChild($document->createElement('testsuite'));
        $testsuite->setAttribute('name', 'CoreSync-PHP74');

        foreach ($selected as $relative) {
            $testsuite->appendChild($document->createElement('file', $this->repoRoot . '/' . $relative));
        }

        if ($document->save($configFile) === false) {
            throw new RuntimeException("cannot write generated PHPUnit config: {$configFile}");
        }

        $skipRows = [];
        foreach ($skipped as $path => $reason) {
            $skipRows[] = ['path' => $path, 'reason' => $reason];
        }

        return ['selected' => $selected, 'skipped' => $skipRows];
    }

    /** @return array<string, string> */
    private function readSkipList(string $skipFile): array
    {
        $lines = file($skipFile, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new InvalidArgumentException("cannot read skip-list: {$skipFile}");
        }

        $skipped = [];
        foreach ($lines as $lineNumber => $line) {
            if ($line === '' || strpos(ltrim($line), '#') === 0) {
                continue;
            }
            $parts = explode("\t", $line, 2);
            $path = trim($parts[0]);
            $reason = isset($parts[1]) ? trim($parts[1]) : '';
            if ($reason === '') {
                throw new InvalidArgumentException('skip-list line ' . ($lineNumber + 1) . ' needs path<TAB>reason');
            }
            if (!preg_match('~^tests/Modules/Format/CoreSync/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+Test\.php$~', $path)) {
                throw new InvalidArgumentException('unsafe skip-list path at line ' . ($lineNumber + 1) . ": {$path}");
            }
            if (!is_file($this->repoRoot . '/' . $path)) {
                throw new InvalidArgumentException("stale skip-list path: {$path}");
            }
            if (isset($skipped[$path])) {
                throw new InvalidArgumentException("duplicate skip-list path: {$path}");
            }
            $skipped[$path] = $reason;
        }

        return $skipped;
    }
}
