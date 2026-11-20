<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;

/**
 * Своё хранилище документов из ядра (product_documents_store 1.0.0) [SECURITY-SENSITIVE].
 *
 * Порядок одного обмена (спек §5.2):
 *  1. {@see prepare()}: файлы пакета, которых нет в хранилище, переносятся из staging одним rename
 *     и записываются строкой файла. Файл, не доехавший в этот обмен (лимит байт), недоступен.
 *  2. {@see reconcileProduct()}: документы товара переписываются одной транзакцией. ready с
 *     доступным файлом вставляется или обновляется; pending и ready без файла не трогают то, что
 *     уже есть по этому external_id; external_id, которого нет в списке, отвязывается.
 *  3. {@see finish()}: после записи привязок удаляются файлы без ссылок.
 * Привязка появляется только к уже записанному файлу, файл удаляется только без ссылок, поэтому
 * обрыв на любом шаге оставляет самое большее файл без привязок; его убирает следующий обмен.
 * Режим disabled удаляет все документы и файлы хранилища и больше ничего.
 */
class ProductDocumentStore
{
    /** @var ProductDocumentStoreRepository */
    private $repository;
    /** @var ProductDocumentStoreFiles */
    private $files;
    /** @var Settings */
    private $settings;
    /** @var array<string, bool> sha256 файлов, к которым в этом обмене можно привязывать */
    private $available = [];

    public function __construct(ProductDocumentStoreRepository $repository, ProductDocumentStoreFiles $files, Settings $settings)
    {
        $this->repository = $repository;
        $this->files = $files;
        $this->settings = $settings;
    }

    /** Сколько байт документов качать за один обмен (настройка модуля). */
    public function downloadBudget(): int
    {
        return Contract::documentsMaxBytes($this->settings->get(Contract::SETTINGS_KEY));
    }

    /** Файл с этим хешем уже в хранилище: скачивать его не нужно. */
    public function hasStoredFile(string $sha256, int $bytes): bool
    {
        return preg_match(ProductDocumentStoreFiles::SHA_PATTERN, $sha256) === 1
            && $this->repository->hasFile($sha256)
            && $this->files->isStored($sha256, $bytes);
    }

    /**
     * Какие из файлов пакета уже лежат в хранилище и записаны строкой (одним запросом строк).
     *
     * @param array<string, int> $candidates sha256 => bytes
     * @return array<string, bool>
     */
    public function storedFiles(array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }
        $recorded = $this->repository->files();
        $stored = [];
        foreach ($candidates as $sha => $bytes) {
            $sha = (string) $sha;
            if (preg_match(ProductDocumentStoreFiles::SHA_PATTERN, $sha) === 1
                && ($recorded[$sha] ?? null) === (int) $bytes && $this->files->isStored($sha, (int) $bytes)) {
                $stored[$sha] = true;
            }
        }

        return $stored;
    }

    /**
     * Шаг 1 (store) или полная уборка (disabled). Зовётся после проверки всего пакета и до записи
     * первого товара.
     *
     * @param array<string, mixed> $manifest
     */
    public function prepare(string $mode, array $manifest, string $stagingDir, ApplyStats $stats): void
    {
        $this->available = [];
        if ($mode === Contract::PRODUCT_DOCUMENTS_MODE_DISABLED) {
            $stats->documentsUnlinked += $this->repository->deleteAllDocuments();
            $this->finish($stats);

            return;
        }
        if ($mode !== Contract::PRODUCT_DOCUMENTS_MODE_STORE) {
            throw new ManifestException('Хранилище документов: неизвестный режим ' . $mode);
        }

        foreach ((array) ($manifest['files'] ?? []) as $file) {
            if (!is_array($file) || ($file['role'] ?? null) !== 'product_document') {
                continue;
            }
            $sha = (string) ($file['sha256'] ?? '');
            $bytes = (int) ($file['bytes'] ?? 0);
            if ($this->hasStoredFile($sha, $bytes)) {
                $this->available[$sha] = true;
                continue;
            }
            if ($this->files->ingest($stagingDir, $sha, $bytes)) {
                $this->recordFile($sha, $bytes);
                $this->available[$sha] = true;
                $stats->documentFilesDownloaded++;
                continue;
            }
            if ($this->files->adopt($sha, $bytes)) {
                $this->recordFile($sha, $bytes);
                $this->available[$sha] = true;
                continue;
            }
            $stats->documentFilesDeferred++;
        }
    }

    /**
     * Шаг 2 для одного товара.
     *
     * @param array<int, array<string, mixed>> $documents провалидированный список store-формы
     */
    public function reconcileProduct(string $productExternalId, int $productLocalId, array $documents, ApplyStats $stats): void
    {
        $existing = $this->repository->productDocuments($productExternalId);
        $inserts = [];
        $updates = [];
        $deletes = [];
        $seen = [];
        foreach ($documents as $document) {
            $externalId = (string) $document['external_id'];
            $seen[$externalId] = true;
            $sha = $document['sha256'] ?? null;
            if (($document['state'] ?? null) !== Contract::PRODUCT_DOCUMENT_STATE_READY
                || !is_string($sha) || !isset($this->available[$sha])) {
                // pending и не доехавший ready: имеющееся по этому external_id не трогаем.
                $stats->documentsPending++;
                continue;
            }
            $fields = [
                'product_external_id' => $productExternalId,
                'product_local_id' => $productLocalId,
                'document_external_id' => $externalId,
                'title' => (string) $document['title'],
                'position' => (int) $document['position'],
                'language' => $document['language'],
                'document_type' => $document['type'],
                'sha256' => $sha,
            ];
            $current = $existing[$externalId] ?? null;
            if ($current === null) {
                $inserts[] = $fields;
            } elseif ($this->differs($current, $fields)) {
                $updates[(int) $current['id']] = $fields;
            }
        }
        foreach ($existing as $externalId => $current) {
            if (!isset($seen[(string) $externalId])) {
                $deletes[] = (int) $current['id'];
            }
        }
        if ($inserts === [] && $updates === [] && $deletes === []) {
            return;
        }
        $this->repository->applyProductChanges($inserts, $updates, $deletes);
        $stats->documentsLinked += count($inserts) + count($updates);
        $stats->documentsUnlinked += count($deletes);
    }

    /** Шаг 3: удалить файлы хранилища без единой ссылки (и файлы без строки). */
    public function finish(ApplyStats $stats): void
    {
        $referenced = $this->repository->referencedShas();
        $recorded = $this->repository->files();
        foreach ($recorded as $sha => $bytes) {
            if (isset($referenced[$sha])) {
                continue;
            }
            // Сначала файл, потом строка: строка без файла только вызовет повторное скачивание.
            if ($this->files->delete($sha)) {
                $this->repository->deleteFile($sha);
                unset($this->available[$sha]);
                $stats->documentFilesRemoved++;
            }
        }
        foreach ($this->files->storedShas() as $sha) {
            if (!isset($recorded[$sha]) && !isset($referenced[$sha]) && $this->files->delete($sha)) {
                $stats->documentFilesRemoved++;
            }
        }
    }

    private function recordFile(string $sha256, int $bytes): void
    {
        if (!$this->repository->hasFile($sha256)) {
            $this->repository->addFile($sha256, $bytes, $this->files->relativePath($sha256));
        }
    }

    /** @param array<string, mixed> $current @param array<string, mixed> $desired */
    private function differs(array $current, array $desired): bool
    {
        foreach ($desired as $key => $value) {
            if (($current[$key] ?? null) !== $value) {
                return true;
            }
        }

        return false;
    }
}
