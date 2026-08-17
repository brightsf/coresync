<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

/**
 * Strict streaming reader for the private b2bCRM-produced gallery adoption plan.
 * The semantic identity is the SHA-256 of the canonical uncompressed NDJSON bytes.
 */
class GalleryAdoptionPlanReader
{
    const MAX_COMPRESSED_BYTES = 67108864;
    const MAX_UNCOMPRESSED_BYTES = 268435456;
    const MAX_LINE_BYTES = 32768;
    const MAX_ROWS = 100000;

    /**
     * @param array<string,mixed> $upload Request::files() payload
     * @return array{sha256:string,header:array<string,mixed>,rows:array<int,array<string,mixed>>}
     * @throws GalleryAdoptionException
     */
    public function read(array $upload): array
    {
        $path = isset($upload['tmp_name']) ? (string) $upload['tmp_name'] : '';
        $size = isset($upload['size']) ? (int) $upload['size'] : -1;
        $stat = $path !== '' ? @lstat($path) : false;
        if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || $size <= 0 || $size > self::MAX_COMPRESSED_BYTES
            || !is_array($stat) || is_link($path) || ($stat['mode'] & 0170000) !== 0100000
            || (int) $stat['size'] !== $size || !is_readable($path)) {
            throw new GalleryAdoptionException('Gallery adoption upload is invalid or exceeds the cap.');
        }

        $stream = @gzopen($path, 'rb');
        if ($stream === false) {
            throw new GalleryAdoptionException('Gallery adoption upload is not a readable gzip stream.');
        }

        $hash = hash_init('sha256');
        $bytes = 0;
        $header = null;
        $rows = [];
        try {
            while (!gzeof($stream)) {
                $line = gzgets($stream, self::MAX_LINE_BYTES + 2);
                if ($line === false) {
                    break;
                }
                $length = strlen($line);
                $bytes += $length;
                if ($length < 2 || $length > self::MAX_LINE_BYTES || substr($line, -1) !== "\n"
                    || $bytes > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new GalleryAdoptionException('Gallery adoption NDJSON framing or size is invalid.');
                }
                hash_update($hash, $line);
                $json = substr($line, 0, -1);
                $value = json_decode($json, true);
                if (!is_array($value) || $value === [] || $this->canonical($value) !== $json) {
                    throw new GalleryAdoptionException('Gallery adoption NDJSON is not canonical.');
                }
                if ($header === null) {
                    $this->validateHeader($value);
                    $header = $value;
                    continue;
                }
                if (count($rows) >= self::MAX_ROWS) {
                    throw new GalleryAdoptionException('Gallery adoption row cap was exceeded.');
                }
                $this->validateRow($value);
                $rows[] = $value;
            }
        } finally {
            gzclose($stream);
        }

        if ($header === null || count($rows) !== (int) $header['rows_count']) {
            throw new GalleryAdoptionException('Gallery adoption row count is incomplete.');
        }
        $this->validateSet($rows);

        return ['sha256' => hash_final($hash), 'header' => $header, 'rows' => $rows];
    }

    /** @param array<string,mixed> $header */
    private function validateHeader(array $header): void
    {
        $keys = [
            'format', 'database', 'source_identity', 'media_plan_sha256',
            'conflict_report_sha256', 'checkpoint_sha256', 'snapshot_manifest_sha256', 'rows_count',
        ];
        if (array_keys($header) !== $keys
            || ($header['format'] ?? null) !== 'coresync-gallery-adoption/v1'
            || ($header['database'] ?? null) !== 'b2bcrm_artaz'
            || ($header['source_identity'] ?? null) !== 'okay:artaz'
            || !is_int($header['rows_count'] ?? null)
            || $header['rows_count'] < 1 || $header['rows_count'] > self::MAX_ROWS) {
            throw new GalleryAdoptionException('Gallery adoption header is outside the exact Artaz contract.');
        }
        foreach (['media_plan_sha256', 'conflict_report_sha256', 'checkpoint_sha256', 'snapshot_manifest_sha256'] as $key) {
            if (!is_string($header[$key] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $header[$key]) !== 1) {
                throw new GalleryAdoptionException('Gallery adoption header identity is invalid.');
            }
        }
    }

    /** @param array<string,mixed> $row */
    private function validateRow(array $row): void
    {
        $keys = [
            'product_external_id', 'product_local_id', 'url', 'url_hash', 'sort',
            'image_id', 'filename', 'position', 'size', 'sha256',
        ];
        $external = $row['product_external_id'] ?? null;
        $url = $row['url'] ?? null;
        $filename = $row['filename'] ?? null;
        if (array_keys($row) !== $keys
            || !is_string($external) || $external === '' || strlen($external) > 128
            || preg_match('/[\x00-\x1f\x7f]/', $external) === 1
            || !is_int($row['product_local_id'] ?? null) || $row['product_local_id'] < 1
            || !is_string($url) || strlen($url) > 4096 || strpos($url, 'https://') !== 0
            || !is_string($row['url_hash'] ?? null) || !hash_equals(hash('sha256', $url), $row['url_hash'])
            || !is_int($row['sort'] ?? null) || $row['sort'] < 0
            || !is_int($row['image_id'] ?? null) || $row['image_id'] < 1
            || !is_string($filename) || $filename === '' || strlen($filename) > 255
            || basename($filename) !== $filename || strpos($filename, "\0") !== false
            || !is_int($row['position'] ?? null) || $row['position'] !== $row['sort'] + 1
            || !is_int($row['size'] ?? null) || $row['size'] < 1 || $row['size'] > 1073741824
            || !is_string($row['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $row['sha256']) !== 1) {
            throw new GalleryAdoptionException('Gallery adoption row is invalid.');
        }
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function validateSet(array $rows): void
    {
        $unique = ['product_sort' => [], 'url_hash' => [], 'image_id' => []];
        $sorts = [];
        foreach ($rows as $row) {
            $product = (string) $row['product_external_id'];
            $keys = [
                'product_sort' => $product . ':' . $row['sort'],
                'url_hash' => (string) $row['url_hash'],
                'image_id' => (string) $row['image_id'],
            ];
            foreach ($keys as $kind => $key) {
                if (isset($unique[$kind][$key])) {
                    throw new GalleryAdoptionException('Gallery adoption identity is duplicated.');
                }
                $unique[$kind][$key] = true;
            }
            $sorts[$product][(int) $row['sort']] = true;
        }
        foreach ($sorts as $productSorts) {
            ksort($productSorts);
            if (array_keys($productSorts) !== range(0, count($productSorts) - 1)) {
                throw new GalleryAdoptionException('Gallery adoption product image set is partial.');
            }
        }
    }

    /** @param array<string,mixed> $value */
    private function canonical(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
