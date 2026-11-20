<?php

namespace Tests\Modules\Format\CoreSync;

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Okay\Core\Database;
use Okay\Core\QueryFactory;
use Okay\Modules\Format\CoreSync\Core\Apply\ProductDocumentStoreRepository;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use PHPUnit\Framework\TestCase;

/**
 * SQL своего хранилища: только две таблицы модуля, каждый запрос проверен, документы товара пишутся
 * одной пачкой (снятие, изменение, вставка), отказ SQL громкий.
 */
class ProductDocumentStoreRepositoryTest extends TestCase
{
    /** @var list<array{0:string,1:array<string,mixed>}> */
    private $queries = [];
    /** @var bool|null */
    private $failAt;

    public function testProductChangesTouchOnlyTheModuleTableInOrder(): void
    {
        $this->repository()->applyProductChanges(
            [['product_external_id' => '101', 'product_local_id' => 11, 'document_external_id' => '9001',
                'title' => 'T', 'position' => 0, 'language' => null, 'document_type' => null, 'sha256' => str_repeat('a', 64)]],
            [7 => ['title' => 'U']],
            [5]
        );

        $this->assertCount(3, $this->queries);
        $this->assertStringStartsWith('DELETE FROM `__format__coresync_product_documents`', $this->normalized(0));
        $this->assertSame(5, $this->queries[0][1]['id']);
        $this->assertStringStartsWith('UPDATE `__format__coresync_product_documents`', $this->normalized(1));
        $this->assertSame(7, $this->queries[1][1]['id']);
        $this->assertStringStartsWith('INSERT INTO `__format__coresync_product_documents`', $this->normalized(2));
    }

    public function testNoChangesRunNoQuery(): void
    {
        $this->repository()->applyProductChanges([], [], []);

        $this->assertSame([], $this->queries);
    }

    public function testFailedQueryIsLoud(): void
    {
        $this->failAt = true;

        $this->expectException(ManifestException::class);
        $this->repository()->applyProductChanges([], [], [5]);
    }

    public function testFileRowsLiveInTheirOwnTable(): void
    {
        $repository = $this->repository();
        $sha = str_repeat('b', 64);

        $repository->addFile($sha, 72, 'files/coresync/documents/' . $sha . '.pdf');
        $repository->deleteFile($sha);

        $this->assertStringStartsWith('INSERT INTO `__format__coresync_document_files`', $this->normalized(0));
        $this->assertStringStartsWith('DELETE FROM `__format__coresync_document_files`', $this->normalized(1));
        $this->assertSame($sha, $this->queries[1][1]['sha256']);
        $this->assertStringContainsString('WHERE', $this->normalized(1), 'удаление файла адресное');
    }

    public function testDeleteAllDocumentsClearsOnlyProductDocuments(): void
    {
        $this->repository(3)->deleteAllDocuments();

        $this->assertCount(2, $this->queries);
        $this->assertStringStartsWith('DELETE FROM `__format__coresync_product_documents`', $this->normalized(1));
    }

    private function normalized(int $index): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $this->queries[$index][0]));
    }

    private function repository(int $count = 0): ProductDocumentStoreRepository
    {
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(function ($query) {
            $this->queries[] = [(string) $query->getStatement(), $query->getBindValues()];

            return $this->failAt ? false : true;
        });
        $db->method('result')->willReturn($count);
        $db->method('results')->willReturn([]);

        // Обёртки Okay QueryFactory тянут ServiceLocator; Aura-запросы дают тот же API построения.
        $queryFactory = $this->createMock(QueryFactory::class);
        foreach (['newSelect', 'newInsert', 'newUpdate', 'newDelete'] as $method) {
            $queryFactory->method($method)->willReturnCallback(static function () use ($method) {
                return (new AuraQueryFactory('mysql'))->{$method}();
            });
        }

        return new ProductDocumentStoreRepository($db, $queryFactory);
    }
}
