<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\Contract;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Format\CoreSync\Support\BuildsApplierEnv;

require_once __DIR__ . '/Support/BuildsApplierEnv.php';

/**
 * Стык SAT-RT: пустой slug ядра = делегирование генерации url приёмнику. Okay сам строит url (из
 * имени/id), пост-проверка коллизии НЕ применяется → товар МАПИТСЯ и идемпотентен (иначе вечная
 * «мутация ядром» → строка не в карте → пере-создаётся каждый прогон, растит дубли живого каталога).
 */
class SlugDelegationTest extends TestCase
{
    use BuildsApplierEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initStaging();
    }

    protected function tearDown(): void
    {
        $this->cleanupStaging();
        parent::tearDown();
    }

    public function testEmptySlugProductIsMappedNotErrorAndIdempotent(): void
    {
        $env = $this->buildEnv();
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', '', 'h1', [$this->variant('v1', 'SKU-1', '10.00', 5)]),
        ]);

        [$status, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $status);
        $this->assertSame(1, $stats->upserted, 'товар с пустым slug создан');
        $this->assertSame(0, $stats->errors, 'пустой slug — НЕ ошибка мутации (генерация делегирована приёмнику)');
        $this->assertNotFalse($env->map->findOne(['entity_type' => 'product', 'external_id' => '1']), 'товар с пустым slug ПОПАЛ в карту');
        // url НЕ шлётся при create (Okay сам построит из имени/id — не затираем пустым).
        $this->assertArrayNotHasKey('url', $env->prod->addCalls[0], 'url не отправлен при пустом slug (create)');

        // Идемпотентность: второй прогон той же версии → skip, ноль новых add.
        $addsAfterFirst = count($env->prod->addCalls);
        [$status2, $stats2] = $this->runApply($env, $this->productsManifest());

        $this->assertSame(Contract::STATUS_APPLIED, $status2);
        $this->assertSame(1, $stats2->skipped, 'товар с пустым slug пропущен по hash (идемпотентно)');
        $this->assertSame(0, $stats2->upserted, 'не пере-создаётся');
        $this->assertSame($addsAfterFirst, count($env->prod->addCalls), 'ни одного дубля товара во 2-м прогоне');

        // Контент изменился (новый hash) → update; url НЕ шлётся (иначе затрёт сгенерированный приёмником url → фронт-404).
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', '', 'h2', [$this->variant('v1', 'SKU-1', '11.00', 4)]),
        ]);
        [, $stats3] = $this->runApply($env, $this->productsManifest());
        $this->assertSame(1, $stats3->updated, 'изменённый товар обновлён');
        $lastUpdate = end($env->prod->updateCalls);
        $this->assertArrayNotHasKey('url', $lastUpdate[1], 'url НЕ шлётся при пустом slug (update) — Okay сохраняет свой');
    }

    public function testNonEmptySlugCollisionStillErrors(): void
    {
        // Регресс-защита: непустой slug, мутированный ядром (коллизия), ОСТАЁТСЯ ошибкой строки.
        $env = $this->buildEnv();
        $env->prod->collisionSlugs = ['phone'];
        $this->gz('products-0001.ndjson.gz', [
            $this->productLine('1', 'phone', 'h1', [$this->variant('v1', 'SKU-1', '10.00', 5)]),
        ]);

        [, $stats] = $this->runApply($env, $this->productsManifest());

        $this->assertGreaterThanOrEqual(1, $stats->errors, 'коллизия непустого slug — ошибка строки');
        $this->assertFalse($env->map->findOne(['entity_type' => 'product', 'external_id' => '1']), 'мутированная строка НЕ в карте');
    }
}
