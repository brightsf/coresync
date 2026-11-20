<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

use Okay\Core\Database;
use Okay\Core\QueryFactory;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncDocumentFilesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncProductDocumentsEntity;

/**
 * Таблицы своего хранилища документов: строки файлов по sha256 и документы товара. Только свои
 * две таблицы модуля; каждый запрос проверяется (Database::query при ошибке SQL возвращает false
 * и не бросает).
 */
class ProductDocumentStoreRepository
{
    /** @var Database */
    private $db;
    /** @var QueryFactory */
    private $queryFactory;

    public function __construct(Database $db, QueryFactory $queryFactory)
    {
        $this->db = $db;
        $this->queryFactory = $queryFactory;
    }

    public function hasFile(string $sha256): bool
    {
        $select = $this->queryFactory->newSelect();
        $select->cols(['id'])->from(CoreSyncDocumentFilesEntity::getTable())
            ->where('sha256 = :sha256')->bindValue('sha256', $sha256)->limit(1);
        $this->checked($select, 'чтение файла');

        return $this->db->result('id') !== null;
    }

    public function addFile(string $sha256, int $bytes, string $path): void
    {
        $insert = $this->queryFactory->newInsert();
        $insert->into(CoreSyncDocumentFilesEntity::getTable())
            ->cols(['sha256' => $sha256, 'bytes' => $bytes, 'path' => $path]);
        $this->checked($insert, 'запись файла');
    }

    public function deleteFile(string $sha256): void
    {
        $delete = $this->queryFactory->newDelete();
        $delete->from(CoreSyncDocumentFilesEntity::getTable())
            ->where('sha256 = :sha256')->bindValue('sha256', $sha256);
        $this->checked($delete, 'удаление файла');
    }

    /** @return array<string, int> sha256 => bytes всех файлов хранилища */
    public function files(): array
    {
        $select = $this->queryFactory->newSelect();
        $select->cols(['sha256', 'bytes'])->from(CoreSyncDocumentFilesEntity::getTable());
        $this->checked($select, 'чтение файлов');
        $files = [];
        foreach ($this->db->results() as $row) {
            $files[(string) $row->sha256] = (int) $row->bytes;
        }

        return $files;
    }

    /** @return array<string, bool> sha256, на которые ссылается хотя бы один документ */
    public function referencedShas(): array
    {
        $select = $this->queryFactory->newSelect();
        $select->distinct(true)->cols(['sha256'])->from(CoreSyncProductDocumentsEntity::getTable());
        $this->checked($select, 'чтение ссылок');
        $shas = [];
        foreach ($this->db->results('sha256') as $sha) {
            $shas[(string) $sha] = true;
        }

        return $shas;
    }

    /** @return array<string, array<string, mixed>> document_external_id => строка документа товара */
    public function productDocuments(string $productExternalId): array
    {
        $select = $this->queryFactory->newSelect();
        $select->cols(['id', 'product_external_id', 'product_local_id', 'document_external_id', 'title',
                'position', 'language', 'document_type', 'sha256'])
            ->from(CoreSyncProductDocumentsEntity::getTable())
            ->where('product_external_id = :product_external_id')
            ->bindValue('product_external_id', $productExternalId);
        $this->checked($select, 'чтение документов товара');
        $documents = [];
        foreach ($this->db->results() as $row) {
            $documents[(string) $row->document_external_id] = [
                'id' => (int) $row->id,
                'product_external_id' => (string) $row->product_external_id,
                'product_local_id' => (int) $row->product_local_id,
                'document_external_id' => (string) $row->document_external_id,
                'title' => (string) $row->title,
                'position' => (int) $row->position,
                'language' => $row->language === null ? null : (string) $row->language,
                'document_type' => $row->document_type === null ? null : (string) $row->document_type,
                'sha256' => (string) $row->sha256,
            ];
        }

        return $documents;
    }

    /**
     * Переписать документы одного товара одной транзакцией. Ядро Okay без транзакционного API
     * (непатченная витрина): те же запросы по порядку, каждый проверен; файл к этому моменту уже
     * лежит и записан, поэтому и частичное применение не даёт привязки к отсутствующему файлу.
     *
     * @param list<array<string, mixed>> $inserts
     * @param array<int, array<string, mixed>> $updates id => поля
     * @param list<int> $deleteIds
     */
    public function applyProductChanges(array $inserts, array $updates, array $deleteIds): void
    {
        $queries = [];
        foreach ($deleteIds as $id) {
            $delete = $this->queryFactory->newDelete();
            $delete->from(CoreSyncProductDocumentsEntity::getTable())->where('id = :id')->bindValue('id', (int) $id);
            $queries[] = $delete;
        }
        foreach ($updates as $id => $fields) {
            $update = $this->queryFactory->newUpdate();
            $update->table(CoreSyncProductDocumentsEntity::getTable())->cols($fields)
                ->where('id = :id')->bindValue('id', (int) $id);
            $queries[] = $update;
        }
        foreach ($inserts as $fields) {
            $insert = $this->queryFactory->newInsert();
            $insert->into(CoreSyncProductDocumentsEntity::getTable())->cols($fields);
            $queries[] = $insert;
        }
        if ($queries === []) {
            return;
        }

        $transactional = method_exists($this->db, 'beginTransaction') && !$this->db->inTransaction();
        if ($transactional && $this->db->beginTransaction() !== true) {
            throw new ManifestException('Хранилище документов: транзакция не открылась');
        }
        try {
            foreach ($queries as $query) {
                $this->checked($query, 'запись документов товара');
            }
            if ($transactional && $this->db->commit() !== true) {
                throw new ManifestException('Хранилище документов: транзакция не зафиксирована');
            }
        } catch (\Throwable $e) {
            if ($transactional) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Удалить все документы из ядра (режим disabled). @return int сколько было */
    public function deleteAllDocuments(): int
    {
        $count = $this->queryFactory->newSelect();
        $count->cols(['COUNT(*) AS count'])->from(CoreSyncProductDocumentsEntity::getTable());
        $this->checked($count, 'подсчёт документов');
        $total = (int) $this->db->result('count');
        if ($total > 0) {
            $delete = $this->queryFactory->newDelete();
            $delete->from(CoreSyncProductDocumentsEntity::getTable());
            $this->checked($delete, 'удаление документов');
        }

        return $total;
    }

    /**
     * Документы товара для показа: только с записанным файлом, по порядку из ядра.
     *
     * @return list<array{title:string,sha256:string,bytes:int,path:string}>
     */
    public function displayDocuments(int $productLocalId): array
    {
        $select = $this->queryFactory->newSelect();
        $select->cols(['d.title', 'd.sha256', 'f.bytes', 'f.path'])
            ->from(CoreSyncProductDocumentsEntity::getTable() . ' AS d')
            ->join('INNER', CoreSyncDocumentFilesEntity::getTable() . ' AS f', 'f.sha256 = d.sha256')
            ->where('d.product_local_id = :product_local_id')
            ->bindValue('product_local_id', $productLocalId)
            ->orderBy(['d.position ASC', 'd.id ASC']);
        if ($this->db->query($select) === false) {
            return [];
        }
        $documents = [];
        foreach ($this->db->results() as $row) {
            $documents[] = [
                'title' => (string) $row->title,
                'sha256' => (string) $row->sha256,
                'bytes' => (int) $row->bytes,
                'path' => (string) $row->path,
            ];
        }

        return $documents;
    }

    /** @param \Aura\SqlQuery\QueryInterface $query */
    private function checked($query, string $step): void
    {
        if ($this->db->query($query) === false) {
            throw new ManifestException('Хранилище документов: не удалось выполнить ' . $step);
        }
    }
}
