<?php

namespace Tests\Modules\Format\CoreSync\Support;

use Okay\Modules\Format\CoreSync\Core\Apply\ProductDocumentStoreRepository;

/**
 * Таблицы хранилища документов в памяти. Инвариант "привязки к отсутствующему файлу нет ни в
 * какой момент" проверяется в момент каждой записи привязки: строка файла и сам файл на диске
 * обязаны уже существовать, иначе исключение (kill-проба порядка "файл, затем привязка").
 */
final class InMemoryDocumentStoreRepository extends ProductDocumentStoreRepository
{
    /** @var array<string, array{bytes:int,path:string}> */
    public $files = [];
    /** @var array<int, array<string, mixed>> */
    public $documents = [];
    /** @var list<string> журнал записей по порядку */
    public $log = [];
    /** @var int */
    public $writes = 0;
    /** @var string корень витрины: файл привязки обязан лежать на диске */
    private $root;
    /** @var int */
    private $nextId = 1;
    /** @var \Throwable|null бросить на следующей записи документов товара (обрыв) */
    public $failNextProductChanges;
    /** @var \Throwable|null бросить на следующей записи строки файла (обрыв после rename) */
    public $failNextAddFile;

    public function __construct(string $root)
    {
        $this->root = $root;
    }

    public function hasFile(string $sha256): bool
    {
        return isset($this->files[$sha256]);
    }

    public function addFile(string $sha256, int $bytes, string $path): void
    {
        if ($this->failNextAddFile !== null) {
            $failure = $this->failNextAddFile;
            $this->failNextAddFile = null;
            throw $failure;
        }
        $this->files[$sha256] = ['bytes' => $bytes, 'path' => $path];
        $this->log[] = 'file+' . $sha256;
        $this->writes++;
    }

    public function deleteFile(string $sha256): void
    {
        unset($this->files[$sha256]);
        $this->log[] = 'file-' . $sha256;
        $this->writes++;
    }

    public function files(): array
    {
        $files = [];
        foreach ($this->files as $sha => $row) {
            $files[$sha] = $row['bytes'];
        }

        return $files;
    }

    public function referencedShas(): array
    {
        $shas = [];
        foreach ($this->documents as $row) {
            $shas[$row['sha256']] = true;
        }

        return $shas;
    }

    public function productDocuments(string $productExternalId): array
    {
        $documents = [];
        foreach ($this->documents as $row) {
            if ($row['product_external_id'] === $productExternalId) {
                $documents[$row['document_external_id']] = $row;
            }
        }

        return $documents;
    }

    public function applyProductChanges(array $inserts, array $updates, array $deleteIds): void
    {
        if ($this->failNextProductChanges !== null) {
            $failure = $this->failNextProductChanges;
            $this->failNextProductChanges = null;
            throw $failure;
        }
        foreach (array_merge($inserts, array_values($updates)) as $fields) {
            $this->assertFilePresent((string) $fields['sha256']);
        }
        foreach ($deleteIds as $id) {
            $this->log[] = 'doc-' . $this->documents[$id]['document_external_id'];
            unset($this->documents[$id]);
            $this->writes++;
        }
        foreach ($updates as $id => $fields) {
            $this->documents[$id] = ['id' => $id] + $fields;
            $this->log[] = 'doc~' . $fields['document_external_id'];
            $this->writes++;
        }
        foreach ($inserts as $fields) {
            $id = $this->nextId++;
            $this->documents[$id] = ['id' => $id] + $fields;
            $this->log[] = 'doc+' . $fields['document_external_id'];
            $this->writes++;
        }
    }

    public function deleteAllDocuments(): int
    {
        $count = count($this->documents);
        $this->documents = [];
        $this->writes += $count;

        return $count;
    }

    public function displayDocuments(int $productLocalId): array
    {
        $rows = array_filter($this->documents, static function (array $row) use ($productLocalId): bool {
            return $row['product_local_id'] === $productLocalId;
        });
        usort($rows, static function (array $a, array $b): int {
            return [$a['position'], $a['id']] <=> [$b['position'], $b['id']];
        });
        $documents = [];
        foreach ($rows as $row) {
            if (isset($this->files[$row['sha256']])) {
                $documents[] = [
                    'title' => $row['title'],
                    'sha256' => $row['sha256'],
                    'bytes' => $this->files[$row['sha256']]['bytes'],
                    'path' => $this->files[$row['sha256']]['path'],
                ];
            }
        }

        return $documents;
    }

    /** @return array<string, string> document_external_id => sha256 привязок товара */
    public function links(string $productExternalId): array
    {
        $links = [];
        foreach ($this->productDocuments($productExternalId) as $externalId => $row) {
            $links[$externalId] = $row['sha256'];
        }
        ksort($links, SORT_STRING);

        return $links;
    }

    private function assertFilePresent(string $sha256): void
    {
        $path = $this->root . '/files/coresync/documents/' . $sha256 . '.pdf';
        if (!isset($this->files[$sha256]) || !is_file($path)) {
            throw new \LogicException('Привязка к отсутствующему файлу ' . $sha256);
        }
    }
}
