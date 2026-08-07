<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Orders\RequestPullProvider;
use PHPUnit\Framework\TestCase;

/**
 * Выдача заявок по request.schema.json: обратный звонок `__callbacks` → type=callback, message →
 * customer.comment, страница → fields.page; курсор=max id. DB-швы переопределены фикстурами.
 */
class RequestPullProviderTest extends TestCase
{
    private function callbackRow(int $id, array $overrides = []): object
    {
        return (object) array_merge([
            'id' => $id, 'name' => 'Ольга', 'phone' => '+380671112233',
            'message' => 'перезвоните', 'date' => '2026-07-17 10:00:00', 'url' => '/products/nasos-42',
        ], $overrides);
    }

    public function testCallbackSerializationAndEnvelope(): void
    {
        $p = new TestRequestPullProvider([$this->callbackRow(77)], true);

        $res = $p->pull(null, 100);

        $this->assertSame(Contract::SCHEMA_VERSION, $res['schema_version']);
        $this->assertSame('77', $res['cursor']);
        $this->assertTrue($res['has_more']);
        $this->assertSame([77], $p->deliveredIds);

        $req = $res['requests'][0];
        $this->assertSame('77', $req['external_id']);
        $this->assertSame('callback', $req['type']);
        $this->assertNull($req['external_status']);
        $this->assertSame('Ольга', $req['customer']['name']);
        $this->assertSame('перезвоните', $req['customer']['comment']);
        $this->assertNull($req['customer']['email']);
        $this->assertNull($req['total']);
        $this->assertStringStartsWith('2026-07-17T10:00:00', (string) $req['ordered_at']);
        $this->assertSame(['page' => '/products/nasos-42'], $req['fields']);
    }

    public function testEmptyFieldsSerializeAsObject(): void
    {
        // Заявка без страницы: fields должен быть JSON-объектом {}, не массивом [] (схема: object).
        $p = new TestRequestPullProvider([$this->callbackRow(77, ['url' => null])], false);

        $req = $p->pull(null, 100)['requests'][0];
        $json = json_encode($req['fields']);
        $this->assertSame('{}', $json, 'пустой fields = {} (object), не []');
    }
}

class TestRequestPullProvider extends RequestPullProvider
{
    /** @var array<int, object> */
    private $rows;
    /** @var bool */
    private $hasMore;
    /** @var array<int> */
    public $deliveredIds = [];

    public function __construct(array $rows, bool $hasMore)
    {
        $this->rows = $rows;
        $this->hasMore = $hasMore;
    }

    protected function fetchRows(int $cursor, int $limit): array
    {
        return $this->rows;
    }

    protected function hasMoreBeyond(int $maxId): bool
    {
        return $this->hasMore;
    }

    protected function recordDelivered(array $localIds): void
    {
        $this->deliveredIds = $localIds;
    }
}
