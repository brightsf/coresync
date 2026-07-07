<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use Okay\Modules\Format\CoreSync\Core\Exceptions\UnsupportedSchemaVersionException;
use Okay\Modules\Format\CoreSync\Core\ManifestValidator;
use PHPUnit\Framework\TestCase;

/**
 * Валидатор манифеста против vendored-схемы модуля (schema/v1). Обязательные поля берутся
 * из самой схемы — расхождение схемы и данных красит тест.
 */
class ManifestValidatorTest extends TestCase
{
    private function goldenManifestPath(): string
    {
        return __DIR__ . '/fixtures/golden/manifest.json';
    }

    private function validator(): ManifestValidator
    {
        // Без аргумента валидатор резолвит РЕАЛЬНУЮ vendored-схему модуля (dirname(Core)/schema/v1).
        return new ManifestValidator();
    }

    public function testParsesAndValidatesGoldenManifest(): void
    {
        $json = (string) file_get_contents($this->goldenManifestPath());
        $manifest = $this->validator()->validate($this->validator()->parse($json));

        $this->assertSame('1.0.0', $manifest['schema_version']);
        $this->assertSame(1, (int) $manifest['snapshot_version']);
        $this->assertSame('site-a', $manifest['channel_code']);
        $this->assertCount(5, $manifest['files']);
    }

    public function testSummaryExposesVersionAndCounts(): void
    {
        $json = (string) file_get_contents($this->goldenManifestPath());
        $summary = $this->validator()->summary($this->validator()->parse($json));

        $this->assertSame(1, $summary['snapshot_version']);
        $this->assertSame(3, $summary['counts']['products']);
        $this->assertSame(4, $summary['counts']['variants']);
        $this->assertSame(2, $summary['counts']['categories']);
        $this->assertSame('full', $summary['sync_mode']);
    }

    public function testUnsupportedMajorSchemaVersionIsRejectedFailClosed(): void
    {
        $json = (string) file_get_contents($this->goldenManifestPath());
        $manifest = $this->validator()->parse($json);
        $manifest['schema_version'] = '2.0.0';

        $this->expectException(UnsupportedSchemaVersionException::class);
        $this->expectExceptionMessageMatches('/unsupported schema_version 2\.0\.0/');

        $this->validator()->validate($manifest);
    }

    public function testMissingRequiredCountsKeyThrows(): void
    {
        $json = (string) file_get_contents($this->goldenManifestPath());
        $manifest = $this->validator()->parse($json);
        unset($manifest['counts']['variants']);

        $this->expectException(ManifestException::class);
        $this->validator()->validate($manifest);
    }

    public function testMissingFileRequiredKeyThrows(): void
    {
        $json = (string) file_get_contents($this->goldenManifestPath());
        $manifest = $this->validator()->parse($json);
        unset($manifest['files'][0]['sha256']);

        $this->expectException(ManifestException::class);
        $this->validator()->validate($manifest);
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(ManifestException::class);
        $this->validator()->parse('{not-json');
    }
}
