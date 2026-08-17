<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\ReportClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Стык SAT-RT: тело apply-report обязано соответствовать контракту SAT-B — snapshot_version и status
 * на ВЕРХНЕМ уровне, stats? (nullable array), error_message? (не «error»). Иначе ядро отвечает 422,
 * отчёт не сохраняется, health не двигается. Проверяем ФАКТИЧЕСКУЮ форму (reflection на buildPayload).
 */
class ReportClientTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function build(string $status, array $stats, ?string $error): array
    {
        $m = new ReflectionMethod(ReportClient::class, 'buildPayload');
        $m->setAccessible(true);
        $json = $m->invoke(new ReportClient(null), $status, $stats, $error);

        return json_decode((string) $json, true);
    }

    public function testAppliedPayloadHasTopLevelSnapshotVersionAndStats(): void
    {
        $p = $this->build('applied', ['snapshot_version' => 4, 'upserted' => 10, 'skipped' => 3], null);

        $this->assertSame(4, $p['snapshot_version'], 'snapshot_version на верхнем уровне');
        $this->assertSame('applied', $p['status']);
        $this->assertArrayNotHasKey('snapshot_version', $p['stats'], 'snapshot_version вынут из stats');
        $this->assertSame(10, $p['stats']['upserted']);
        $this->assertSame(3, $p['stats']['skipped']);
        $this->assertNull($p['error_message']);
        $this->assertArrayNotHasKey('error', $p, 'ключ error переименован в error_message');
    }

    public function testStartedPayloadStatsNullWhenOnlyVersion(): void
    {
        $p = $this->build('started', ['snapshot_version' => 7], null);

        $this->assertSame(7, $p['snapshot_version']);
        $this->assertSame('started', $p['status']);
        $this->assertNull($p['stats'], 'пустой stats после выемки version → null (contract: nullable)');
    }

    public function testFailedPayloadUsesErrorMessageKey(): void
    {
        $p = $this->build('failed', ['snapshot_version' => 4, 'phase' => 'apply'], 'apply failed');

        $this->assertSame('apply failed', $p['error_message'], 'ошибка едет в error_message');
        $this->assertSame('apply', $p['stats']['phase']);
    }

    public function testInventoryRequestUsesExactEndpointAndBodyContract(): void
    {
        $this->assertTrue(method_exists(ReportClient::class, 'buildInventoryUrl'));
        $this->assertTrue(method_exists(ReportClient::class, 'buildInventoryPayload'));

        $client = new ReportClient(null);
        $urlMethod = new ReflectionMethod(ReportClient::class, 'buildInventoryUrl');
        $urlMethod->setAccessible(true);
        $payloadMethod = new ReflectionMethod(ReportClient::class, 'buildInventoryPayload');
        $payloadMethod->setAccessible(true);

        $url = $urlMethod->invoke(
            $client,
            'https://core.example/',
            'channel/42',
            'token with spaces'
        );
        $payload = json_decode((string) $payloadMethod->invoke(
            $client,
            'Format/CoreSync',
            '1.5.4+build.7'
        ), true);

        $this->assertSame(
            'https://core.example/api/satellite/channel%2F42/inventory?token=token%20with%20spaces',
            $url
        );
        $this->assertSame([
            'module_name' => 'Format/CoreSync',
            'module_version' => '1.5.4+build.7',
        ], $payload);
    }

    public function testInventoryTransportFailureLogsBoundedWarningWithoutTokenOrBody(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('CoreSync: inventory-report не доставлен');

        $client = new ReportClient($logger);
        $client->sendInventory(
            'coresync-inventory-missing-wrapper://core.example',
            '42',
            'secret-token-marker',
            'Format/CoreSync',
            '1.5.4+raw-body-marker'
        );

        $this->addToAssertionCount(1);
    }

    public function testInventoryResponseStatusAcceptsOnlyTwoHundreds(): void
    {
        $this->assertTrue(method_exists(ReportClient::class, 'isSuccessfulInventoryResponse'));

        $method = new ReflectionMethod(ReportClient::class, 'isSuccessfulInventoryResponse');
        $method->setAccessible(true);
        $client = new ReportClient(null);

        $this->assertTrue($method->invoke($client, ['HTTP/1.1 204 No Content']));
        $this->assertTrue($method->invoke($client, [
            'HTTP/1.1 301 Moved Permanently',
            'HTTP/2 204',
        ]));
        $this->assertFalse($method->invoke($client, ['HTTP/1.1 422 Unprocessable Entity']));
        $this->assertFalse($method->invoke($client, []));
    }
}
