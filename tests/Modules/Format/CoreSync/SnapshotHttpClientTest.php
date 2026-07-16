<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\SnapshotHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Построение URL выдачи ядра (REJECT-фикс §0: buildManifestUrl/buildFileUrl раньше не покрывались —
 * FakeHttpClient переопределяет fetchManifest/downloadFile целиком). Reflection-паттерн repo
 * (ProductHelperTest/APIImportFindCategoryTest). Тестируется ФАКТИЧЕСКОЕ поведение:
 * манифест {core}/api/satellite/{channel}/manifest.json, файлы — сегмент /files/ (пин SAT-B §1),
 * канал rawurlencode, имя файла — только ltrim('/'), токен клеится ТОЛЬКО на fetch-слое
 * (в построенных URL и в лог-контексте токена нет).
 */
class SnapshotHttpClientTest extends TestCase
{
    private function callPrivate(SnapshotHttpClient $client, string $method, array $args)
    {
        $ref = new ReflectionClass(SnapshotHttpClient::class);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs($client, $args);
    }

    public function testManifestUrlIsExactAndWithoutFilesSegment(): void
    {
        $client = new SnapshotHttpClient(null);

        $url = $this->callPrivate($client, 'buildManifestUrl', ['https://core.example', 'site-a']);

        $this->assertSame('https://core.example/api/satellite/site-a/manifest.json', $url);
        $this->assertStringNotContainsString('/files/', $url, 'манифест живёт БЕЗ сегмента /files/');
        $this->assertStringNotContainsString('token', $url, 'токен не участвует в построении URL');
    }

    public function testManifestUrlTrimsTrailingSlashOfBase(): void
    {
        $client = new SnapshotHttpClient(null);

        $url = $this->callPrivate($client, 'buildManifestUrl', ['https://core.example///', 'site-a']);

        $this->assertSame('https://core.example/api/satellite/site-a/manifest.json', $url);
    }

    public function testFileUrlUsesFilesSegmentPinnedBySatB(): void
    {
        $client = new SnapshotHttpClient(null);

        $url = $this->callPrivate($client, 'buildFileUrl', ['https://core.example', 'site-a', 'products-0001.ndjson.gz']);

        $this->assertSame(
            'https://core.example/api/satellite/site-a/files/products-0001.ndjson.gz',
            $url,
            'файлы набора качаются под сегментом /files/ (пин SAT-B §1)'
        );
        $this->assertStringNotContainsString('token', $url);
    }

    public function testChannelCodeIsRawUrlEncodedInBothUrls(): void
    {
        $client = new SnapshotHttpClient(null);

        $manifestUrl = $this->callPrivate($client, 'buildManifestUrl', ['https://core.example', 'site a/б']);
        $fileUrl = $this->callPrivate($client, 'buildFileUrl', ['https://core.example', 'site a/б', 'x.gz']);

        $encoded = rawurlencode('site a/б');
        $this->assertSame('https://core.example/api/satellite/' . $encoded . '/manifest.json', $manifestUrl);
        $this->assertSame('https://core.example/api/satellite/' . $encoded . '/files/x.gz', $fileUrl);
    }

    public function testFileNameLeadingSlashesTrimmedSpecialCharsKeptAsIs(): void
    {
        $client = new SnapshotHttpClient(null);

        // Фактическое поведение: у имени файла срезаются только ведущие «/» (ltrim), содержимое
        // НЕ энкодится (rawurlencode применяется к каналу и токену, не к имени файла манифеста).
        $leadingSlash = $this->callPrivate($client, 'buildFileUrl', ['https://core.example', 'site-a', '///a.gz']);
        $this->assertSame('https://core.example/api/satellite/site-a/files/a.gz', $leadingSlash);

        $dotDot = $this->callPrivate($client, 'buildFileUrl', ['https://core.example', 'site-a', '../a.gz']);
        $this->assertSame(
            'https://core.example/api/satellite/site-a/files/../a.gz',
            $dotDot,
            'спецсимволы имени не ломают построение URL (пропускается as-is; имена приходят из сверенного манифеста)'
        );

        $spaced = $this->callPrivate($client, 'buildFileUrl', ['https://core.example', 'site-a', 'a b.gz']);
        $this->assertSame('https://core.example/api/satellite/site-a/files/a b.gz', $spaced);
    }

    public function testTokenIsNeverLoggedOnExhaustedRetries(): void
    {
        // fetchWithRetries логирует URL БЕЗ токена (токен приклеивается в локальную $withToken).
        // Несуществующая stream-схема → все попытки падают мгновенно → warning «исчерпаны попытки».
        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string $message) use (&$logged): void {
            $logged[] = $message;
        });

        $client = new SnapshotHttpClient($logger);
        $url = $this->callPrivate($client, 'buildFileUrl', ['coresync-test-noscheme://core.example', 'site-a', 'a.gz']);

        $result = @$this->callPrivate($client, 'fetchWithRetries', [$url, 'super-secret-token', SnapshotHttpClient::MAX_MANIFEST_BYTES]);

        $this->assertNull($result, 'исчерпание попыток → null');
        $this->assertNotEmpty($logged, 'исчерпание попыток логируется');
        foreach ($logged as $message) {
            $this->assertStringNotContainsString('super-secret-token', $message, 'токен НЕ попадает в лог-контекст');
            $this->assertStringContainsString('/files/a.gz', $message, 'в лог попадает URL без токена');
        }
    }
}
