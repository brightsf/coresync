<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

/**
 * Strict streaming reader for the private b2bCRM-produced gallery adoption plan.
 * The semantic identity is the SHA-256 of the canonical uncompressed NDJSON bytes.
 *
 * WHAT A ROW SAYS ABOUT ORDER (measured 2026-08-18, both sides read).
 *
 * `sort` is the producer's rank of the image inside its product: `media.order_column`, a closed
 * 1..N run per product. `position` is the storefront's own `ok_images.position` of the row this
 * plan adopts, carried through the artifact verbatim — it is customer data, not a rank, and the
 * producer says so in as many words (b2bCRM `GalleryAdoptionPlanBuilder`: "Storefront `position`
 * is carried verbatim, never equated with the rank; ordering is the invariant").
 *
 * Until now this reader demanded `position === sort + 1` of every row and a 0-based rank run,
 * neither of which the producer emits. On the live `artaz` mirror that refusal was total: of
 * 59565 visible `ok_images` rows, 9332 carry `position = 0`, 6005 carry `position = id` and 6016
 * carry `position > 100`; the reader failed on the first row of the first product and dropped the
 * whole plan. The equality is gone and `position` is now read as what it is — an opaque
 * non-negative storefront integer. Ordering did not become unchecked: it lives, as it always did,
 * in the rank run asserted per product by {@see self::validateSet()}, re-based to the 1..N the
 * producer actually writes (and that this module's own apply path already stores: `Applier` takes
 * durable `sort` straight from the snapshot `sort`, which is `order_column`).
 */
class GalleryAdoptionPlanReader
{
    /**
     * The plan format this module reads, written down once and mirrored from the producer's own
     * single source (b2bCRM `GalleryAdoptionPlan::FORMAT`). v1 was refused by this very reader
     * because the header grew the excluded-product pair without a bump; no v1 artifact was ever
     * produced, so v2 is the first shape that has a consumer at all.
     */
    const FORMAT = 'coresync-gallery-adoption/v2';

    const MAX_COMPRESSED_BYTES = 67108864;
    const MAX_UNCOMPRESSED_BYTES = 268435456;
    const MAX_HEADER_LINE_BYTES = 4194304;
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
                $lineCap = $header === null ? self::MAX_HEADER_LINE_BYTES : self::MAX_LINE_BYTES;
                $line = gzgets($stream, $lineCap + 2);
                if ($line === false) {
                    break;
                }
                $length = strlen($line);
                $bytes += $length;
                // Same refusal, honest diagnosis. The ONE header line carries the whole excluded
                // products list, so it is the only line that grows with the campaign rather than
                // with one image. Read as generic
                // "framing" the operator looks for a broken artifact that is not broken.
                if ($header === null && $length > self::MAX_HEADER_LINE_BYTES) {
                    throw new GalleryAdoptionException(
                        'Gallery adoption header line exceeds ' . self::MAX_HEADER_LINE_BYTES
                        . ' bytes: its excluded products list does not fit one NDJSON line.'
                    );
                }
                if ($length < 2 || $length > $lineCap || substr($line, -1) !== "\n"
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

    /**
     * The v2 header, key for key in the producer's own order: `format`, the builder metadata, then
     * `rows_count` last. `excluded_products` names the products b2bCRM refused to adopt completely
     * and must agree with its own declared count — this module does not act on the list, but an
     * exclusion the operator never saw is exactly the silent degradation the producer refuses to
     * make, so a header that lies about it is not read either.
     *
     * @param array<string,mixed> $header
     */
    private function validateHeader(array $header): void
    {
        $keys = [
            'format', 'database', 'source_identity', 'media_plan_sha256',
            'conflict_report_sha256', 'checkpoint_sha256', 'snapshot_manifest_sha256',
            'excluded_products_count', 'excluded_products', 'rows_count',
        ];
        $excluded = $header['excluded_products'] ?? null;
        if (array_keys($header) !== $keys
            || ($header['format'] ?? null) !== self::FORMAT
            || ($header['database'] ?? null) !== 'b2bcrm_artaz'
            || ($header['source_identity'] ?? null) !== 'okay:artaz'
            || !is_int($header['excluded_products_count'] ?? null)
            || $header['excluded_products_count'] < 0
            || !is_array($excluded) || $excluded !== array_values($excluded)
            || count($excluded) !== $header['excluded_products_count']
            || !is_int($header['rows_count'] ?? null)
            || $header['rows_count'] < 1 || $header['rows_count'] > self::MAX_ROWS) {
            throw new GalleryAdoptionException('Gallery adoption header is outside the exact Artaz contract.');
        }
        foreach ($excluded as $product) {
            if (!is_array($product) || $product === []) {
                throw new GalleryAdoptionException('Gallery adoption header is outside the exact Artaz contract.');
            }
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
            // Opaque storefront value: checked for shape, never for arithmetic against the rank.
            || !is_int($row['position'] ?? null) || $row['position'] < 0
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
        // THE ordering invariant of the plan, and the only one: every product carries the closed
        // 1..N rank run the producer emits (`media.order_column`, 1-based against a 0-based plan
        // rank). A gap means the plan does not cover the whole product; a repeat is already caught
        // by the `product_sort` identity above.
        foreach ($sorts as $productSorts) {
            ksort($productSorts);
            if (array_keys($productSorts) !== range(1, count($productSorts))) {
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
