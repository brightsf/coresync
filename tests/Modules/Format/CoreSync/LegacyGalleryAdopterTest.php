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
        // The only image of the product claims rank 2, so rank 1 is missing from the plan.
        $row['sort'] = 2;
        $partial = $lines[0] . "\n" . $this->encode($row) . "\n";
        $reader = new GalleryAdoptionPlanReader();

        try {
            $reader->read($this->upload($partial));
            self::fail('partial desired set must be refused');
        } catch (GalleryAdoptionException $e) {
            self::assertStringContainsString('partial', $e->getMessage());
        }

        // A header whose excluded products list outgrows one NDJSON line is still refused, but it
        // must SAY so: read as generic framing damage the operator hunts a broken artifact that is
        // not broken. 137 exclusions fit, 138 do not (measured); the cap itself is not touched here.
        $exclusions = [];
        for ($i = 0; $i < 138; $i++) {
            $exclusions[] = [
                'product_external_id' => 'okay:product:' . (7000 + $i),
                'product_local_id' => 7000 + $i,
                'decision' => 'excluded_from_adoption',
                'reasons' => ['legacy_gallery_not_closed', 'plan_partial_for_product'],
                'planned_rows' => 17,
                'snapshot_images' => 18,
                'legacy_gallery_rows' => 18,
            ];
        }
        $overlong = $this->ndjson($this->header(1, [], $exclusions), [$fixture['row']]);
        self::assertGreaterThan(GalleryAdoptionPlanReader::MAX_LINE_BYTES, strpos($overlong, "\n"));
        try {
            $reader->read($this->upload($overlong));
            self::fail('a header line above the cap must be refused');
        } catch (GalleryAdoptionException $e) {
            self::assertStringContainsString('header line exceeds', $e->getMessage());
            self::assertStringContainsString('excluded products list', $e->getMessage());
        }

        $upload = $this->upload($fixture['payload']);
        $upload['size'] = GalleryAdoptionPlanReader::MAX_COMPRESSED_BYTES + 1;
        $this->expectException(GalleryAdoptionException::class);
        $reader->read($upload);
    }

    /**
     * The shapes the customer storefront actually holds. Measured on the local `artaz` mirror
     * 2026-08-18 over 59565 visible `ok_images` rows: 9332 carry `position = 0`, 6005 carry
     * `position = id`, 6016 carry `position > 100`. None of them equals `sort + 1`, so a reader
     * that demands that equality refuses every real product. Order inside a product is carried by
     * `sort` alone, and this asserts it is carried through untouched.
     */
    public function testReaderAcceptsLiveStorefrontPositionsAndKeepsProductOrder(): void
    {
        $rows = [
            // first image of the product, position left unset by Okay -> 0
            $this->planRow('okay:product:520', 520, 1, 900001, 0, 'a1.jpg'),
            // Okay rewrote an unset position with the row id: a six-digit position
            $this->planRow('okay:product:7134', 7134, 1, 123456, 123456, 'b1.jpg'),
            // three images whose positions carry no order at all
            $this->planRow('okay:product:1000', 1000, 1, 910001, 0, 'c1.jpg'),
            $this->planRow('okay:product:1000', 1000, 2, 910002, 0, 'c2.jpg'),
            $this->planRow('okay:product:1000', 1000, 3, 910003, 0, 'c3.jpg'),
            // four images with positions above the hundred mark
            $this->planRow('okay:product:2000', 2000, 1, 920001, 3, 'd1.jpg'),
            $this->planRow('okay:product:2000', 2000, 2, 920002, 100, 'd2.jpg'),
            $this->planRow('okay:product:2000', 2000, 3, 920003, 214, 'd3.jpg'),
            $this->planRow('okay:product:2000', 2000, 4, 920004, 998877, 'd4.jpg'),
        ];
        $exclusions = [[
            'product_external_id' => 'okay:product:7135',
            'product_local_id' => 7135,
            'decision' => 'excluded_from_adoption',
            'reasons' => ['plan_partial_for_product'],
            'planned_rows' => 17,
            'snapshot_images' => 18,
            'legacy_gallery_rows' => 18,
        ]];
        $payload = $this->ndjson($this->header(count($rows), [], $exclusions), $rows);

        $plan = (new GalleryAdoptionPlanReader())->read($this->upload($payload));

        self::assertSame(hash('sha256', $payload), $plan['sha256']);
        self::assertSame($rows, $plan['rows'], 'every planned row must survive read unmodified');
        self::assertSame(1, $plan['header']['excluded_products_count']);
        self::assertSame($exclusions, $plan['header']['excluded_products']);

        $sortsByProduct = [];
        $positionsByProduct = [];
        foreach ($plan['rows'] as $row) {
            $sortsByProduct[$row['product_external_id']][] = $row['sort'];
            $positionsByProduct[$row['product_external_id']][] = $row['position'];
        }
        self::assertSame([
            'okay:product:520' => [1],
            'okay:product:7134' => [1],
            'okay:product:1000' => [1, 2, 3],
            'okay:product:2000' => [1, 2, 3, 4],
        ], $sortsByProduct);
        self::assertSame([
            'okay:product:520' => [0],
            'okay:product:7134' => [123456],
            'okay:product:1000' => [0, 0, 0],
            'okay:product:2000' => [3, 100, 214, 998877],
        ], $positionsByProduct, 'storefront positions must be carried verbatim, never renumbered');
    }

    /**
     * Dropping the position/rank equality must not cost the ordering invariant: the rank sequence
     * inside a product stays the closed 1..N run the core emits, and it is still the ONE mechanism
     * that says so ({@see GalleryAdoptionPlanReader::validateSet()}).
     */
    public function testReaderRefusesBrokenOrDuplicatedRankSequenceInsideProduct(): void
    {
        $cases = [
            'gap in the middle' => [
                [
                    $this->planRow('okay:product:1000', 1000, 1, 910001, 0, 'c1.jpg'),
                    $this->planRow('okay:product:1000', 1000, 3, 910003, 0, 'c3.jpg'),
                ],
                'partial',
            ],
            'zero-based run left over from the old contract' => [
                [
                    $this->planRow('okay:product:1000', 1000, 0, 910001, 0, 'c1.jpg'),
                    $this->planRow('okay:product:1000', 1000, 1, 910002, 0, 'c2.jpg'),
                ],
                'partial',
            ],
            'two images claiming the same rank' => [
                [
                    $this->planRow('okay:product:1000', 1000, 1, 910001, 0, 'c1.jpg'),
                    $this->planRow('okay:product:1000', 1000, 1, 910002, 0, 'c2.jpg'),
                ],
                'duplicated',
            ],
        ];
        $reader = new GalleryAdoptionPlanReader();
        foreach ($cases as $label => $case) {
            [$rows, $expected] = $case;
            try {
                $reader->read($this->upload($this->ndjson($this->header(count($rows)), $rows)));
                self::fail($label . ' must be refused');
            } catch (GalleryAdoptionException $e) {
                self::assertStringContainsString($expected, $e->getMessage(), $label);
            }
        }

        // The same rows with an intact 1..N run are accepted, so the refusals above are the
        // invariant firing and not a fixture that cannot be read at all.
        $intact = [
            $this->planRow('okay:product:1000', 1000, 1, 910001, 0, 'c1.jpg'),
            $this->planRow('okay:product:1000', 1000, 2, 910002, 0, 'c2.jpg'),
        ];
        $plan = $reader->read($this->upload($this->ndjson($this->header(count($intact)), $intact)));
        self::assertSame($intact, $plan['rows']);
    }

    /**
     * Exactly ONE row condition was dropped. `position` is still required to BE a non-negative
     * integer, and every other check of the row stands untouched — this walks them one by one, so
     * "the equality was removed" cannot quietly mean "the row is barely checked any more".
     */
    public function testReaderRefusesEveryOtherRowDefectAndStillShapesPosition(): void
    {
        $valid = $this->planRow('okay:product:520', 520, 1, 900001, 0, 'a1.jpg');
        $mutations = [
            'position below zero' => ['position' => -1],
            'position as a string' => ['position' => '0'],
            // 0.0 is NOT a case: JSON renders it as `0` and it comes back a genuine integer.
            'position as a fractional number' => ['position' => 1.5],
            'rank below the 1-based run' => ['sort' => -1],
            'url hash that does not match the url' => ['url_hash' => str_repeat('f', 64)],
            'url outside https' => ['url' => 'http://media.example/a1.jpg'],
            'content hash that is not a sha-256' => ['sha256' => 'not-a-hash'],
            'empty object' => ['size' => 0],
            'filename carrying a path' => ['filename' => '../a1.jpg'],
            'local product id below one' => ['product_local_id' => 0],
            'image id below one' => ['image_id' => 0],
            'blank product identity' => ['product_external_id' => ''],
        ];
        $reader = new GalleryAdoptionPlanReader();
        foreach ($mutations as $label => $mutation) {
            $row = $valid;
            foreach ($mutation as $key => $value) {
                $row[$key] = $value;
            }
            if (isset($mutation['url'])) {
                $row['url_hash'] = hash('sha256', $mutation['url']);
            }
            try {
                $reader->read($this->upload($this->ndjson($this->header(1), [$row])));
                self::fail($label . ' must be refused');
            } catch (GalleryAdoptionException $e) {
                self::assertStringContainsString('row is invalid', $e->getMessage(), $label);
            }
        }

        // A dropped key and an added key stay refused too: the key set is exact, not a subset.
        foreach ([['position'], ['sort']] as $dropped) {
            $row = $valid;
            unset($row[$dropped[0]]);
            try {
                $reader->read($this->upload($this->ndjson($this->header(1), [$row])));
                self::fail('a row missing ' . $dropped[0] . ' must be refused');
            } catch (GalleryAdoptionException $e) {
                self::assertStringContainsString('row is invalid', $e->getMessage());
            }
        }
        $extra = $valid;
        $extra['legacy_position'] = 7;
        $this->expectException(GalleryAdoptionException::class);
        $reader->read($this->upload($this->ndjson($this->header(1), [$extra])));
    }

    /**
     * The header the core emits is `coresync-gallery-adoption/v2` with ten keys, the exclusion pair
     * among them ({@see \App\Legacy\Okay\Media\GalleryAdoptionPlan} in b2bCRM). Anything else — the
     * retired v1 shape, a v2 tag over a v1 key set, an undeclared exclusion list — stays refused.
     */
    public function testReaderRefusesForeignPlanFormatAndHeaderKeySet(): void
    {
        $rows = [$this->planRow('okay:product:520', 520, 1, 900001, 0, 'a1.jpg')];
        $v1Header = [
            'format' => 'coresync-gallery-adoption/v1',
            'database' => 'b2bcrm_artaz',
            'source_identity' => 'okay:artaz',
            'media_plan_sha256' => str_repeat('1', 64),
            'conflict_report_sha256' => str_repeat('2', 64),
            'checkpoint_sha256' => str_repeat('3', 64),
            'snapshot_manifest_sha256' => str_repeat('4', 64),
            'rows_count' => 1,
        ];
        $headers = [
            'retired v1 header' => $v1Header,
            'v2 tag over the v1 key set' => ['format' => 'coresync-gallery-adoption/v2'] + $v1Header,
            'unknown future format' => $this->header(1, ['format' => 'coresync-gallery-adoption/v3']),
            'no format tag at all' => $this->header(1, ['format' => 'gallery-adoption']),
            'foreign database' => $this->header(1, ['database' => 'b2bcrm_inua']),
            'foreign source identity' => $this->header(1, ['source_identity' => 'okay:inua']),
            'undeclared exclusions' => $this->header(1, ['excluded_products_count' => 1]),
            'exclusion count below the list' => $this->header(1, ['excluded_products' => [
                ['product_external_id' => 'okay:product:7135'],
            ]]),
            'exclusion list is not a list' => $this->header(1, [
                'excluded_products_count' => 1,
                'excluded_products' => ['okay:product:7135' => 1],
            ]),
            'exclusion count is not an integer' => $this->header(1, ['excluded_products_count' => '0']),
            // The exact class this stage exists for: the header grew a key and nobody noticed.
            // All ten required keys are present and valid here — only the eleventh is new, so
            // nothing but an EXACT key-set comparison can refuse it.
            'ten required keys plus an eleventh' => $this->header(1, ['legacy_position_base' => 0]),
            // A subset comparison would pass a reshuffled header too, and order is not cosmetic
            // here: the semantic SHA-256 that identifies the plan is taken over these bytes.
            'ten required keys in a different order' => [
                'format' => 'coresync-gallery-adoption/v2',
                'database' => 'b2bcrm_artaz',
                'source_identity' => 'okay:artaz',
                'rows_count' => 1,
                'media_plan_sha256' => str_repeat('1', 64),
                'conflict_report_sha256' => str_repeat('2', 64),
                'checkpoint_sha256' => str_repeat('3', 64),
                'snapshot_manifest_sha256' => str_repeat('4', 64),
                'excluded_products_count' => 0,
                'excluded_products' => [],
            ],
        ];
        $reader = new GalleryAdoptionPlanReader();
        foreach ($headers as $label => $header) {
            try {
                $reader->read($this->upload($this->ndjson($header, $rows)));
                self::fail($label . ' must be refused');
            } catch (GalleryAdoptionException $e) {
                self::assertStringContainsString('header', $e->getMessage(), $label);
            }
        }

        $plan = $reader->read($this->upload($this->ndjson($this->header(1), $rows)));
        self::assertSame('coresync-gallery-adoption/v2', $plan['header']['format']);
    }

    /**
     * The adopter pins the format a second time, on the already parsed plan. Both directions are
     * measured here, otherwise "the pin was moved" is indistinguishable from "the pin was removed".
     */
    public function testAdopterAcceptsOnlyTheCurrentPlanFormat(): void
    {
        $fixture = $this->fixture();
        $plan = (new GalleryAdoptionPlanReader())->read($this->upload($fixture['payload']));
        [$adopter] = $this->adopter($fixture, null, 'pending');

        $preview = $adopter->preview($plan);
        self::assertSame(['durable_adds' => 1, 'durable_updates' => 0, 'map_updates' => 1], $preview['writes']);

        $retired = $plan;
        $retired['header']['format'] = 'coresync-gallery-adoption/v1';
        try {
            $adopter->preview($retired);
            self::fail('a plan of a retired format must be refused by the adopter itself');
        } catch (GalleryAdoptionException $e) {
            self::assertStringContainsString('parsed plan contract is invalid', $e->getMessage());
        }
    }

    /**
     * The bump that made this stage necessary happened because the format tag was written down in
     * several places and only some of them moved. Here it is one constant, and this test is what
     * keeps it one: a second literal anywhere in the module fails, and the ten header keys are
     * pinned as a literal so the next producer change has to be a deliberate edit on both sides.
     */
    public function testGalleryAdoptionPlanFormatIsWrittenDownOnce(): void
    {
        self::assertSame('coresync-gallery-adoption/v2', GalleryAdoptionPlanReader::FORMAT);

        $root = dirname(__DIR__, 4) . '/Okay/Modules/Format/CoreSync';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $occurrences = [];
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $count = substr_count((string) file_get_contents($file->getPathname()), 'coresync-gallery-adoption/');
            if ($count > 0) {
                $occurrences[substr($file->getPathname(), strlen($root))] = $count;
            }
        }
        self::assertSame(['/Core/Apply/GalleryAdoptionPlanReader.php' => 1], $occurrences);

        $rows = [$this->planRow('okay:product:520', 520, 1, 900001, 0, 'a1.jpg')];
        $plan = (new GalleryAdoptionPlanReader())->read($this->upload($this->ndjson($this->header(1), $rows)));
        self::assertSame([
            'format',
            'database',
            'source_identity',
            'media_plan_sha256',
            'conflict_report_sha256',
            'checkpoint_sha256',
            'snapshot_manifest_sha256',
            'excluded_products_count',
            'excluded_products',
            'rows_count',
        ], array_keys($plan['header']));
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
            'sort' => 1,
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
            'sort' => 1,
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
            'sort' => 1,
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
        // The live storefront shape, not a convenient one: `sort` is the 1-based core rank and
        // `position` is the storefront value carried verbatim — here the commonest one, 0.
        $row = [
            'product_external_id' => '501',
            'product_local_id' => 77,
            'url' => 'https://media.example/legacy.jpg',
            'url_hash' => hash('sha256', 'https://media.example/legacy.jpg'),
            'sort' => 1,
            'image_id' => 901,
            'filename' => 'legacy.jpg',
            'position' => 0,
            'size' => strlen('legacy-image-bytes'),
            'sha256' => hash('sha256', 'legacy-image-bytes'),
        ];

        return [
            'payload' => $this->ndjson($this->header(1), [$row]),
            'row' => $row,
            'gallery_root' => $root,
        ];
    }

    /**
     * The header b2bCRM actually emits, key for key: `GalleryAdoptionPlan::from()` writes `format`
     * first, then the builder metadata, then `rows_count` last.
     *
     * @param array<string,mixed> $override
     * @param array<int,array<string,mixed>>|null $exclusions
     * @return array<string,mixed>
     */
    private function header(int $rowsCount, array $override = [], ?array $exclusions = null): array
    {
        $exclusions = $exclusions === null ? [] : $exclusions;
        $header = [
            'format' => 'coresync-gallery-adoption/v2',
            'database' => 'b2bcrm_artaz',
            'source_identity' => 'okay:artaz',
            'media_plan_sha256' => str_repeat('1', 64),
            'conflict_report_sha256' => str_repeat('2', 64),
            'checkpoint_sha256' => str_repeat('3', 64),
            'snapshot_manifest_sha256' => str_repeat('4', 64),
            'excluded_products_count' => count($exclusions),
            'excluded_products' => $exclusions,
            'rows_count' => $rowsCount,
        ];
        foreach ($override as $key => $value) {
            $header[$key] = $value;
        }

        return $header;
    }

    /**
     * @param array<string,mixed> $header
     * @param array<int,array<string,mixed>> $rows
     */
    private function ndjson(array $header, array $rows): string
    {
        $payload = $this->encode($header) . "\n";
        foreach ($rows as $row) {
            $payload .= $this->encode($row) . "\n";
        }

        return $payload;
    }

    /** @param array<string,mixed> $value */
    private function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A plan row in the exact key order of `GalleryAdoptionPlanBuilder::build()`.
     *
     * @return array<string,mixed>
     */
    private function planRow(
        string $external,
        int $local,
        int $sort,
        int $imageId,
        int $position,
        string $filename
    ): array {
        $url = 'https://media.example/' . $filename;

        return [
            'product_external_id' => $external,
            'product_local_id' => $local,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'sort' => $sort,
            'image_id' => $imageId,
            'filename' => $filename,
            'position' => $position,
            'size' => 1024 + $imageId,
            'sha256' => hash('sha256', $filename),
        ];
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
            'position' => 0,
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
