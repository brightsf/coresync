<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Config;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\ExchangeRetentionEvidence;
use Okay\Modules\Format\CoreSync\Core\ExchangeRetentionManager;
use PHPUnit\Framework\TestCase;

/**
 * Ротация staging при своём хранилище документов: документ, перенесённый в хранилище (его нет в
 * staging, чекпоинт может остаться pending у догнанного файла), не держит версию навсегда.
 * Документ, которого нет ни в staging, ни в хранилище (отложен лимитом), версию держит.
 * Старый путь (документ в staging, сверка ProductDocuments) прежний.
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
     * Документы перенесены в хранилище (A сверен при rename, B догнан той же версией и остался
     * pending в чекпоинте): версия удаляется по политике. Kill-проба: без признака stored ->
     * unsafe_checkpoint / missing_verified_file -> красный.
     */
    public function testStoredDocumentsDoNotPinAppliedVersion(): void
    {
        $a = $this->doc('A');
        $b = $this->doc('B');
        $manager = $this->manager([
            $this->ndjsonRow(),
            $this->row($a, Contract::FILE_VERIFIED),
            $this->row($b, Contract::FILE_PENDING),
        ], [$a['name'], $b['name']]);

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
        $stored = [];
        for ($i = 0; $i < 300; $i++) {
            $doc = $this->doc('doc' . $i);
            $rows[] = $this->row($doc, Contract::FILE_VERIFIED);
            $stored[] = $doc['name'];
        }

        $this->assertSame('delete', $this->item($this->manager($rows, $stored))['decision']);
    }

    /** Отложенный лимитом документ (нет в хранилище, нет в staging) держит версию для догона. */
    public function testDeferredDocumentKeepsVersion(): void
    {
        $a = $this->doc('A');
        $b = $this->doc('B');

        $item = $this->item($this->manager([
            $this->ndjsonRow(),
            $this->row($a, Contract::FILE_VERIFIED),
            $this->row($b, Contract::FILE_PENDING),
        ], [$a['name']]));

        $this->assertSame('keep', $item['decision']);
        $this->assertSame('unsafe_checkpoint', $item['reason']);
    }

    /** Старый путь: PDF в staging без признака хранилища проверяется ProductDocuments, как раньше. */
    public function testLegacyStagedDocumentStillRequiresProductDocumentsProof(): void
    {
        $a = $this->doc('A');
        file_put_contents($this->stage($a['name']), $a['body']);
        $rows = [$this->ndjsonRow(), $this->row($a, Contract::FILE_VERIFIED)];

        $refused = $this->item($this->manager($rows, [], false));
        $proved = $this->item($this->manager($rows, [], true));

        $this->assertSame('published_document_unverified', $refused['reason']);
        $this->assertSame('keep', $refused['decision']);
        $this->assertSame('delete', $proved['decision']);
    }

    /** Старый путь: пропавший из staging PDF без хранилища по-прежнему держит версию. */
    public function testLegacyMissingDocumentStillKeepsVersion(): void
    {
        $a = $this->doc('A');

        $item = $this->item($this->manager([$this->ndjsonRow(), $this->row($a, Contract::FILE_VERIFIED)], [], true));

        $this->assertSame('missing_verified_file', $item['reason']);
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

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $stored
     */
    private function manager(array $rows, array $stored, bool $legacySafe = false): ExchangeRetentionManager
    {
        $facts = [
            'status' => 'applied', 'phase' => Contract::PHASE_DONE, 'job_id' => 5,
            'finished_at' => gmdate('Y-m-d H:i:s', time() - 30 * 86400), 'error' => null, 'files' => $rows,
        ];
        $evidence = new class($facts, $stored, $legacySafe) extends ExchangeRetentionEvidence {
            /** @var array<string, mixed> */ private $f;
            /** @var array<string, bool> */ private $stored;
            /** @var bool */ private $legacySafe;

            public function __construct(array $facts, array $stored, bool $legacySafe)
            {
                $this->f = $facts;
                $this->stored = array_fill_keys($stored, true);
                $this->legacySafe = $legacySafe;
            }

            public function currentAppliedVersions(): array { return [12, 11]; }
            public function statusSummary(array $versions): array { return array_fill_keys($versions, 'applied'); }
            public function facts(int $version): ?array { return $this->f; }
            public function stagingLinked(int $version): bool { return false; }
            public function documentSafe(int $version, string $name, string $sha256, int $bytes): bool { return $this->legacySafe; }
            public function storedDocuments(array $candidates): array
            {
                return array_intersect_key($this->stored, $candidates);
            }
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
