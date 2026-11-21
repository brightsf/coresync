<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Config;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Apply\ApplyStats;
use Okay\Modules\Format\CoreSync\Core\Apply\ProductDocumentStore;
use Okay\Modules\Format\CoreSync\Core\Apply\ProductDocumentStoreFiles;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\InMemoryDocumentStoreRepository;

require_once __DIR__ . '/Support/InMemoryDocumentStoreRepository.php';

/**
 * Своё хранилище документов: файл раньше привязки, повтор без изменений, pending и не доехавший
 * ready не удаляют имеющееся, общий файл живёт до последней ссылки, disabled убирает только своё.
 * Реальный диск (корень витрины во временном каталоге), таблицы в памяти.
 */
class ProductDocumentStoreTest extends TestCase
{
    /** @var string */
    private $root;
    /** @var string */
    private $staging;
    /** @var InMemoryDocumentStoreRepository */
    private $repo;
    /** @var ProductDocumentStore */
    private $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/coresync_docstore_' . uniqid('', true);
        $this->staging = $this->root . '/files/coresync/7';
        mkdir($this->staging, 0775, true);
        $this->repo = new InMemoryDocumentStoreRepository($this->root);
        $this->store = $this->store($this->repo);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
        parent::tearDown();
    }

    public function testFirstExchangeMovesFilesThenLinksReadyAndSkipsPending(): void
    {
        $a = $this->stage('A');
        $b = $this->stage('B');
        $stats = new ApplyStats();

        $this->store->prepare('store', $this->manifest([$a, $b]), $this->staging, $stats);
        $this->store->reconcileProduct('101', 11, [$this->ready('9001', $a, 0), $this->pending('9002', 1)], $stats);
        $this->store->reconcileProduct('102', 12, [$this->ready('9003', $a, 0), $this->ready('9004', $b, 1)], $stats);
        $this->store->finish($stats);

        $this->assertSame(['9001' => $a['sha']], $this->repo->links('101'));
        $this->assertSame(['9003' => $a['sha'], '9004' => $b['sha']], $this->repo->links('102'));
        $this->assertFileExists($this->stored($a));
        $this->assertFileExists($this->stored($b));
        $this->assertFileDoesNotExist($this->staged($a), 'файл перенесён, а не скопирован');
        $this->assertSame(2, $stats->documentFilesDownloaded);
        $this->assertSame(3, $stats->documentsLinked);
        $this->assertSame(1, $stats->documentsPending);
        $this->assertSame(0, $stats->documentFilesRemoved);
        $this->assertSame(['file+' . $a['sha'], 'file+' . $b['sha']], array_slice($this->repo->log, 0, 2), 'файлы раньше привязок');
        $this->assertSame('0644', substr(sprintf('%o', fileperms($this->stored($a))), -4));
    }

    /** Acceptance 1: тот же снимок второй раз ничего не принимает и ничего не пишет. */
    public function testRepeatWithSameDocumentsIsNoop(): void
    {
        $a = $this->stage('A');
        $this->exchange([$a], ['101' => [$this->ready('9001', $a, 0)]]);
        $writes = $this->repo->writes;
        $mtime = filemtime($this->stored($a));

        $stats = $this->exchange([$a], ['101' => [$this->ready('9001', $a, 0)]]);

        $this->assertSame($writes, $this->repo->writes);
        $this->assertSame(0, $stats->documentFilesDownloaded);
        $this->assertSame(0, $stats->documentsLinked + $stats->documentsUnlinked + $stats->documentFilesRemoved);
        $this->assertSame($mtime, filemtime($this->stored($a)));
    }

    public function testTitleOrPositionChangeUpdatesInPlace(): void
    {
        $a = $this->stage('A');
        $this->exchange([$a], ['101' => [$this->ready('9001', $a, 0)]]);
        $document = $this->ready('9001', $a, 3);
        $document['title'] = 'Паспорт, ред. 2';

        $stats = $this->exchange([$a], ['101' => [$document]]);

        $this->assertSame(1, $stats->documentsLinked);
        $row = array_values($this->repo->productDocuments('101'))[0];
        $this->assertSame('Паспорт, ред. 2', $row['title']);
        $this->assertSame(3, $row['position']);
    }

    /** Acceptance 4: pending по external_id имеющееся не трогает. */
    public function testPendingKeepsExistingDocument(): void
    {
        $a = $this->stage('A');
        $this->exchange([$a], ['101' => [$this->ready('9001', $a, 0)]]);

        $stats = $this->exchange([], ['101' => [$this->pending('9001', 0)]]);

        $this->assertSame(['9001' => $a['sha']], $this->repo->links('101'));
        $this->assertFileExists($this->stored($a));
        $this->assertSame(1, $stats->documentsPending);
        $this->assertSame(0, $stats->documentsUnlinked + $stats->documentFilesRemoved);
    }

    /** Acceptance 4: ready, чей файл не доехал (лимит), ведёт себя как pending: прежняя копия остаётся. */
    public function testReadyWithoutArrivedFileKeepsPreviousCopy(): void
    {
        $a = $this->stage('A');
        $this->exchange([$a], ['101' => [$this->ready('9001', $a, 0)]]);
        $c = $this->spec('C'); // в пакете, но не скачан (staging пуст)

        $stats = $this->exchange([$c], ['101' => [$this->ready('9001', $c, 0)]]);

        $this->assertSame(['9001' => $a['sha']], $this->repo->links('101'));
        $this->assertFileExists($this->stored($a), 'прежняя копия не удалена уборкой');
        $this->assertSame(1, $stats->documentFilesDeferred);
        $this->assertSame(1, $stats->documentsPending);

        // Следующий обмен довозит файл: привязка переключается, прежняя копия уходит.
        $this->stage('C');
        $next = $this->exchange([$c], ['101' => [$this->ready('9001', $c, 0)]]);

        $this->assertSame(['9001' => $c['sha']], $this->repo->links('101'));
        $this->assertFileDoesNotExist($this->stored($a));
        $this->assertSame(1, $next->documentFilesRemoved);
    }

    public function testMissingExternalIdIsUnlinkedAndOrphanFileRemoved(): void
    {
        $a = $this->stage('A');
        $b = $this->stage('B');
        $this->exchange([$a, $b], ['101' => [$this->ready('9001', $a, 0), $this->ready('9002', $b, 1)]]);

        $stats = $this->exchange([$a], ['101' => [$this->ready('9001', $a, 0)]]);

        $this->assertSame(['9001' => $a['sha']], $this->repo->links('101'));
        $this->assertSame(1, $stats->documentsUnlinked);
        $this->assertSame(1, $stats->documentFilesRemoved);
        $this->assertFileDoesNotExist($this->stored($b));
        $this->assertArrayNotHasKey($b['sha'], $this->repo->files);
    }

    /** Пустой список в store: у товара документов нет. */
    public function testEmptyListUnlinksEverything(): void
    {
        $a = $this->stage('A');
        $this->exchange([$a], ['101' => [$this->ready('9001', $a, 0)]]);

        $this->exchange([], ['101' => []]);

        $this->assertSame([], $this->repo->links('101'));
        $this->assertFileDoesNotExist($this->stored($a));
    }

    /** Acceptance 3: общий файл двух товаров удаляется только когда отвязаны оба. */
    public function testSharedFileIsRemovedOnlyAfterBothProductsUnlink(): void
    {
        $a = $this->stage('A');
        $this->exchange([$a], ['101' => [$this->ready('9001', $a, 0)], '102' => [$this->ready('9003', $a, 0)]]);

        $this->exchange([$a], ['101' => [], '102' => [$this->ready('9003', $a, 0)]]);
        $this->assertFileExists($this->stored($a), 'второй товар ещё ссылается');
        $this->assertArrayHasKey($a['sha'], $this->repo->files);

        $this->exchange([], ['102' => []]);
        $this->assertFileDoesNotExist($this->stored($a));
        $this->assertArrayNotHasKey($a['sha'], $this->repo->files);
    }

    /** Acceptance 4: disabled удаляет только документы и файлы хранилища модуля. */
    public function testDisabledRemovesOnlyStoreDocumentsAndFiles(): void
    {
        $a = $this->stage('A');
        $this->exchange([$a], ['101' => [$this->ready('9001', $a, 0)]]);
        $foreign = [
            $this->root . '/files/documents/' . $a['sha'] . '.pdf',
            $this->root . '/files/coresync/documents/readme.txt',
            $this->root . '/files/coresync/documents/' . strtoupper($a['sha']) . '.pdf',
            $this->staging . '/products-0001.ndjson.gz',
        ];
        foreach ($foreign as $path) {
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, 'foreign');
        }
        $stats = new ApplyStats();

        $this->store->prepare('disabled', $this->manifest([]), $this->staging, $stats);

        $this->assertSame([], $this->repo->documents);
        $this->assertSame([], $this->repo->files);
        $this->assertFileDoesNotExist($this->stored($a));
        foreach ($foreign as $path) {
            $this->assertFileExists($path, 'чужой файл не тронут: ' . $path);
        }
        $this->assertSame(1, $stats->documentsUnlinked);
        $this->assertSame(1, $stats->documentFilesRemoved);
    }

    /**
     * Acceptance 2: обрыв после переноса файла и до записи привязок. Файл лежит без привязок,
     * следующий обмен ничего не качает (файл уже в хранилище) и доводит привязки.
     */
    public function testCrashAfterFileMoveBeforeLinksIsCompletedByNextExchange(): void
    {
        $a = $this->stage('A');
        $this->repo->failNextProductChanges = new \RuntimeException('обрыв');
        try {
            $this->exchange([$a], ['101' => [$this->ready('9001', $a, 0)]]);
            $this->fail('обрыв должен пробросить');
        } catch (\RuntimeException $e) {
            $this->assertSame('обрыв', $e->getMessage());
        }
        $this->assertSame([], $this->repo->documents);
        $this->assertFileExists($this->stored($a));
        $this->assertArrayHasKey($a['sha'], $this->repo->files);

        $stats = $this->exchange([$a], ['101' => [$this->ready('9001', $a, 0)]]);

        $this->assertSame(['9001' => $a['sha']], $this->repo->links('101'));
        $this->assertSame(0, $stats->documentFilesDownloaded, 'файл не принимался повторно');
    }

    /** Обрыв между rename и строкой файла: файл без строки принимается после полной сверки. */
    public function testCrashBetweenMoveAndFileRowAdoptsVerifiedFile(): void
    {
        $a = $this->stage('A');
        $this->repo->failNextAddFile = new \RuntimeException('обрыв');
        try {
            $this->exchange([$a], ['101' => [$this->ready('9001', $a, 0)]]);
            $this->fail('обрыв должен пробросить');
        } catch (\RuntimeException $e) {
            $this->assertSame([], $this->repo->files);
        }
        $this->assertFileExists($this->stored($a));

        $this->exchange([$a], ['101' => [$this->ready('9001', $a, 0)]]);

        $this->assertSame(['9001' => $a['sha']], $this->repo->links('101'));
        $this->assertArrayHasKey($a['sha'], $this->repo->files);
    }

    /** Подменённый файл без строки не принимается: остаётся недоступным и убирается уборкой. */
    public function testTamperedFileWithoutRowIsNotAdopted(): void
    {
        $a = $this->spec('A');
        @mkdir(dirname($this->stored($a)), 0775, true);
        file_put_contents($this->stored($a), '%PDF-tampered!');

        $stats = $this->exchange([$a], ['101' => [$this->ready('9001', $a, 0)]]);

        $this->assertSame([], $this->repo->links('101'));
        $this->assertSame(1, $stats->documentFilesDeferred);
        $this->assertFileDoesNotExist($this->stored($a), 'файл без строки и без ссылок убран');
    }

    public function testCorruptStagedFileIsNotMovedOrLinked(): void
    {
        $a = $this->spec('A');
        file_put_contents($this->staged($a), '%PDF-other bytes');

        $stats = $this->exchange([$a], ['101' => [$this->ready('9001', $a, 0)]]);

        $this->assertSame([], $this->repo->links('101'));
        $this->assertFileDoesNotExist($this->stored($a));
        $this->assertSame(1, $stats->documentFilesDeferred);
    }

    public function testSymlinkedStoreDirectoryIsRefused(): void
    {
        $outside = $this->root . '/outside';
        mkdir($outside, 0775, true);
        symlink($outside, $this->root . '/files/coresync/documents');
        $a = $this->stage('A');

        $this->expectException(ManifestException::class);
        try {
            $this->store->prepare('store', $this->manifest([$a]), $this->staging, new ApplyStats());
        } finally {
            $this->assertSame([], array_values(array_diff(scandir($outside), ['.', '..'])));
        }
    }

    /** Каталог хранилища подменён симлинком наружу: уборка ничего за пределами корня не удаляет. */
    public function testCleanupNeverDeletesThroughSymlinkedStoreDirectory(): void
    {
        $outside = $this->root . '/outside';
        mkdir($outside, 0775, true);
        $a = $this->spec('A');
        file_put_contents($outside . '/' . $a['sha'] . '.pdf', $a['body']);
        symlink($outside, $this->root . '/files/coresync/documents');
        $this->repo->files[$a['sha']] = ['bytes' => $a['bytes'], 'path' => 'x'];
        $stats = new ApplyStats();

        $this->store->finish($stats);

        $this->assertFileExists($outside . '/' . $a['sha'] . '.pdf');
        $this->assertFalse($this->store->hasStoredFile($a['sha'], $a['bytes']));
        $this->assertSame(0, $stats->documentFilesRemoved);
    }

    /** Файл, не нужный витрине (товара на ней нет), не переносится и не считается отложенным. */
    public function testPrepareTakesOnlyNeededFiles(): void
    {
        $a = $this->stage('A');
        $b = $this->stage('B');
        $stats = new ApplyStats();

        $available = $this->store->prepare('store', $this->manifest([$a, $b]), $this->staging, $stats, [$a['sha'] => true]);

        $this->assertSame([$a['sha'] => true], $available);
        $this->assertFileExists($this->stored($a));
        $this->assertFileDoesNotExist($this->stored($b));
        $this->assertFileExists($this->staged($b), 'ненужный файл не тронут');
        $this->assertSame(1, $stats->documentFilesDownloaded);
        $this->assertSame(0, $stats->documentFilesDeferred);
    }

    public function testHasStoredFileRejectsNonHashNames(): void
    {
        $this->assertFalse($this->store->hasStoredFile('../../index', 1));
        $this->expectException(ManifestException::class);
        (new ProductDocumentStoreFiles($this->config()))->relativePath('../x');
    }

    public function testDownloadBudgetDefaultsTo512MiBAndReadsSetting(): void
    {
        $this->assertSame(536870912, $this->store->downloadBudget());
        $configured = $this->store($this->repo, [Contract::SETTINGS_DOCUMENTS_MAX_BYTES_FIELD => 1048576]);
        $this->assertSame(1048576, $configured->downloadBudget());
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Один обмен: prepare, товары по порядку, уборка.
     *
     * @param list<array{sha:string,bytes:int}> $files
     * @param array<string, list<array<string, mixed>>> $products
     */
    private function exchange(array $files, array $products): ApplyStats
    {
        $stats = new ApplyStats();
        $this->store->prepare('store', $this->manifest($files), $this->staging, $stats);
        $localIds = ['101' => 11, '102' => 12];
        foreach ($products as $externalId => $documents) {
            $this->store->reconcileProduct((string) $externalId, $localIds[(string) $externalId], $documents, $stats);
        }
        $this->store->finish($stats);

        return $stats;
    }

    /** @param array<string, mixed> $settings */
    private function store(InMemoryDocumentStoreRepository $repo, array $settings = []): ProductDocumentStore
    {
        $settingsMock = $this->createMock(Settings::class);
        $settingsMock->method('get')->willReturn($settings);

        return new ProductDocumentStore($repo, new ProductDocumentStoreFiles($this->config()), $settingsMock);
    }

    private function config(): Config
    {
        $config = $this->getMockBuilder(Config::class)->disableOriginalConstructor()->onlyMethods(['get'])->getMock();
        $root = $this->root;
        $config->method('get')->willReturnCallback(static function (string $key) use ($root) {
            return $key === 'root_dir' ? $root : null;
        });

        return $config;
    }

    /** @return array{sha:string,bytes:int,body:string} */
    private function spec(string $label): array
    {
        $body = '%PDF-1.4 branded copy ' . $label . "\n%%EOF\n";

        return ['sha' => hash('sha256', $body), 'bytes' => strlen($body), 'body' => $body];
    }

    /** @return array{sha:string,bytes:int,body:string} */
    private function stage(string $label): array
    {
        $spec = $this->spec($label);
        file_put_contents($this->staged($spec), $spec['body']);

        return $spec;
    }

    /** @param array{sha:string} $spec */
    private function staged(array $spec): string
    {
        return $this->staging . '/document-' . $spec['sha'] . '.pdf';
    }

    /** @param array{sha:string} $spec */
    private function stored(array $spec): string
    {
        return $this->root . '/files/coresync/documents/' . $spec['sha'] . '.pdf';
    }

    /**
     * @param list<array{sha:string,bytes:int}> $files
     * @return array<string, mixed>
     */
    private function manifest(array $files): array
    {
        $entries = [['name' => 'products-0001.ndjson.gz', 'sha256' => str_repeat('0', 64), 'bytes' => 1, 'rows' => 1]];
        foreach ($files as $file) {
            $entries[] = [
                'name' => 'document-' . $file['sha'] . '.pdf', 'sha256' => $file['sha'], 'bytes' => $file['bytes'],
                'rows' => 0, 'role' => 'product_document', 'kind' => 'application/pdf',
            ];
        }

        return ['product_documents' => ['mode' => 'store'], 'files' => $entries];
    }

    /** @param array{sha:string,bytes:int} $file @return array<string, mixed> */
    private function ready(string $externalId, array $file, int $position): array
    {
        return [
            'external_id' => $externalId, 'title' => 'Паспорт ' . $externalId, 'position' => $position,
            'language' => 'ru', 'type' => 'passport', 'state' => 'ready',
            'file' => 'document-' . $file['sha'] . '.pdf', 'sha256' => $file['sha'], 'bytes' => $file['bytes'],
            'original_filename' => 'passport.pdf',
        ];
    }

    /** @return array<string, mixed> */
    private function pending(string $externalId, int $position): array
    {
        return [
            'external_id' => $externalId, 'title' => 'Инструкция ' . $externalId, 'position' => $position,
            'language' => null, 'type' => null, 'state' => 'pending',
            'file' => null, 'sha256' => null, 'bytes' => null, 'original_filename' => null,
        ];
    }

    private function rrmdir(string $dir): void
    {
        if (is_link($dir)) {
            @unlink($dir);
            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) && !is_link($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
