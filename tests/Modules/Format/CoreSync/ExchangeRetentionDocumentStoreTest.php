<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Config;
use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\QueryFactory;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Apply\ProductDocumentStore;
use Okay\Modules\Format\CoreSync\Core\Apply\ProductDocumentStoreFiles;
use Okay\Modules\Format\CoreSync\Core\Apply\ProductDocumentStoreRepository;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\ExchangeRetentionEvidence;
use Okay\Modules\Format\CoreSync\Core\ExchangeRetentionManager;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncJobsEntity;
use PHPUnit\Framework\TestCase;

/**
 * Ротация staging при своём хранилище документов: документ store со статусом applied (перенесён в
 * хранилище и сверен либо не нужен витрине) не держит версию навсегда, хотя в staging его нет.
 * Отложенный лимитом документ (pending) версию держит. Старый путь applied у документов не ставит:
 * PDF в staging проверяется через ProductDocuments, пропавший держит версию, как в 1.8.4.
 * Таблицы хранилища ротация не читает (до update_1_9_0 их может не быть).
 */
class ExchangeRetentionDocumentStoreTest extends TestCase
{
    private const VERSION = 10;

    /** @var string */
    private $root;
    /** @var string */
    private $ndjson = 'rows';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/coresync_retention_' . uniqid('', true);
        mkdir($this->root . '/files/coresync/' . self::VERSION, 0775, true);
        file_put_contents($this->stage('products-0001.ndjson.gz'), $this->ndjson);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    /**
     * Документы store закрыты applied (A перенесён, B не нужен витрине или догнан): версия
     * удаляется по политике. Kill-проба: applied у документа не учитывается -> красный.
     */
    public function testHandledStoreDocumentsDoNotPinAppliedVersion(): void
    {
        $a = $this->doc('A');
        $b = $this->doc('B');
        $manager = $this->manager([
            $this->ndjsonRow(),
            $this->row($a, Contract::FILE_APPLIED),
            $this->row($b, Contract::FILE_APPLIED),
        ]);

        $item = $this->item($manager);

        $this->assertSame('delete', $item['decision'], (string) $item['reason']);
        $result = $manager->apply(null, $manager->preview(null)['plan_sha256']);
        $this->assertSame(1, $result['deleted_count']);
        $this->assertDirectoryDoesNotExist($this->root . '/files/coresync/' . self::VERSION);
    }

    /** Store-пакет с сотнями PDF (t-ind): строк job больше 128, staging почти пуст. */
    public function testLargeStorePackageIsNotUnknown(): void
    {
        $rows = [$this->ndjsonRow()];
        for ($i = 0; $i < 300; $i++) {
            $rows[] = $this->row($this->doc('doc' . $i), Contract::FILE_APPLIED);
        }

        $this->assertSame('delete', $this->item($this->manager($rows))['decision']);
    }

    /** Отложенный лимитом документ (нет в хранилище, нет в staging) держит версию для догона. */
    public function testDeferredDocumentKeepsVersion(): void
    {
        $a = $this->doc('A');
        $b = $this->doc('B');

        $item = $this->item($this->manager([
            $this->ndjsonRow(),
            $this->row($a, Contract::FILE_APPLIED),
            $this->row($b, Contract::FILE_PENDING),
        ]));

        $this->assertSame('keep', $item['decision']);
        $this->assertSame('unsafe_checkpoint', $item['reason']);
    }

    /** Старый путь: PDF в staging без признака хранилища проверяется ProductDocuments, как раньше. */
    public function testLegacyStagedDocumentStillRequiresProductDocumentsProof(): void
    {
        $a = $this->doc('A');
        file_put_contents($this->stage($a['name']), $a['body']);
        $rows = [$this->ndjsonRow(), $this->row($a, Contract::FILE_VERIFIED)];

        $refused = $this->item($this->manager($rows, false));
        $proved = $this->item($this->manager($rows, true));

        $this->assertSame('published_document_unverified', $refused['reason']);
        $this->assertSame('keep', $refused['decision']);
        $this->assertSame('delete', $proved['decision']);
    }

    /** Старый путь: пропавший из staging PDF без хранилища по-прежнему держит версию. */
    public function testLegacyMissingDocumentStillKeepsVersion(): void
    {
        $a = $this->doc('A');

        $item = $this->item($this->manager([$this->ndjsonRow(), $this->row($a, Contract::FILE_VERIFIED)], true));

        $this->assertSame('missing_verified_file', $item['reason']);
    }

    /**
     * LOW приёмки: код 1.9.0 уже стоит, update_1_9_0 ещё не прошёл (таблиц хранилища нет). Сбор
     * фактов ротации таблицы хранилища не читает: строки job те же, что в 1.8.4, отказа нет.
     * Kill-проба: обращение к хранилищу из фактов -> красный.
     */
    public function testEvidenceDoesNotTouchStoreTablesBeforeMigration(): void
    {
        $repository = $this->createMock(ProductDocumentStoreRepository::class);
        $repository->expects(self::never())->method(self::anything());
        $config = $this->getMockBuilder(Config::class)->disableOriginalConstructor()->onlyMethods(['get'])->getMock();
        $settings = $this->createMock(Settings::class);
        $store = new ProductDocumentStore($repository, new ProductDocumentStoreFiles($config), $settings);
        $a = $this->doc('A');
        $rows = [(object) ['name' => 'products-0001.ndjson.gz', 'sha256_expected' => str_repeat('a', 64), 'bytes' => 4,
            'status' => Contract::FILE_APPLIED], (object) ['name' => $a['name'], 'sha256_expected' => $a['sha'],
            'bytes' => $a['bytes'], 'status' => Contract::FILE_VERIFIED]];
        $jobs = new class {
            public function count(array $filter): int { return 1; }
            public function findOne(array $filter) {
                return (object) ['id' => 5, 'status' => 'applied', 'phase' => 'done', 'files_total' => 2,
                    'finished_at' => '2026-11-01 00:00:00', 'error_message' => null];
            }
        };
        $jobFiles = new class($rows) {
            /** @var array<int, object> */ private $rows;
            public function __construct(array $rows) { $this->rows = $rows; }
            public function count(array $filter): int { return count($this->rows); }
            public function find(array $filter): array { return $this->rows; }
        };
        $factory = $this->createMock(EntityFactory::class);
        $factory->method('get')->willReturnCallback(static function (string $class) use ($jobs, $jobFiles) {
            return $class === CoreSyncJobsEntity::class ? $jobs : $jobFiles;
        });
        $evidence = new ExchangeRetentionEvidence($factory, $settings, $config,
            $this->createMock(Database::class), $this->createMock(QueryFactory::class), $store);

        $facts = $evidence->facts(self::VERSION);

        $this->assertSame([
            ['name' => 'products-0001.ndjson.gz', 'sha256' => str_repeat('a', 64), 'bytes' => 4, 'status' => 'applied'],
            ['name' => $a['name'], 'sha256' => $a['sha'], 'bytes' => $a['bytes'], 'status' => 'verified'],
        ], $facts['files']);
    }

    /** @return array<string, mixed> */
    private function item(ExchangeRetentionManager $manager): array
    {
        foreach ($manager->preview(null)['items'] as $item) {
            if ($item['version'] === self::VERSION) {
                return $item;
            }
        }
        $this->fail('версия не попала в превью');
    }

    /** @param list<array<string, mixed>> $rows */
    private function manager(array $rows, bool $legacySafe = false): ExchangeRetentionManager
    {
        $facts = [
            'status' => 'applied', 'phase' => Contract::PHASE_DONE, 'job_id' => 5,
            'finished_at' => gmdate('Y-m-d H:i:s', time() - 30 * 86400), 'error' => null, 'files' => $rows,
        ];
        $evidence = new class($facts, $legacySafe) extends ExchangeRetentionEvidence {
            /** @var array<string, mixed> */ private $f;
            /** @var bool */ private $legacySafe;

            public function __construct(array $facts, bool $legacySafe)
            {
                $this->f = $facts;
                $this->legacySafe = $legacySafe;
            }

            public function currentAppliedVersions(): array { return [12, 11]; }
            public function statusSummary(array $versions): array { return array_fill_keys($versions, 'applied'); }
            public function facts(int $version): ?array { return $this->f; }
            public function stagingLinked(int $version): bool { return false; }
            public function documentSafe(int $version, string $name, string $sha256, int $bytes): bool { return $this->legacySafe; }
        };
        $config = $this->createMock(Config::class);
        $root = $this->root;
        $config->method('get')->willReturnCallback(static function (string $key) use ($root) {
            return $key === 'root_dir' ? $root : null;
        });
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturn(null);

        return new ExchangeRetentionManager($config, $settings, $evidence);
    }

    /** @return array{name:string,sha:string,bytes:int,body:string} */
    private function doc(string $label): array
    {
        $body = '%PDF-1.4 ' . $label . "\n";
        $sha = hash('sha256', $body);

        return ['name' => 'document-' . $sha . '.pdf', 'sha' => $sha, 'bytes' => strlen($body), 'body' => $body];
    }

    /** @param array{name:string,sha:string,bytes:int} $doc @return array<string, mixed> */
    private function row(array $doc, string $status): array
    {
        return ['name' => $doc['name'], 'sha256' => $doc['sha'], 'bytes' => $doc['bytes'], 'status' => $status];
    }

    /** @return array<string, mixed> */
    private function ndjsonRow(): array
    {
        return ['name' => 'products-0001.ndjson.gz', 'sha256' => hash('sha256', $this->ndjson),
            'bytes' => strlen($this->ndjson), 'status' => Contract::FILE_APPLIED];
    }

    private function stage(string $name): string
    {
        return $this->root . '/files/coresync/' . self::VERSION . '/' . $name;
    }
}
