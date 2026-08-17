<?php

namespace Tests\Modules\Format\CoreSync;

use Aura\SqlQuery\QueryFactory as AuraQueryFactory;
use Okay\Core\Config;
use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\QueryFactory;
use Okay\Entities\ImagesEntity;
use Okay\Entities\ProductsEntity;
use Okay\Modules\Format\CoreSync\Core\Apply\GalleryAdoptionException;
use Okay\Modules\Format\CoreSync\Core\Apply\GalleryAdoptionPlanReader;
use Okay\Modules\Format\CoreSync\Core\Apply\LegacyGalleryAdopter;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class LegacyGalleryAdopterTest extends TestCase
{
    /** @var array<int,string> */
    private $paths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->paths) as $path) {
            if (is_file($path) || is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
        parent::tearDown();
    }

    public function testReaderAcceptsOnlyCanonicalCappedPlan(): void
    {
        $fixture = $this->fixture();
        $reader = new GalleryAdoptionPlanReader();

        $plan = $reader->read($this->upload($fixture['payload']));

        self::assertSame(hash('sha256', $fixture['payload']), $plan['sha256']);
        self::assertSame(1, $plan['header']['rows_count']);
        self::assertSame($fixture['row'], $plan['rows'][0]);

        $this->expectException(GalleryAdoptionException::class);
        $reader->read($this->upload(str_replace('{"format"', '{ "format"', $fixture['payload'])));
    }

    public function testReaderRefusesPartialProductSetAndOversizedUploadDeclaration(): void
    {
        $fixture = $this->fixture();
        $lines = explode("\n", trim($fixture['payload']));
        $row = json_decode($lines[1], true);
        $row['sort'] = 1;
        $row['position'] = 2;
        $partial = $lines[0] . "\n" . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $reader = new GalleryAdoptionPlanReader();

        try {
            $reader->read($this->upload($partial));
            self::fail('partial desired set must be refused');
        } catch (GalleryAdoptionException $e) {
            self::assertStringContainsString('partial', $e->getMessage());
        }

        $upload = $this->upload($fixture['payload']);
        $upload['size'] = GalleryAdoptionPlanReader::MAX_COMPRESSED_BYTES + 1;
        $this->expectException(GalleryAdoptionException::class);
        $reader->read($upload);
    }

    public function testApplyAddsOnlyDurableOwnershipAndCoarseMarkerInsideTransaction(): void
    {
        $fixture = $this->fixture();
        $plan = (new GalleryAdoptionPlanReader())->read($this->upload($fixture['payload']));
        [$adopter, $database, $durable, $map] = $this->adopter($fixture, null, 'pending');

        $database->expects(self::once())->method('beginTransaction')->willReturn(true);
        $database->expects(self::once())->method('commit')->willReturn(true);
        $database->expects(self::never())->method('rollBack');
        $statements = [];
        $database->expects(self::exactly(2))->method('query')->willReturnCallback(
            static function ($query) use (&$statements): bool {
                $statements[] = $query->getStatement();

                return true;
            }
        );
        $durable->expects(self::never())->method('add');
        $durable->expects(self::never())->method('update');
        $map->expects(self::never())->method('update');

        $preview = $adopter->preview($plan);
        $result = $adopter->apply($plan, $plan['sha256'], 'ADOPT_EXISTING_GALLERY');

        self::assertSame(['durable_adds' => 1, 'durable_updates' => 0, 'map_updates' => 1], $preview['writes']);
        self::assertSame(2, $result['writes']);
        self::assertStringContainsString('INSERT', $statements[0]);
        self::assertStringContainsString('__format__coresync_images', $statements[0]);
        self::assertStringContainsString('__format__coresync_map', $statements[1]);
    }

    public function testExactRepeatIsZeroWriteAndDoesNotOpenTransaction(): void
    {
        $fixture = $this->fixture();
        $plan = (new GalleryAdoptionPlanReader())->read($this->upload($fixture['payload']));
        $existing = (object) array_merge(['id' => 61, 'attempts' => 0, 'state' => 'done'], [
            'product_external_id' => '501',
            'product_local_id' => 77,
            'url' => $fixture['row']['url'],
            'url_hash' => $fixture['row']['url_hash'],
            'sort' => 0,
            'filename' => 'legacy.jpg',
            'image_id' => 901,
        ]);
        [$adopter, $database, $durable, $map] = $this->adopter($fixture, $existing, 'done');

        $database->expects(self::never())->method('beginTransaction');
        $database->expects(self::never())->method('commit');
        $database->expects(self::never())->method('rollBack');
        $durable->expects(self::never())->method('add');
        $durable->expects(self::never())->method('update');
        $map->expects(self::never())->method('update');

        $result = $adopter->apply($plan, $plan['sha256'], 'ADOPT_EXISTING_GALLERY');

        self::assertSame(0, $result['writes']);
    }

    public function testMismatchAbortsBeforeWriteAndFailedWriteRollsBack(): void
    {
        $fixture = $this->fixture();
        $plan = (new GalleryAdoptionPlanReader())->read($this->upload($fixture['payload']));
        [$adopter, $database, $durable, $map] = $this->adopter($fixture, null, 'pending');

        $database->expects(self::once())->method('beginTransaction')->willReturn(true);
        $database->expects(self::never())->method('commit');
        $database->expects(self::once())->method('rollBack')->willReturn(true);
        $database->expects(self::once())->method('query')->willReturn(false);
        $durable->expects(self::never())->method('add');
        $map->expects(self::never())->method('update');

        try {
            $adopter->apply($plan, str_repeat('0', 64), 'ADOPT_EXISTING_GALLERY');
            self::fail('expected SHA mismatch must refuse before writes');
        } catch (GalleryAdoptionException $e) {
            self::assertStringContainsString('SHA-256', $e->getMessage());
        }
        try {
            $adopter->apply($plan, $plan['sha256'], 'WRONG_CONFIRMATION');
            self::fail('confirmation mismatch must refuse before writes');
        } catch (GalleryAdoptionException $e) {
            self::assertStringContainsString('confirmation', $e->getMessage());
        }

        $this->expectException(GalleryAdoptionException::class);
        $adopter->apply($plan, $plan['sha256'], 'ADOPT_EXISTING_GALLERY');
    }

    public function testDatabaseFalseHiddenByGenericEntityRollsBackBeforeCoarseMarker(): void
    {
        $fixture = $this->fixture();
        $plan = (new GalleryAdoptionPlanReader())->read($this->upload($fixture['payload']));
        $existing = (object) [
            'id' => 61,
            'product_external_id' => '501',
            'product_local_id' => 77,
            'url' => $fixture['row']['url'],
            'url_hash' => $fixture['row']['url_hash'],
            'sort' => 0,
            'state' => 'pending',
            'attempts' => 1,
            'filename' => 'legacy.jpg',
            'image_id' => 901,
        ];
        [$adopter, $database, $durable, $map] = $this->adopter($fixture, $existing, 'pending');

        $database->expects(self::once())->method('beginTransaction')->willReturn(true);
        $database->expects(self::once())->method('query')->willReturn(false);
        $database->expects(self::never())->method('commit');
        $database->expects(self::once())->method('rollBack')->willReturn(true);
        // This callback models Okay generic CRUD: it executes Database::query(), ignores false,
        // and reports success to its caller. Adoption must therefore bypass this unchecked layer.
        $durable->expects(self::any())->method('update')->willReturnCallback(
            static function () use ($database): bool {
                $query = (new AuraQueryFactory('mysql'))->newUpdate();
                $query->table('__format__coresync_images')->cols(['state' => 'done'])->where('id = 61');
                $database->query($query);

                return true;
            }
        );
        $map->expects(self::never())->method('update');

        $this->expectException(GalleryAdoptionException::class);
        $adopter->apply($plan, $plan['sha256'], 'ADOPT_EXISTING_GALLERY');
    }

    public function testFileFingerprintDriftRefusesBeforeOpeningTransaction(): void
    {
        $fixture = $this->fixture();
        $plan = (new GalleryAdoptionPlanReader())->read($this->upload($fixture['payload']));
        [$adopter, $database] = $this->adopter($fixture, null, 'pending');
        file_put_contents($fixture['gallery_root'] . '/legacy.jpg', 'legacy-image-byteX');
        $database->expects(self::never())->method('beginTransaction');

        $this->expectException(GalleryAdoptionException::class);
        $adopter->apply($plan, $plan['sha256'], 'ADOPT_EXISTING_GALLERY');
    }

    public function testSymlinkedLegacyFileRefusesBeforeOpeningTransaction(): void
    {
        $fixture = $this->fixture();
        $plan = (new GalleryAdoptionPlanReader())->read($this->upload($fixture['payload']));
        [$adopter, $database] = $this->adopter($fixture, null, 'pending');
        $image = $fixture['gallery_root'] . '/legacy.jpg';
        $target = $fixture['gallery_root'] . '/target.jpg';
        file_put_contents($target, 'legacy-image-bytes');
        $this->paths[] = $target;
        unlink($image);
        symlink($target, $image);
        $database->expects(self::never())->method('beginTransaction');

        $this->expectException(GalleryAdoptionException::class);
        $adopter->apply($plan, $plan['sha256'], 'ADOPT_EXISTING_GALLERY');
    }

    public function testDurableConflictAndMainImageDriftRefuseBeforeTransaction(): void
    {
        $fixture = $this->fixture();
        $plan = (new GalleryAdoptionPlanReader())->read($this->upload($fixture['payload']));
        $conflict = (object) [
            'id' => 61,
            'product_external_id' => '501',
            'product_local_id' => 77,
            'url' => $fixture['row']['url'],
            'url_hash' => $fixture['row']['url_hash'],
            'sort' => 0,
            'state' => 'done',
            'attempts' => 0,
            'filename' => 'conflict.jpg',
            'image_id' => 901,
        ];
        [$conflicting, $database] = $this->adopter($fixture, $conflict, 'pending');
        $database->expects(self::never())->method('beginTransaction');
        try {
            $conflicting->apply($plan, $plan['sha256'], 'ADOPT_EXISTING_GALLERY');
            self::fail('conflicting durable ownership must be refused');
        } catch (GalleryAdoptionException $e) {
            self::assertStringContainsString('durable', $e->getMessage());
        }

        [$wrongMain, $mainDatabase] = $this->adopter($fixture, null, 'pending', 902);
        $mainDatabase->expects(self::never())->method('beginTransaction');
        $this->expectException(GalleryAdoptionException::class);
        $wrongMain->apply($plan, $plan['sha256'], 'ADOPT_EXISTING_GALLERY');
    }

    public function testDatabaseWithoutTransactionContractRefusesBeforeWrite(): void
    {
        $fixture = $this->fixture();
        $plan = (new GalleryAdoptionPlanReader())->read($this->upload($fixture['payload']));
        [$adopter] = $this->adopter($fixture, null, 'pending');
        $database = new \ReflectionProperty(LegacyGalleryAdopter::class, 'database');
        $database->setAccessible(true);
        $database->setValue($adopter, new \stdClass());

        $this->expectException(GalleryAdoptionException::class);
        $this->expectExceptionMessage('transactional');
        $adopter->apply($plan, $plan['sha256'], 'ADOPT_EXISTING_GALLERY');
    }

    public function testTrailingUnplannedGalleryRowRefusesBeforeTransaction(): void
    {
        $fixture = $this->fixture();
        $plan = (new GalleryAdoptionPlanReader())->read($this->upload($fixture['payload']));
        [$adopter, $database] = $this->adopter($fixture, null, 'pending', 901, true);
        $database->expects(self::never())->method('beginTransaction');

        $this->expectException(GalleryAdoptionException::class);
        $adopter->apply($plan, $plan['sha256'], 'ADOPT_EXISTING_GALLERY');
    }

    public function testOrdinaryCoreSyncEntrypointsCannotReachGalleryAdopter(): void
    {
        $root = dirname(__DIR__, 4) . '/Okay/Modules/Format/CoreSync';
        foreach ([
            '/Core/SyncRunner.php',
            '/Core/Apply/Applier.php',
            '/Controllers/PingController.php',
            '/Init/routes.php',
        ] as $relative) {
            self::assertStringNotContainsString(
                'LegacyGalleryAdopter',
                (string) file_get_contents($root . $relative),
                $relative . ' must not expose automatic gallery adoption'
            );
        }
    }

    public function testInventoryQueriesAreChunkedAtOneThousandValues(): void
    {
        $fixture = $this->fixture();
        [$adopter] = $this->adopter($fixture, null, 'pending');
        $entity = new class {
            /** @var array<int,array<string,mixed>> */
            public $filters = [];

            public function noLimit()
            {
                return $this;
            }

            public function find(array $filter): array
            {
                $this->filters[] = $filter;

                return [];
            }
        };
        $method = new \ReflectionMethod(LegacyGalleryAdopter::class, 'findChunked');
        $method->setAccessible(true);
        $method->invoke($adopter, $entity, 'id', range(1, 2505), ['entity_type' => 'product']);

        self::assertCount(3, $entity->filters);
        self::assertSame([1000, 1000, 505], array_map(static function (array $filter): int {
            return count($filter['id']);
        }, $entity->filters));
        self::assertSame(['product', 'product', 'product'], array_column($entity->filters, 'entity_type'));
    }

    /** @return array{payload:string,row:array<string,mixed>,gallery_root:string} */
    private function fixture(): array
    {
        $root = sys_get_temp_dir() . '/coresync-adopt-' . bin2hex(random_bytes(6));
        mkdir($root, 0700);
        $this->paths[] = $root;
        $image = $root . '/legacy.jpg';
        file_put_contents($image, 'legacy-image-bytes');
        $this->paths[] = $image;
        $row = [
            'product_external_id' => '501',
            'product_local_id' => 77,
            'url' => 'https://media.example/legacy.jpg',
            'url_hash' => hash('sha256', 'https://media.example/legacy.jpg'),
            'sort' => 0,
            'image_id' => 901,
            'filename' => 'legacy.jpg',
            'position' => 1,
            'size' => strlen('legacy-image-bytes'),
            'sha256' => hash('sha256', 'legacy-image-bytes'),
        ];
        $header = [
            'format' => 'coresync-gallery-adoption/v1',
            'database' => 'b2bcrm_artaz',
            'source_identity' => 'okay:artaz',
            'media_plan_sha256' => str_repeat('1', 64),
            'conflict_report_sha256' => str_repeat('2', 64),
            'checkpoint_sha256' => str_repeat('3', 64),
            'snapshot_manifest_sha256' => str_repeat('4', 64),
            'rows_count' => 1,
        ];
        $encode = static function (array $value): string {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        };

        return ['payload' => $encode($header) . "\n" . $encode($row) . "\n", 'row' => $row, 'gallery_root' => $root];
    }

    /** @return array<string,mixed> */
    private function upload(string $payload): array
    {
        $path = tempnam(sys_get_temp_dir(), 'coresync-adopt-upload-');
        $this->paths[] = $path;
        $gz = gzopen($path, 'wb9');
        gzwrite($gz, $payload);
        gzclose($gz);

        return ['error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'size' => filesize($path), 'name' => 'plan.ndjson.gz'];
    }

    /**
     * @param object|null $existing
     * @return array{0:LegacyGalleryAdopter,1:Database,2:CoreSyncImagesEntity,3:CoreSyncMapEntity}
     */
    private function adopter(
        array $fixture,
        $existing,
        string $mapState,
        int $mainImageId = 901,
        bool $extraGallery = false
    ): array
    {
        $database = $this->createMock(Database::class);
        $durable = $this->createMock(CoreSyncImagesEntity::class);
        $durable->expects(self::any())->method('noLimit')->willReturnSelf();
        $durable->expects(self::any())->method('find')->willReturn($existing === null ? [] : [$existing]);
        $map = $this->createMock(CoreSyncMapEntity::class);
        $mapRow = (object) [
            'id' => 41,
            'entity_type' => 'product',
            'external_id' => '501',
            'local_id' => 77,
            'image_state' => $mapState,
        ];
        $map->expects(self::any())->method('noLimit')->willReturnSelf();
        $map->expects(self::any())->method('find')->willReturn([$mapRow]);
        $images = $this->createMock(ImagesEntity::class);
        $galleryRow = (object) [
            'id' => 901,
            'product_id' => 77,
            'filename' => 'legacy.jpg',
            'position' => 1,
        ];
        $images->expects(self::any())->method('noLimit')->willReturnSelf();
        $galleryRows = [$galleryRow];
        if ($extraGallery) {
            $galleryRows[] = (object) [
                'id' => 902,
                'product_id' => 77,
                'filename' => 'extra.jpg',
                'position' => 2,
            ];
        }
        $images->expects(self::any())->method('find')->willReturn($galleryRows);
        $images->expects(self::never())->method('add');
        $images->expects(self::never())->method('update');
        $images->expects(self::never())->method('delete');
        $products = $this->createMock(ProductsEntity::class);
        $productRow = (object) [
            'id' => 77,
            'main_image_id' => $mainImageId,
        ];
        $products->expects(self::any())->method('noLimit')->willReturnSelf();
        $products->expects(self::any())->method('find')->willReturn([$productRow]);
        $products->expects(self::never())->method('add');
        $products->expects(self::never())->method('update');
        $products->expects(self::never())->method('delete');
        $factory = $this->createMock(EntityFactory::class);
        $factory->expects(self::any())->method('get')->willReturnCallback(static function (string $class) use ($durable, $map, $images, $products) {
            if ($class === CoreSyncImagesEntity::class) {
                return $durable;
            }
            if ($class === CoreSyncMapEntity::class) {
                return $map;
            }
            if ($class === ImagesEntity::class) {
                return $images;
            }
            if ($class === ProductsEntity::class) {
                return $products;
            }
            throw new \InvalidArgumentException('Unexpected entity');
        });
        $config = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $config->expects(self::any())->method('get')->willReturnCallback(static function (string $key) use ($fixture) {
            if ($key === 'root_dir') {
                return $fixture['gallery_root'];
            }
            if ($key === 'original_images_dir') {
                return '/';
            }
            return null;
        });
        self::assertSame($fixture['gallery_root'], $config->root_dir);
        self::assertSame(0100000, lstat($fixture['gallery_root'] . '/legacy.jpg')['mode'] & 0170000);
        $queryFactory = $this->createMock(QueryFactory::class);
        $queryFactory->expects(self::any())->method('newInsert')->willReturnCallback(static function () {
            return (new AuraQueryFactory('mysql'))->newInsert();
        });
        $queryFactory->expects(self::any())->method('newUpdate')->willReturnCallback(static function () {
            return (new AuraQueryFactory('mysql'))->newUpdate();
        });

        return [new LegacyGalleryAdopter($factory, $database, $queryFactory, $config, new NullLogger()), $database, $durable, $map];
    }
}
