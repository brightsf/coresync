<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use Okay\Modules\Format\CoreSync\Core\Exceptions\UnsupportedSchemaVersionException;

/**
 * Парсинг и валидация манифеста снапшота против vendored-схемы (schema/v1/manifest.schema.json).
 * Обязательные поля берутся из самой схемы (не хардкод) — расхождение схемы и манифеста ловится тестом.
 * Fail-closed по мажору schema_version.
 */
class ManifestValidator
{
    /** @var array<int, string> major => vendored schema directory */
    private $schemaDirs;

    /** @var array<int, array<string, mixed>> */
    private $manifestSchemas = [];

    public function __construct(?string $schemaDir = null)
    {
        $v1 = $schemaDir ?? dirname(__DIR__) . '/schema/v1';
        $this->schemaDirs = [
            1 => $v1,
            2 => $schemaDir === null ? dirname(__DIR__) . '/schema/v2' : dirname($v1) . '/v2',
            3 => $schemaDir === null ? dirname(__DIR__) . '/schema/v3' : dirname($v1) . '/v3',
        ];
    }

    /**
     * Разбор JSON-манифеста в ассоц-массив.
     *
     * @return array<string, mixed>
     * @throws ManifestException
     */
    public function parse(string $json): array
    {
        $native = json_decode($json);
        $data = json_decode($json, true);
        if (!is_object($native) || !is_array($data)) {
            throw new ManifestException('Манифест снапшота не является валидным JSON-объектом');
        }
        if (in_array(($data['schema_version'] ?? null), [
                Contract::SNAPSHOT_SCHEMA_V2,
                Contract::SNAPSHOT_SCHEMA_V3,
            ], true)
            && (!property_exists($native, 'files') || !is_array($native->files))) {
            throw new ManifestException('Поле files v2/v3 манифеста должно быть JSON-массивом');
        }
        if (($data['schema_version'] ?? null) === Contract::SNAPSHOT_SCHEMA_V3
            && (!property_exists($native, 'product_content_languages')
                || !is_array($native->product_content_languages))) {
            throw new ManifestException('product_content_languages v3 должен быть JSON-массивом');
        }

        return $data;
    }

    /**
     * Проверка манифеста: поддерживаемый мажор + наличие обязательных полей по схеме.
     *
     * @param array<string, mixed> $manifest
     * @return array<string, mixed> тот же манифест (для чейнинга)
     * @throws UnsupportedSchemaVersionException fail-closed по мажору
     * @throws ManifestException структурные нарушения
     */
    public function validate(array $manifest): array
    {
        if (!isset($manifest['schema_version']) || !is_string($manifest['schema_version'])) {
            throw new ManifestException('В манифесте отсутствует поле schema_version');
        }

        $major = $this->major($manifest);

        $schema = $this->loadManifestSchema($major);
        $this->assertSchemaVersionMatches($manifest['schema_version'], $schema);
        $this->assertRequiredKeys($manifest, (array) ($schema['required'] ?? []), 'манифеста');

        $countsRequired = (array) ($schema['properties']['counts']['required'] ?? []);
        $counts = $manifest['counts'] ?? null;
        if (!is_array($counts)) {
            throw new ManifestException('Поле counts манифеста отсутствует или не является объектом');
        }
        $this->assertRequiredKeys($counts, $countsRequired, 'counts');

        if (!isset($manifest['files']) || !is_array($manifest['files'])) {
            throw new ManifestException('Поле files манифеста отсутствует или не является массивом');
        }
        if (Contract::isSnapshotStructuralV2Plus($major) && !Contract::isList($manifest['files'])) {
            throw new ManifestException('Поле files v2/v3 манифеста должно быть списком');
        }
        $fileRequired = (array) ($schema['properties']['files']['items']['required'] ?? []);
        foreach ($manifest['files'] as $i => $file) {
            if (!is_array($file)) {
                throw new ManifestException(sprintf('files[%s] не является объектом', $i));
            }
            $this->assertRequiredKeys($file, $fileRequired, sprintf('files[%s]', $i));
        }

        // v1 validation remains deliberately byte-compatible with the established consumer.
        // V2/V3 are closed boundaries; V3 adds the exact product language list checks below.
        if (Contract::isSnapshotStructuralV2Plus($major)) {
            $this->assertStructuralManifest($manifest, $schema, $major);
        }

        return $manifest;
    }

    /**
     * Parse and pin an exact supported major from an already decoded manifest.
     *
     * @param array<string, mixed> $manifest
     * @throws UnsupportedSchemaVersionException
     */
    public function major(array $manifest): int
    {
        $version = $manifest['schema_version'] ?? null;
        if (!is_string($version)
            || preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/', $version) !== 1) {
            throw new UnsupportedSchemaVersionException('unsupported malformed schema_version — обнови модуль');
        }

        $major = (int) explode('.', $version, 2)[0];
        if (!in_array($major, Contract::SNAPSHOT_SCHEMA_MAJORS, true)) {
            throw new UnsupportedSchemaVersionException(sprintf(
                'unsupported schema_version %s — обнови модуль',
                $version
            ));
        }

        return $major;
    }

    /**
     * Краткая сводка для «Проверить связь»: version / counts / generated_at / mode.
     *
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function summary(array $manifest): array
    {
        return [
            'schema_version'   => $manifest['schema_version'] ?? null,
            'snapshot_version' => isset($manifest['snapshot_version']) ? (int) $manifest['snapshot_version'] : null,
            'channel_code'     => $manifest['channel_code'] ?? null,
            'generated_at'     => $manifest['generated_at'] ?? null,
            'language'         => $manifest['language'] ?? null,
            'currency'         => $manifest['currency'] ?? null,
            'sync_mode'        => $manifest['sync_mode'] ?? null,
            'absent_policy'    => $manifest['absent_policy'] ?? null,
            'counts'           => $manifest['counts'] ?? [],
            'files_count'      => isset($manifest['files']) && is_array($manifest['files']) ? count($manifest['files']) : 0,
        ];
    }

    /**
     * Версия снапшот-контракта, которую модуль умеет ПРИМЕНЯТЬ — живой источник для церемонии
     * подключения ({@see Describer}). Берётся из vendored-схемы, по которой валидируется входящий
     * манифест, чтобы «что умею» и «что проверяю» не могли разъехаться. Мажор сверяется с
     * Contract::SCHEMA_MAJOR: рассинхрон схемы и кода — не то, о чём стоит узнавать от ядра.
     *
     * @throws ManifestException схема повреждена / не объявляет версию / разъехалась с Contract
     */
    public function supportedSchemaVersion(): string
    {
        $schema = $this->loadManifestSchema(Contract::SCHEMA_MAJOR);
        $spec = (array) ($schema['properties']['schema_version'] ?? []);

        // Схема фиксирует версию одним из двух способов (та же семантика — «поддерживаю мажор 1»):
        //  - const "1.0.0" — точечная версия;
        //  - pattern "^1\.[0-9]+\.[0-9]+$" — любой минор/патч мажора 1 (как describe.schema.json).
        // Для pattern одной версии нет, поэтому представляем поддерживаемый мажор канонической
        // MAJOR.0.0 и проверяем, что pattern её принимает (иначе pattern описывает не наш мажор).
        $const = $spec['const'] ?? null;
        $pattern = $spec['pattern'] ?? null;
        if (is_string($const) && $const !== '') {
            $version = $const;
        } elseif (is_string($pattern) && $pattern !== '') {
            $version = Contract::SCHEMA_MAJOR . '.0.0';
            if (preg_match('#' . $pattern . '#', $version) !== 1) {
                throw new ManifestException(sprintf(
                    'Vendored-схема манифеста: pattern "%s" не принимает поддерживаемый мажор %d (%s)',
                    $pattern,
                    Contract::SCHEMA_MAJOR,
                    $version
                ));
            }
        } else {
            throw new ManifestException('Vendored-схема манифеста не объявляет schema_version (ни const, ни pattern)');
        }

        if ($this->majorOf($version) !== Contract::SCHEMA_MAJOR) {
            throw new ManifestException(sprintf(
                'Мажор vendored-схемы (%s) разошёлся с Contract::SCHEMA_MAJOR (%d)',
                $version,
                Contract::SCHEMA_MAJOR
            ));
        }

        return $version;
    }

    private function majorOf(string $schemaVersion): int
    {
        $parts = explode('.', $schemaVersion);

        return (int) ($parts[0] ?? 0);
    }

    /** @param array<string, mixed> $schema */
    private function assertSchemaVersionMatches(string $version, array $schema): void
    {
        $spec = (array) ($schema['properties']['schema_version'] ?? []);
        if (isset($spec['const']) && $version !== (string) $spec['const']) {
            throw new UnsupportedSchemaVersionException('unsupported schema_version ' . $version . ' — обнови модуль');
        }
        if (isset($spec['pattern']) && preg_match('#' . $spec['pattern'] . '#', $version) !== 1) {
            throw new UnsupportedSchemaVersionException('unsupported schema_version ' . $version . ' — обнови модуль');
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $schema
     */
    private function assertStructuralManifest(array $manifest, array $schema, int $major): void
    {
        $properties = (array) ($schema['properties'] ?? []);
        $this->assertAllowedKeys($manifest, array_keys($properties), 'манифеста');

        foreach (['channel_code', 'generated_at', 'language', 'currency'] as $field) {
            if (!is_string($manifest[$field] ?? null)) {
                throw new ManifestException('Поле ' . $field . ' манифеста должно быть строкой');
            }
        }
        if (!is_int($manifest['snapshot_version'] ?? null) || $manifest['snapshot_version'] < 1) {
            throw new ManifestException('Поле snapshot_version манифеста должно быть положительным integer');
        }
        if (!in_array($manifest['sync_mode'] ?? null, (array) ($properties['sync_mode']['enum'] ?? []), true)
            || !in_array($manifest['absent_policy'] ?? null, (array) ($properties['absent_policy']['enum'] ?? []), true)) {
            throw new ManifestException('Манифест содержит неподдерживаемый mode/policy');
        }

        $countsSchema = (array) ($properties['counts'] ?? []);
        $counts = (array) $manifest['counts'];
        $this->assertAllowedKeys($counts, array_keys((array) ($countsSchema['properties'] ?? [])), 'counts');
        foreach ($counts as $key => $value) {
            if (!is_int($value) || $value < 0) {
                throw new ManifestException('Поле counts.' . $key . ' должно быть неотрицательным integer');
            }
        }

        $fileSchema = (array) ($properties['files']['items'] ?? []);
        $allowedFileKeys = array_keys((array) ($fileSchema['properties'] ?? []));
        foreach ($manifest['files'] as $i => $file) {
            $file = (array) $file;
            $this->assertAllowedKeys($file, $allowedFileKeys, sprintf('files[%s]', $i));
            if (!is_string($file['name'] ?? null)
                || !is_string($file['sha256'] ?? null)
                || preg_match('/\A[0-9a-f]{64}\z/', $file['sha256']) !== 1
                || !is_int($file['bytes'] ?? null) || $file['bytes'] < 0
                || !is_int($file['rows'] ?? null) || $file['rows'] < 0) {
                throw new ManifestException(sprintf('files[%s] не соответствует vendored v2 schema', $i));
            }
        }

        if ($major === 3) {
            $languages = $manifest['product_content_languages'] ?? null;
            if (!is_array($languages) || !Contract::isList($languages) || $languages === []) {
                throw new ManifestException('product_content_languages v3 должен быть непустым списком');
            }
            $seen = [];
            foreach ($languages as $language) {
                if (!is_string($language)
                    || preg_match('/\A[a-z][a-z0-9_-]{0,15}\z/', $language) !== 1
                    || isset($seen[$language])) {
                    throw new ManifestException('product_content_languages v3 содержит неверный или повторный код');
                }
                $seen[$language] = true;
            }
            $sorted = $languages;
            sort($sorted, SORT_STRING);
            if ($sorted !== $languages) {
                throw new ManifestException('product_content_languages v3 должен быть отсортирован');
            }
            if (!isset($seen[(string) $manifest['language']])) {
                throw new ManifestException('Язык канала отсутствует в product_content_languages v3');
            }
        }
    }

    /** @param array<string, mixed> $data @param array<int, string> $allowed */
    private function assertAllowedKeys(array $data, array $allowed, string $where): void
    {
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new ManifestException(sprintf('В %s запрещено поле "%s"', $where, $key));
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string>   $required
     * @throws ManifestException
     */
    private function assertRequiredKeys(array $data, array $required, string $where): void
    {
        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                throw new ManifestException(sprintf('В %s отсутствует обязательное поле "%s"', $where, $key));
            }
        }
    }

    /**
     * @return array<string, mixed>
     * @throws ManifestException
     */
    private function loadManifestSchema(int $major): array
    {
        if (!isset($this->manifestSchemas[$major])) {
            $path = ($this->schemaDirs[$major] ?? '') . '/manifest.schema.json';
            if (!is_file($path)) {
                throw new ManifestException('Не найдена vendored-схема манифеста: ' . $path);
            }
            $decoded = json_decode((string) file_get_contents($path), true);
            if (!is_array($decoded)) {
                throw new ManifestException('Vendored-схема манифеста повреждена: ' . $path);
            }
            $this->manifestSchemas[$major] = $decoded;
        }

        return $this->manifestSchemas[$major];
    }
}
