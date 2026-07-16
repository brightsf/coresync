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
    /** @var string путь к каталогу vendored-схем v1 */
    private $schemaDir;

    /** @var array<string, mixed>|null */
    private $manifestSchema = null;

    public function __construct(?string $schemaDir = null)
    {
        $this->schemaDir = $schemaDir ?? dirname(__DIR__) . '/schema/v1';
    }

    /**
     * Разбор JSON-манифеста в ассоц-массив.
     *
     * @return array<string, mixed>
     * @throws ManifestException
     */
    public function parse(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new ManifestException('Манифест снапшота не является валидным JSON-объектом');
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

        $major = $this->majorOf($manifest['schema_version']);
        if ($major !== Contract::SCHEMA_MAJOR) {
            throw new UnsupportedSchemaVersionException(sprintf(
                'unsupported schema_version %s — обнови модуль',
                $manifest['schema_version']
            ));
        }

        $schema = $this->loadManifestSchema();
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
        $fileRequired = (array) ($schema['properties']['files']['items']['required'] ?? []);
        foreach ($manifest['files'] as $i => $file) {
            if (!is_array($file)) {
                throw new ManifestException(sprintf('files[%s] не является объектом', $i));
            }
            $this->assertRequiredKeys($file, $fileRequired, sprintf('files[%s]', $i));
        }

        return $manifest;
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
        $schema = $this->loadManifestSchema();
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
    private function loadManifestSchema(): array
    {
        if ($this->manifestSchema === null) {
            $path = $this->schemaDir . '/manifest.schema.json';
            if (!is_file($path)) {
                throw new ManifestException('Не найдена vendored-схема манифеста: ' . $path);
            }
            $decoded = json_decode((string) file_get_contents($path), true);
            if (!is_array($decoded)) {
                throw new ManifestException('Vendored-схема манифеста повреждена: ' . $path);
            }
            $this->manifestSchema = $decoded;
        }

        return $this->manifestSchema;
    }
}
