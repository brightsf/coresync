<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Config;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Apply\ProductDocumentStore;
use Okay\Modules\Format\CoreSync\Core\Apply\ProductDocumentStoreFiles;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\SnapshotDownloader;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\FakeHttpClient;
use Tests\Modules\Format\CoreSync\Support\InMemoryCheckpointStore;
use Tests\Modules\Format\CoreSync\Support\InMemoryDocumentStoreRepository;

require_once __DIR__ . '/Support/FakeHttpClient.php';
require_once __DIR__ . '/Support/InMemoryCheckpointStore.php';
require_once __DIR__ . '/Support/InMemoryDocumentStoreRepository.php';

/**
 * Скачивание документов при своём хранилище: хеш, который уже лежит в хранилище, не качается;
 * объём документов за обмен ограничен настройкой, остаток ждёт следующего обмена.
 */
class SnapshotDownloaderDocumentStoreTest extends TestCase
{
    /** @var string */
    private $root;
    /** @var InMemoryDocumentStoreRepository */
    private $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/coresync_dl_docs_' . uniqid('', true);
        mkdir($this->root . '/files/coresync/documents', 0775, true);
        $this->repo = new InMemoryDocumentStoreRepository($this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    /** Kill-проба "скачивание имеющегося хеша": имеющийся в хранилище файл не запрашивается у ядра. */
    public function testStoredHashIsNotDownloaded(): void
    {
        $stored = $this->pdf('stored');
        $fresh = $this->pdf('fresh');
        $this->putInStore($stored);
        $http = new FakeHttpClient($this->contents([$stored, $fresh]) + ['products-0001.ndjson.gz' => 'rows']);
        $checkpoints = new InMemoryCheckpointStore();

        $status = $this->downloader($http)->download('https://core', '1', 't',
            $this->files([$stored, $fresh]), $this->staging(), $checkpoints, $this->noCancel());

        $this->assertSame(Contract::STATUS_DOWNLOADED, $status);
        $this->assertSame(['products-0001.ndjson.gz', $fresh['name']], $http->downloadedNames);
        $this->assertFileDoesNotExist($this->staging() . '/' . $stored['name']);
    }

    /** Файл со строкой, но без файла на диске (или не того размера), качается заново. */
    public function testRecordedButMissingFileIsDownloaded(): void
    {
        $doc = $this->pdf('lost');
        $this->repo->addFile($doc['sha'], $doc['bytes'], 'x');
        $http = new FakeHttpClient($this->contents([$doc]) + ['products-0001.ndjson.gz' => 'rows']);

        $this->downloader($http)->download('https://core', '1', 't', $this->files([$doc]), $this->staging(),
            new InMemoryCheckpointStore(), $this->noCancel());

        $this->assertContains($doc['name'], $http->downloadedNames);
    }

    public function testDocumentBytesPerExchangeAreLimitedAndRestWaits(): void
    {
        $docs = [$this->pdf('one', 40), $this->pdf('two', 40), $this->pdf('three', 40)];
        $http = new FakeHttpClient($this->contents($docs) + ['products-0001.ndjson.gz' => 'rows']);
        $checkpoints = new InMemoryCheckpointStore();
        $limit = $docs[0]['bytes'] + $docs[1]['bytes'];

        $this->downloader($http, $limit)->download('https://core', '1', 't', $this->files($docs), $this->staging(),
            $checkpoints, $this->noCancel());

        $this->assertSame(['products-0001.ndjson.gz', $docs[0]['name'], $docs[1]['name']], $http->downloadedNames);
        $this->assertNotSame(Contract::FILE_VERIFIED, $checkpoints->getStatus($docs[2]['name']));
        $this->assertFileDoesNotExist($this->staging() . '/' . $docs[2]['name']);

        // Следующий обмен: первые два уже в хранилище (их перенёс apply), качается остаток.
        $this->putInStore($docs[0]);
        $this->putInStore($docs[1]);
        @unlink($this->staging() . '/' . $docs[0]['name']);
        @unlink($this->staging() . '/' . $docs[1]['name']);
        $next = new FakeHttpClient($this->contents($docs) + ['products-0001.ndjson.gz' => 'rows']);

        $this->downloader($next, $limit)->download('https://core', '1', 't', $this->files($docs), $this->staging(),
            new InMemoryCheckpointStore(), $this->noCancel());

        $this->assertSame(['products-0001.ndjson.gz', $docs[2]['name']], $next->downloadedNames);
    }

    /** Один файл больше лимита всё равно качается, иначе обмен не продвинулся бы никогда. */
    public function testFirstDocumentIsDownloadedEvenAboveLimit(): void
    {
        $docs = [$this->pdf('big', 200), $this->pdf('next', 10)];
        $http = new FakeHttpClient($this->contents($docs) + ['products-0001.ndjson.gz' => 'rows']);

        $this->downloader($http, 50)->download('https://core', '1', 't', $this->files($docs), $this->staging(),
            new InMemoryCheckpointStore(), $this->noCancel());

        $this->assertSame(['products-0001.ndjson.gz', $docs[0]['name']], $http->downloadedNames);
    }

    /** Без хранилища (старый конструктор) скачивание прежнее: все документы пакета. */
    public function testWithoutStoreEveryDocumentIsDownloaded(): void
    {
        $docs = [$this->pdf('one', 40), $this->pdf('two', 40)];
        $this->putInStore($docs[0]);
        $http = new FakeHttpClient($this->contents($docs) + ['products-0001.ndjson.gz' => 'rows']);

        (new SnapshotDownloader($http, null))->download('https://core', '1', 't', $this->files($docs),
            $this->staging(), new InMemoryCheckpointStore(), $this->noCancel());

        $this->assertSame(['products-0001.ndjson.gz', $docs[0]['name'], $docs[1]['name']], $http->downloadedNames);
    }

    private function downloader(FakeHttpClient $http, ?int $limit = null): SnapshotDownloader
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturn(
            $limit === null ? [] : [Contract::SETTINGS_DOCUMENTS_MAX_BYTES_FIELD => $limit]
        );
        $config = $this->getMockBuilder(Config::class)->disableOriginalConstructor()->onlyMethods(['get'])->getMock();
        $root = $this->root;
        $config->method('get')->willReturnCallback(static function (string $key) use ($root) {
            return $key === 'root_dir' ? $root : null;
        });

        return new SnapshotDownloader($http, null,
            new ProductDocumentStore($this->repo, new ProductDocumentStoreFiles($config), $settings));
    }

    private function staging(): string
    {
        return $this->root . '/files/coresync/9';
    }

    /** @return array{name:string,sha:string,bytes:int,body:string} */
    private function pdf(string $label, int $padding = 0): array
    {
        $body = '%PDF-1.4 ' . $label . str_repeat('.', $padding) . "\n";
        $sha = hash('sha256', $body);

        return ['name' => 'document-' . $sha . '.pdf', 'sha' => $sha, 'bytes' => strlen($body), 'body' => $body];
    }

    /** @param array{name:string,sha:string,bytes:int,body:string} $doc */
    private function putInStore(array $doc): void
    {
        file_put_contents($this->root . '/files/coresync/documents/' . $doc['sha'] . '.pdf', $doc['body']);
        $this->repo->addFile($doc['sha'], $doc['bytes'], 'files/coresync/documents/' . $doc['sha'] . '.pdf');
    }

    /** @param list<array{name:string,body:string}> $docs @return array<string, string> */
    private function contents(array $docs): array
    {
        $contents = [];
        foreach ($docs as $doc) {
            $contents[$doc['name']] = $doc['body'];
        }

        return $contents;
    }

    /** @param list<array{name:string,sha:string,bytes:int}> $docs @return list<array<string, mixed>> */
    private function files(array $docs): array
    {
        $files = [['name' => 'products-0001.ndjson.gz', 'sha256' => hash('sha256', 'rows'), 'bytes' => 4, 'rows' => 1]];
        foreach ($docs as $doc) {
            $files[] = ['name' => $doc['name'], 'sha256' => $doc['sha'], 'bytes' => $doc['bytes'], 'rows' => 0,
                'role' => 'product_document', 'kind' => 'application/pdf'];
        }

        return $files;
    }

    private function noCancel(): callable
    {
        return static function (): bool {
            return false;
        };
    }
}
