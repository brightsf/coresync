<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

use Okay\Core\Config;
use Psr\Log\LoggerInterface;

/**
 * Security boundary for v2 category images.  Every redirect is manually resolved and checked,
 * the validated public IP is pinned into curl, and only verified image bytes reach the category
 * originals directory.
 */
class CategoryImageDownloader
{
    const MAX_BYTES = 5242880;
    const MAX_DIMENSION = 8192;
    const MAX_PIXELS = 16000000;
    const MAX_REDIRECTS = 5;
    const OWNED_PREFIX = 'coresync_category_';

    /** @var string[] */
    private const BLOCKED_CIDRS = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
        '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
        '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48',
        '100::/64', '2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20',
        '5f00::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    /** @var Config */
    private $config;
    /** @var LoggerInterface|null */
    private $logger;
    /** @var string|null Safe machine code only. */
    private $lastErrorCode;

    public function __construct(Config $config, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * @param array{url:string,sha256:string,mime:string,bytes:int} $descriptor
     * @return string|null basename in original_categories_dir
     */
    public function download(array $descriptor, string $opaqueIdentity): ?string
    {
        $this->lastErrorCode = null;
        $temporary = null;
        try {
            if (!isset($descriptor['url'], $descriptor['sha256'], $descriptor['mime'], $descriptor['bytes'])
                || !is_string($descriptor['url'])
                || !is_string($descriptor['sha256'])
                || preg_match('/\A[0-9a-f]{64}\z/', $descriptor['sha256']) !== 1
                || !is_string($descriptor['mime'])
                || !in_array($descriptor['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)
                || !is_int($descriptor['bytes'])
                || $descriptor['bytes'] < 1
                || $descriptor['bytes'] > self::MAX_BYTES) {
                return $this->fail('descriptor_invalid', $opaqueIdentity);
            }
            $targetDir = $this->targetDirectory();
            if (!is_dir($targetDir) || !is_writable($targetDir)) {
                return $this->fail('target_unavailable', $opaqueIdentity);
            }

            $filename = $this->ownedFilename($opaqueIdentity, $descriptor['sha256'], $descriptor['mime']);
            $target = $targetDir . $filename;
            if (is_file($target)) {
                if ($this->verifyFile($target, $descriptor)) {
                    return $filename; // crash-safe reuse after rename but before durable DB commit
                }

                return $this->fail('target_collision', $opaqueIdentity);
            }

            $temporary = tempnam($targetDir, '.coresync_category_');
            if (!is_string($temporary)) {
                return $this->fail('temp_create_failed', $opaqueIdentity);
            }

            $url = $descriptor['url'];
            for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
                $targetInfo = $this->resolvePublicTarget($url);
                if ($targetInfo === null) {
                    return $this->fail('unsafe_target', $opaqueIdentity);
                }
                $stream = fopen($temporary, 'wb');
                if ($stream === false) {
                    return $this->fail('temp_open_failed', $opaqueIdentity);
                }
                try {
                    $result = $this->fetchHop($url, $targetInfo, $stream);
                } finally {
                    fclose($stream);
                }

                $status = (int) ($result['status'] ?? 0);
                if ($status >= 300 && $status < 400) {
                    $location = $result['location'] ?? null;
                    if (!is_string($location) || $location === '' || $redirects === self::MAX_REDIRECTS) {
                        return $this->fail('redirect_rejected', $opaqueIdentity);
                    }
                    $url = $this->resolveRedirect($url, $location);
                    if ($url === null) {
                        return $this->fail('redirect_rejected', $opaqueIdentity);
                    }
                    continue;
                }
                if ($status !== 200) {
                    return $this->fail('http_status', $opaqueIdentity);
                }
                if (!$this->verifyFile($temporary, $descriptor)) {
                    return $this->fail('content_mismatch', $opaqueIdentity);
                }
                if (!@rename($temporary, $target)) {
                    return $this->fail('atomic_rename_failed', $opaqueIdentity);
                }
                $temporary = null;

                return $filename;
            }

            return $this->fail('redirect_limit', $opaqueIdentity);
        } catch (\Throwable $e) {
            return $this->fail('download_exception', $opaqueIdentity);
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function lastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    /** Delete only a plain basename generated by this class, within the configured directory. */
    public function deleteOwned(string $filename): bool
    {
        if (!$this->isOwnedBasename($filename)) {
            return false;
        }
        $path = $this->targetDirectory() . $filename;
        if (is_link($path)) {
            return false;
        }
        if (!is_file($path)) {
            return true;
        }

        $base = realpath($this->targetDirectory());
        $actual = realpath($path);
        if (!is_string($base) || !is_string($actual) || dirname($actual) !== $base) {
            return false;
        }

        return @unlink($path);
    }

    /**
     * @return array{host:string,port:int,ip:string}|null
     */
    protected function resolvePublicTarget(string $url): ?array
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $host = strtolower(rtrim(trim((string) $parts['host'], '[]'), '.'));
        if ($host === '' || strpos($host, '%') !== false || $this->isLegacyNumericIpv4Host($host)
            || $host === 'localhost' || $this->endsWith($host, '.localhost')
            || $this->endsWith($host, '.local') || $this->endsWith($host, '.internal')) {
            return null;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolveHost($host);
        if (empty($ips)) {
            return null;
        }
        $public = [];
        foreach (array_unique($ips) as $ip) {
            if (!$this->isPublicIp($ip)) {
                return null; // fail closed if DNS returns even one private/restricted target
            }
            $public[] = $ip;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            return null;
        }

        return ['host' => $host, 'port' => $port, 'ip' => $public[0]];
    }

    /** @return string[] */
    protected function resolveHost(string $host): array
    {
        $ips = [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            foreach (is_array($records) ? $records : [] as $record) {
                if (isset($record['ip'])) {
                    $ips[] = (string) $record['ip'];
                }
                if (isset($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }
        if (empty($ips)) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = $v4;
            }
        }

        return $ips;
    }

    /**
     * @param array{host:string,port:int,ip:string} $target
     * @param resource $stream
     * @return array{status:int,location:?string}
     */
    protected function fetchHop(string $url, array $target, $stream): array
    {
        $received = 0;
        $overflow = false;
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'location' => null];
        }

        $resolveIp = strpos($target['ip'], ':') !== false ? '[' . $target['ip'] . ']' : $target['ip'];
        $options = [
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept-Encoding: identity'],
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($stream, &$received, &$overflow): int {
                $length = strlen($chunk);
                $received += $length;
                if ($received > self::MAX_BYTES) {
                    $overflow = true;

                    return 0;
                }
                $written = fwrite($stream, $chunk);

                return $written === false ? 0 : $written;
            },
        ];
        if (filter_var($target['host'], FILTER_VALIDATE_IP) === false) {
            $options[CURLOPT_RESOLVE] = [$target['host'] . ':' . $target['port'] . ':' . $resolveIp];
        }
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if ($ok === false || $overflow) {
            return ['status' => 0, 'location' => null];
        }

        return ['status' => $status, 'location' => is_string($location) && $location !== '' ? $location : null];
    }

    /** @param array{sha256:string,mime:string,bytes:int} $descriptor */
    private function verifyFile(string $path, array $descriptor): bool
    {
        $size = @filesize($path);
        if (!is_int($size) || $size < 1 || $size > self::MAX_BYTES || $size !== (int) $descriptor['bytes']) {
            return false;
        }
        $hash = @hash_file('sha256', $path);
        if (!is_string($hash) || !hash_equals($descriptor['sha256'], $hash)) {
            return false;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        if (!is_string($mime) || $mime !== $descriptor['mime']) {
            return false;
        }
        $info = @getimagesize($path);
        $width = is_array($info) ? (int) ($info[0] ?? 0) : 0;
        $height = is_array($info) ? (int) ($info[1] ?? 0) : 0;
        $expectedTypes = [
            'image/jpeg' => IMAGETYPE_JPEG,
            'image/png' => IMAGETYPE_PNG,
            'image/webp' => IMAGETYPE_WEBP,
            'image/gif' => IMAGETYPE_GIF,
        ];
        if (!is_array($info)
            || $width < 1 || $height < 1
            || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION
            || $height > intdiv(self::MAX_PIXELS, $width)
            || (int) ($info[2] ?? 0) !== $expectedTypes[$descriptor['mime']]) {
            return false;
        }
        if (!$this->hasRealImageDecoder() || !$this->realDecode($path)) {
            return false;
        }

        return true;
    }

    /** A missing/disabled GD decoder is a hard trust-boundary failure, never an optional check. */
    protected function hasRealImageDecoder(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagedestroy');
    }

    /** Decode the complete raster so finfo/getimagesize header probes cannot authorize it alone. */
    protected function realDecode(string $path): bool
    {
        $bytes = @file_get_contents($path);
        if (!is_string($bytes)) {
            return false;
        }
        $decoded = @imagecreatefromstring($bytes);
        if ($decoded === false) {
            return false;
        }
        imagedestroy($decoded);

        return true;
    }

    /** @return string|null */
    private function resolveRedirect(string $base, string $location): ?string
    {
        if ($location === '' || preg_match('/[\x00-\x20\x7f]/', $location) === 1) {
            return null;
        }
        if (preg_match('#\Ahttps?://#i', $location) === 1) {
            return $location;
        }
        $baseParts = parse_url($base);
        if (!is_array($baseParts) || !isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }
        if (strpos($location, '//') === 0) {
            return $baseParts['scheme'] . ':' . $location;
        }
        $origin = $baseParts['scheme'] . '://'
            . (strpos((string) $baseParts['host'], ':') !== false ? '[' . trim((string) $baseParts['host'], '[]') . ']' : $baseParts['host'])
            . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '');
        if (strpos($location, '/') === 0) {
            return $origin . $location;
        }
        $path = (string) ($baseParts['path'] ?? '/');
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin . $directory . $location;
    }

    private function isLegacyNumericIpv4Host(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return false;
        }
        $parts = explode('.', $host);
        if (count($parts) > 4) {
            return false;
        }
        foreach ($parts as $part) {
            if (preg_match('/\A(?:0x[0-9a-f]+|[0-9]+)\z/i', $part) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        foreach (self::BLOCKED_CIDRS as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        list($network, $prefix) = explode('/', $cidr, 2);
        $packedIp = @inet_pton($ip);
        $packedNetwork = @inet_pton($network);
        if (!is_string($packedIp) || !is_string($packedNetwork) || strlen($packedIp) !== strlen($packedNetwork)) {
            return false;
        }
        $bits = (int) $prefix;
        $bytes = intdiv($bits, 8);
        $remaining = $bits % 8;
        if (substr($packedIp, 0, $bytes) !== substr($packedNetwork, 0, $bytes)) {
            return false;
        }
        if ($remaining === 0) {
            return true;
        }
        $mask = (0xff << (8 - $remaining)) & 0xff;

        return (ord($packedIp[$bytes]) & $mask) === (ord($packedNetwork[$bytes]) & $mask);
    }

    private function ownedFilename(string $opaqueIdentity, string $sha256, string $mime): string
    {
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

        return self::OWNED_PREFIX
            . substr(hash('sha256', $opaqueIdentity), 0, 16) . '_'
            . substr($sha256, 0, 20) . '.' . $extensions[$mime];
    }

    private function isOwnedBasename(string $filename): bool
    {
        return basename($filename) === $filename
            && preg_match('/\A' . self::OWNED_PREFIX . '[0-9a-f]{16}_[0-9a-f]{20}\.(?:jpg|png|webp|gif)\z/', $filename) === 1;
    }

    private function targetDirectory(): string
    {
        $root = rtrim((string) $this->config->get('root_dir'), '/\\') . '/';
        $relative = trim((string) $this->config->get('original_categories_dir'), '/\\');

        return $root . ($relative !== '' ? $relative . '/' : '');
    }

    private function endsWith(string $value, string $suffix): bool
    {
        return $suffix === '' || substr($value, -strlen($suffix)) === $suffix;
    }

    private function fail(string $code, string $opaqueIdentity): ?string
    {
        $this->lastErrorCode = $code;
        if ($this->logger !== null) {
            // No URL, filesystem path, hash, content or remote error message crosses this boundary.
            $this->logger->warning('CoreSync category image failed', [
                'code' => $code,
                'category' => substr(hash('sha256', $opaqueIdentity), 0, 12),
            ]);
        }

        return null;
    }
}
