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
        $manifest['schema_version'] = '4.0.0';

        $this->expectException(UnsupportedSchemaVersionException::class);
        $this->expectExceptionMessageMatches('/unsupported schema_version 4\.0\.0/');

        $this->validator()->validate($manifest);
    }

    public function testV2ManifestUsesVendoredV2SchemaAndValidatesSuccessfully(): void
    {
        $manifest = $this->validator()->parse((string) file_get_contents($this->goldenManifestPath()));
        $manifest['schema_version'] = '2.0.0';

        $validated = $this->validator()->validate($manifest);

        $this->assertSame('2.0.0', $validated['schema_version']);
        $this->assertSame(2, $this->validator()->major($validated));
        $this->assertSame('1.0.0', $this->validator()->supportedSchemaVersion());
    }

    public function testV3ManifestUsesVendoredSchemaAndPinsSortedProductLanguages(): void
    {
        $manifest = $this->v3Manifest();

        $validated = $this->validator()->validate($manifest);

        $this->assertSame(3, $this->validator()->major($validated));
        $this->assertSame(['ru', 'uk'], $validated['product_content_languages']);
        $this->assertSame('1.0.0', $this->validator()->supportedSchemaVersion());
    }

    /** @dataProvider invalidV3LanguageSets */
    public function testV3ManifestRejectsInvalidLanguageSet($languages, string $channelLanguage = 'ru'): void
    {
        $manifest = $this->v3Manifest();
        $manifest['language'] = $channelLanguage;
        if ($languages === '__missing__') {
            unset($manifest['product_content_languages']);
        } else {
            $manifest['product_content_languages'] = $languages;
        }

        $this->expectException(ManifestException::class);
        $this->validator()->validate($manifest);
    }

    /** @return array<string, array{mixed,string?}> */
    public function invalidV3LanguageSets(): array
    {
        return [
            'missing' => ['__missing__'],
            'empty' => [[]],
            'duplicate' => [['ru', 'ru']],
            'unsorted' => [['uk', 'ru']],
            'non-string' => [['ru', 17]],
            'channel language absent' => [['uk'], 'ru'],
        ];
    }

    public function testV3ParseRejectsJsonObjectInsteadOfLanguageList(): void
    {
        $manifest = $this->v3Manifest();
        $manifest['product_content_languages'] = (object) ['ru' => 91];

        $this->expectException(ManifestException::class);
        $this->validator()->parse((string) json_encode($manifest));
    }

    /** @dataProvider malformedSchemaVersions */
    public function testMalformedSchemaVersionIsRejectedBeforeSchemaSelection(string $version): void
    {
        $manifest = $this->validator()->parse((string) file_get_contents($this->goldenManifestPath()));
        $manifest['schema_version'] = $version;

        $this->expectException(UnsupportedSchemaVersionException::class);
        $this->validator()->validate($manifest);
    }

    /** @return array<string, array{string}> */
    public function malformedSchemaVersions(): array
    {
        return [
            'missing patch' => ['2.0'],
            'leading whitespace' => [' 2.0.0'],
            'suffix' => ['2.0.0-beta'],
            'numeric alias' => ['02.0.0'],
        ];
    }

    public function testV2ManifestRecursiveAllowListRejectsUnexpectedNestedKey(): void
    {
        $manifest = $this->validator()->parse((string) file_get_contents($this->goldenManifestPath()));
        $manifest['schema_version'] = '2.0.0';
        $manifest['files'][0]['token'] = 'must-not-pass';

        $this->expectException(ManifestException::class);
        $this->validator()->validate($manifest);
    }

    /** @dataProvider jsonObjectFiles */
    public function testV2ManifestRejectsJsonObjectInsteadOfFilesList(object $files): void
    {
        $manifest = $this->validator()->parse((string) file_get_contents($this->goldenManifestPath()));
        $manifest['schema_version'] = '2.0.0';
        $manifest['files'] = $files;

        $json = (string) json_encode($manifest, JSON_UNESCAPED_UNICODE);

        $this->expectException(ManifestException::class);
        $this->validator()->validate($this->validator()->parse($json));
    }

    /** @return array<string, array{object}> */
    public function jsonObjectFiles(): array
    {
        $manifest = $this->validator()->parse((string) file_get_contents($this->goldenManifestPath()));
        $file = (object) $manifest['files'][0];

        return [
            'named object' => [(object) ['categories' => $file]],
            'numeric sequential object' => [(object) ['0' => $file]],
        ];
    }

    /** @dataProvider nonListFiles */
    public function testV2ManifestRejectsSparseOrNonListFilesArray(array $files): void
    {
        $manifest = $this->validator()->parse((string) file_get_contents($this->goldenManifestPath()));
        $manifest['schema_version'] = '2.0.0';
        $manifest['files'] = $files;

        $this->expectException(ManifestException::class);
        $this->validator()->validate($manifest);
    }

    public function testV1KeepsEstablishedFilesObjectBehavior(): void
    {
        $manifest = $this->validator()->parse((string) file_get_contents($this->goldenManifestPath()));
        $manifest['files'] = (object) ['0' => (object) $manifest['files'][0]];

        $validated = $this->validator()->validate($this->validator()->parse(
            (string) json_encode($manifest, JSON_UNESCAPED_UNICODE)
        ));

        $this->assertSame('1.0.0', $validated['schema_version']);
        $this->assertCount(1, $validated['files']);
    }

    /** @return array<string, array{array<mixed>}> */
    public function nonListFiles(): array
    {
        $manifest = $this->validator()->parse((string) file_get_contents($this->goldenManifestPath()));
        $file = $manifest['files'][0];

        return [
            'sparse numeric' => [[1 => $file]],
            'mixed keys' => [[0 => $file, 'next' => $file]],
        ];
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

    // ------------------------------------------------------------------
    // supportedSchemaVersion: принимает const- И pattern-схему (координация со stage-sat-sweep:
    // ядро переводит схему на pattern; порядок мержей неважен — оба варианта дают мажор 1).
    // ------------------------------------------------------------------

    /** Реальная vendored-схема модуля (теперь pattern) → поддерживаемая версия выводится, мажор 1. */
    public function testSupportedSchemaVersionFromVendoredSchema(): void
    {
        $version = $this->validator()->supportedSchemaVersion();

        $this->assertMatchesRegularExpression('/^1\.[0-9]+\.[0-9]+$/', $version);
    }

    /** const-схема (историческая форма) продолжает приниматься — возвращается ровно const. */
    public function testSupportedSchemaVersionAcceptsConstSchema(): void
    {
        $dir = $this->schemaDirWith(['const' => '1.0.0']);

        $this->assertSame('1.0.0', (new ManifestValidator($dir))->supportedSchemaVersion());
    }

    /** pattern-схема (новая форма, как в ядре и describe.schema.json) — возвращается MAJOR.0.0. */
    public function testSupportedSchemaVersionAcceptsPatternSchema(): void
    {
        $dir = $this->schemaDirWith(['type' => 'string', 'pattern' => '^1\\.[0-9]+\\.[0-9]+$']);

        $this->assertSame('1.0.0', (new ManifestValidator($dir))->supportedSchemaVersion());
    }

    /** Ни const, ни pattern → схема ничего не обещает → бросок (fail-closed). */
    public function testSupportedSchemaVersionThrowsWithoutConstOrPattern(): void
    {
        $dir = $this->schemaDirWith(['type' => 'string']);

        $this->expectException(ManifestException::class);
        (new ManifestValidator($dir))->supportedSchemaVersion();
    }

    /** pattern чужого мажора (2.x) не принимается канонической 1.0.0 → бросок. */
    public function testSupportedSchemaVersionThrowsOnForeignMajorPattern(): void
    {
        $dir = $this->schemaDirWith(['type' => 'string', 'pattern' => '^2\\.[0-9]+\\.[0-9]+$']);

        $this->expectException(ManifestException::class);
        (new ManifestValidator($dir))->supportedSchemaVersion();
    }

    /**
     * Пишет во временный каталог минимальную manifest.schema.json с заданным spec поля
     * schema_version и возвращает путь каталога (для ManifestValidator($dir)).
     *
     * @param array<string, mixed> $schemaVersionSpec
     */
    private function schemaDirWith(array $schemaVersionSpec): string
    {
        $dir = sys_get_temp_dir() . '/coresync_schema_' . uniqid('', true);
        mkdir($dir, 0775, true);
        $this->tmpSchemaDirs[] = $dir;

        $schema = ['properties' => ['schema_version' => $schemaVersionSpec]];
        file_put_contents($dir . '/manifest.schema.json', (string) json_encode($schema));

        return $dir;
    }

    /** @var list<string> */
    private $tmpSchemaDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpSchemaDirs as $dir) {
            @unlink($dir . '/manifest.schema.json');
            @rmdir($dir);
        }
        $this->tmpSchemaDirs = [];
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function v3Manifest(): array
    {
        $manifest = $this->validator()->parse((string) file_get_contents($this->goldenManifestPath()));
        $manifest['schema_version'] = '3.0.0';
        $manifest['language'] = 'ru';
        $manifest['product_content_languages'] = ['ru', 'uk'];

        return $manifest;
    }
}
