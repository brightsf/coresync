<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\EntityFactory;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Controllers\PingController;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Describer;
use Okay\Modules\Format\CoreSync\Core\Orders\OrdersSyncGateway;
use Okay\Modules\Format\CoreSync\Core\SyncRunner;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncJobsEntity;
use PHPUnit\Framework\TestCase;

if (!defined('RESPONSE_JSON')) {
    require_once dirname(__DIR__, 4) . '/Okay/Core/config/constants.php';
}

/**
 * Ветки order-action в приёмнике HMAC-канала (FEAT-ORD-M §C/§D/§F): pull/ack приходят тем же
 * `/coresync/ping`. HMAC — ПЕРВЫЙ гейт (битая подпись → 404, gateway не зовётся). Отдача данных за
 * стоп-краном (выключенный модуль → неразличимая 404). Сбой обработки → та же 404, деталей не палим.
 */
class PingControllerOrdersTest extends TestCase
{
    const TOKEN = 'secret-token-abcdef';

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_SATELLITE_SIGNATURE']);
        parent::tearDown();
    }

    private function sign(string $body, string $token = self::TOKEN): string
    {
        return hash_hmac('sha256', $body, $token);
    }

    /**
     * @param array<string, mixed> $settingsOverrides
     * @return array{0:PingController,1:Request,2:Response,3:Settings,4:SyncRunner,5:EntityFactory,6:Describer}
     */
    private function harness(string $rawBody, array $settingsOverrides = []): array
    {
        // Request определяет собственный метод method() → конфигурируем через expects()->method().
        $request = $this->createMock(Request::class);
        $request->expects($this->any())->method('isPost')->willReturn(true);
        $request->expects($this->any())->method('post')->willReturn($rawBody);

        $response = $this->createMock(Response::class);
        $response->method('setStatusCode')->willReturnSelf();

        $cfg = array_merge(['token' => self::TOKEN, 'channel_code' => '5'], $settingsOverrides);
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(fn (string $k) => $k === Contract::SETTINGS_KEY ? $cfg : null);

        $jobs = $this->createMock(CoreSyncJobsEntity::class);
        $jobs->method('hasActiveRun')->willReturn(false);
        $factory = $this->createMock(EntityFactory::class);
        $factory->method('get')->willReturn($jobs);

        $syncRunner = $this->createMock(SyncRunner::class);
        $describer = $this->createMock(Describer::class);

        return [new PingController(), $request, $response, $settings, $syncRunner, $factory, $describer];
    }

    private function captureContent(Response $resp, array &$captured): void
    {
        $resp->method('setContent')->willReturnCallback(function ($content) use (&$captured, $resp) {
            $captured = json_decode((string) $content, true) ?: [];

            return $resp;
        });
    }

    public function testPullOrdersValidSignatureDelegatesToGateway(): void
    {
        $body = (string) json_encode(['action' => 'pull_orders', 'cursor' => null, 'limit' => 100]);
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $this->sign($body);
        [$ctl, $req, $resp, $set, $runner, $factory, $describer] = $this->harness($body);

        $envelope = ['schema_version' => '1.0.0', 'orders' => [], 'cursor' => null, 'has_more' => false];
        $gateway = $this->createMock(OrdersSyncGateway::class);
        $gateway->expects($this->once())->method('handle')
            ->with('pull_orders', $this->arrayHasKey('action'))->willReturn($envelope);

        $resp->expects($this->never())->method('setStatusCode'); // не 404
        $captured = [];
        $this->captureContent($resp, $captured);

        $ctl->ping($req, $resp, $set, $runner, $factory, $describer, null, $gateway);

        $this->assertSame('1.0.0', $captured['schema_version'] ?? null);
    }

    public function testAckOrdersValidSignatureDelegatesToGateway(): void
    {
        $body = (string) json_encode(['action' => 'ack_orders', 'cursor' => '6']);
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $this->sign($body);
        [$ctl, $req, $resp, $set, $runner, $factory, $describer] = $this->harness($body);

        $gateway = $this->createMock(OrdersSyncGateway::class);
        $gateway->expects($this->once())->method('handle')->with('ack_orders', $this->anything())
            ->willReturn(['ok' => true]);

        $captured = [];
        $this->captureContent($resp, $captured);

        $ctl->ping($req, $resp, $set, $runner, $factory, $describer, null, $gateway);

        $this->assertTrue($captured['ok'] ?? false);
    }

    public function testPullOrdersDisabledModuleIs404AndDoesNotDelegate(): void
    {
        $body = (string) json_encode(['action' => 'pull_orders', 'cursor' => null, 'limit' => 100]);
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $this->sign($body);
        [$ctl, $req, $resp, $set, $runner, $factory, $describer] = $this->harness($body, ['enabled' => 0]);

        $gateway = $this->createMock(OrdersSyncGateway::class);
        $gateway->expects($this->never())->method('handle'); // стоп-кран §F
        $resp->expects($this->atLeastOnce())->method('setStatusCode')->with(404);

        $ctl->ping($req, $resp, $set, $runner, $factory, $describer, null, $gateway);
    }

    public function testPullOrdersBadSignatureIs404AndDoesNotDelegate(): void
    {
        $body = (string) json_encode(['action' => 'pull_orders', 'cursor' => null, 'limit' => 100]);
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $this->sign($body, 'WRONG-TOKEN');
        [$ctl, $req, $resp, $set, $runner, $factory, $describer] = $this->harness($body);

        $gateway = $this->createMock(OrdersSyncGateway::class);
        $gateway->expects($this->never())->method('handle'); // HMAC — первый гейт
        $resp->expects($this->atLeastOnce())->method('setStatusCode')->with(404);

        $ctl->ping($req, $resp, $set, $runner, $factory, $describer, null, $gateway);
    }

    public function testGatewayFailureIs404(): void
    {
        $body = (string) json_encode(['action' => 'pull_orders', 'cursor' => null, 'limit' => 100]);
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $this->sign($body);
        [$ctl, $req, $resp, $set, $runner, $factory, $describer] = $this->harness($body);

        $gateway = $this->createMock(OrdersSyncGateway::class);
        $gateway->method('handle')->willThrowException(new \RuntimeException('boom'));
        $resp->expects($this->atLeastOnce())->method('setStatusCode')->with(404);

        $ctl->ping($req, $resp, $set, $runner, $factory, $describer, null, $gateway);
    }
}
