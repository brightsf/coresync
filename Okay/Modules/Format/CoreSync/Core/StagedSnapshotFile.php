<?php

namespace Okay\Modules\Format\CoreSync\Core;

final class StagedSnapshotFile
{
    /** Recheck actual bytes on replay; a remembered verified flag alone is never sufficient. */
    public static function matches(string $path, string $expected, array $file): bool
    {
        if (is_link($path) || !is_file($path)) { return false; }
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
            || (isset($file['bytes']) && $stat['size'] !== (int) $file['bytes'])) {
            return false;
        }
        $actual = @hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) { return false; }
        return ($file['role'] ?? null) !== 'product_document'
            || (self::hasPdfSignature($path) && basename($path) === 'document-' . $expected . '.pdf');
    }

    private static function hasPdfSignature(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            return false;
        }
        $signature = fread($handle, 5);
        fclose($handle);

        return $signature === '%PDF-';
    }
}
