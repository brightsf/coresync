<?php

namespace Okay\Modules\Format\CoreSync\Core\Preview;

use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use Okay\Modules\Format\CoreSync\Core\ManifestValidator;
use Okay\Modules\Format\CoreSync\Core\StagedSnapshotFile;

final class OfflineFullPackage
{
    private $directory;
    private $manifest;
    private $digest;
    private $missingDocuments;

    private function __construct(string $directory, array $manifest, string $digest, array $missingDocuments)
    {
        $this->directory = $directory;
        $this->manifest = $manifest;
        $this->digest = $digest;
        $this->missingDocuments = $missingDocuments;
    }

    public static function open(string $directory, ManifestValidator $validator): self
    {
        $directory = rtrim($directory, '/');
        if ($directory === '' || $directory[0] !== '/' || strpos($directory, "\0") !== false
            || is_link($directory) || !is_dir($directory)) {
            throw new ManifestException('Offline full requires a local package directory');
        }
        $manifestPath = $directory . '/manifest.json';
        if (is_link($manifestPath) || !is_file($manifestPath)) {
            throw new ManifestException('Offline full requires a local manifest');
        }
        $raw = file_get_contents($manifestPath);
        if (!is_string($raw)) {
            throw new ManifestException('Offline full could not read the manifest');
        }
        $manifest = $validator->validate($validator->parse($raw));
        if (($manifest['sync_mode'] ?? null) !== Contract::SYNC_MODE_FULL) {
            throw new ManifestException('Offline preview accepts only a full manifest');
        }

        $files = [];
        $missing = [];
        foreach ($manifest['files'] as $file) {
            $name = $file['name'] ?? null;
            $sha = $file['sha256'] ?? null;
            if (!is_string($name) || !preg_match('/^[a-z0-9][a-z0-9.-]*$/D', $name)
                || isset($files[$name]) || !is_string($sha) || !preg_match('/^[a-f0-9]{64}$/D', $sha)
                || !is_int($file['bytes'] ?? null) || $file['bytes'] < 0) {
                throw new ManifestException('Offline full contains an invalid or duplicate file record');
            }
            $files[$name] = true;
            $path = $directory . '/' . $name;
            if (($file['role'] ?? null) === 'product_document' && !file_exists($path) && !is_link($path)) {
                $missing[$name] = $sha;
                continue;
            }
            if (!StagedSnapshotFile::matches($path, $sha, $file)) {
                throw new ManifestException('Offline package file digest, length or type differs from manifest: ' . $name);
            }
        }

        return new self($directory, $manifest, hash('sha256', $raw), $missing);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function manifest(): array
    {
        return $this->manifest;
    }

    public function manifestDigest(): string
    {
        return $this->digest;
    }

    /** @return array<string, string> */
    public function missingDocuments(): array
    {
        return $this->missingDocuments;
    }
}
