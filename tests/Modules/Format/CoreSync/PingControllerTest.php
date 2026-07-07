<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\EntityFactory;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Controllers\PingController;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\SyncRunner;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncJobsEntity;
use PHPUnit\Framework\TestCase;

// RESPONSE_JSON (и прочие RESPONSE_*) живут в Okay/Core/config/constants.php — bootstrap phpunit их
// не грузит. Подгружаем реальный файл (чистые const, без сайд-эффектов); require_once дедупит по
// realpath, если app-бут уже загрузил его в полном прогоне.
if (!defined('RESPONSE_JSON')) {
    require_once dirname(__DIR__, 4) . '/Okay/Core/config/constants.php';
}

/**
 * Приёмник HMAC-пинка (SAT-RT §0.2): валидная подпись запускает/планирует прогон, невалидная — 404,
 * тело не логируется, канал-мисматч — 404.
 */
class PingControllerTest extends TestCase
{
    const TOKEN = 'secret-token-abcdef';
    const CHANNEL = 'demo';

    /** @var array<string, mixed> */
    private $loggerMessages = [];

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_SATELLITE_SIGNATURE']);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function body(array $overrides = []): string
    {
        return (string) json_encode(array_merge([
            'channel_code'     => self::CHANNEL,
            'snapshot_version' => 7,
        ], $overrides));
    }

    private function sign(string $body, string $token = self::TOKEN): string
    {
        return hash_hmac('sha256', $body, $token);
    }

    /**
     * @return array{0:PingController,1:Request,2:Response,3:Settings,4:SyncRunner,5:EntityFactory,6:CoreSyncJobsEntity}
     */
    private function harness(bool $isPost, string $rawBody, bool $activeRun): array
    {
        // Request определяет собственный метод method() → конфигурируем мок через expects()->method(),
        // иначе $mock->method('post') зовёт замоканный Request::method() (возвращает null).
        $request = $this->createMock(Request::class);
        $request->expects($this->any())->method('isPost')->willReturn($isPost);
        $request->expects($this->any())->method('post')->willReturn($rawBody);

        $response = $this->createMock(Response::class);
        $response->expects($this->any())->method('setStatusCode')->willReturnSelf();
        // setContent НЕ пре-стабим: 404-тесты оставляют дефолт (null), started/scheduled-тесты
        // навешивают capture-callback сами (второй any-matcher не переопределяет первый в PHPUnit).

        $settings = $this->createMock(Settings::class);
        $settings->expects($this->any())->method('get')->willReturnCallback(function (string $key) {
            if ($key === Contract::SETTINGS_KEY) {
                return ['token' => self::TOKEN, 'channel_code' => self::CHANNEL];
            }

            return null;
        });

        $jobs = $this->createMock(CoreSyncJobsEntity::class);
        $jobs->expects($this->any())->method('hasActiveRun')->willReturn($activeRun);

        $factory = $this->createMock(EntityFactory::class);
        $factory->expects($this->any())->method('get')->willReturn($jobs);

        $syncRunner = $this->createMock(SyncRunner::class);

        return [new PingController(), $request, $response, $settings, $syncRunner, $factory, $jobs];
    }

    private function logger(): \Psr\Log\LoggerInterface
    {
        $this->loggerMessages = [];
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $capture = function ($message) {
            $this->loggerMessages[] = (string) $message;
        };
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $m) {
            $logger->method($m)->willReturnCallback($capture);
        }

        return $logger;
    }

    public function testValidSignatureIdleStartsRun(): void
    {
        $body = $this->body();
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $this->sign($body);
        [$ctl, $req, $resp, $set, $runner, $factory] = $this->harness(true, $body, false);

        $runner->expects($this->once())->method('run'); // прогон запущен
        $set->expects($this->never())->method('set');   // не помечаем «запланирован»
        $resp->expects($this->never())->method('setStatusCode'); // не 404

        $captured = [];
        $resp->method('setContent')->willReturnCallback(function ($content) use (&$captured, $resp) {
            $captured = (array) $content;

            return $resp;
        });

        $ctl->ping($req, $resp, $set, $runner, $factory, $this->logger());

        $this->assertSame('started', $captured['action'] ?? null);
    }

    public function testValidSignatureDuringRunSchedules(): void
    {
        $body = $this->body();
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $this->sign($body);
        [$ctl, $req, $resp, $set, $runner, $factory] = $this->harness(true, $body, true);

        $runner->expects($this->never())->method('run'); // второй прогон НЕ запускаем
        $set->expects($this->once())->method('set')->with(Contract::SETTINGS_PING_PENDING_KEY, 1);

        $captured = [];
        $resp->method('setContent')->willReturnCallback(function ($content) use (&$captured, $resp) {
            $captured = (array) $content;

            return $resp;
        });

        $ctl->ping($req, $resp, $set, $runner, $factory, $this->logger());

        $this->assertSame('scheduled', $captured['action'] ?? null);
    }

    public function testInvalidSignatureIs404AndBodyNotLogged(): void
    {
        $body = $this->body();
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $this->sign($body, 'WRONG-TOKEN');
        [$ctl, $req, $resp, $set, $runner, $factory] = $this->harness(true, $body, false);

        $runner->expects($this->never())->method('run');
        $set->expects($this->never())->method('set');
        $resp->expects($this->atLeastOnce())->method('setStatusCode')->with(404);

        $logger = $this->logger();
        $ctl->ping($req, $resp, $set, $runner, $factory, $logger);

        // Тело (и его значимые куски) НЕ попали в лог.
        foreach ($this->loggerMessages as $msg) {
            $this->assertStringNotContainsString('snapshot_version', $msg);
            $this->assertStringNotContainsString(self::CHANNEL, $msg);
        }
    }

    public function testMissingSignatureIs404(): void
    {
        $body = $this->body();
        unset($_SERVER['HTTP_X_SATELLITE_SIGNATURE']);
        [$ctl, $req, $resp, $set, $runner, $factory] = $this->harness(true, $body, false);

        $runner->expects($this->never())->method('run');
        $resp->expects($this->atLeastOnce())->method('setStatusCode')->with(404);

        $ctl->ping($req, $resp, $set, $runner, $factory, $this->logger());
    }

    public function testNonPostIs404(): void
    {
        $body = $this->body();
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $this->sign($body);
        [$ctl, $req, $resp, $set, $runner, $factory] = $this->harness(false, $body, false);

        $runner->expects($this->never())->method('run');
        $resp->expects($this->atLeastOnce())->method('setStatusCode')->with(404);

        $ctl->ping($req, $resp, $set, $runner, $factory, $this->logger());
    }

    public function testValidSignatureWrongChannelIs404(): void
    {
        // Подпись валидна (тем же токеном), но channel_code в теле не совпадает с настройкой.
        $body = $this->body(['channel_code' => 'other-channel']);
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $this->sign($body);
        [$ctl, $req, $resp, $set, $runner, $factory] = $this->harness(true, $body, false);

        $runner->expects($this->never())->method('run');
        $resp->expects($this->atLeastOnce())->method('setStatusCode')->with(404);

        $ctl->ping($req, $resp, $set, $runner, $factory, $this->logger());
    }
}
