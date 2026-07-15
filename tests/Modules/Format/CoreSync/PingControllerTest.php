<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\EntityFactory;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Controllers\PingController;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Describer;
use Okay\Modules\Format\CoreSync\Core\Exceptions\DescribeUnavailableException;
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

    /** Тело церемонии подключения — ровно то, что шлёт ядро (SatelliteDescribeService). */
    private function describeBody(): string
    {
        return (string) json_encode(['action' => 'describe']);
    }

    /**
     * @return array<string, mixed>
     */
    private function description(): array
    {
        return [
            'schema_version' => '1.0.0',
            'module'         => ['name' => 'Format/CoreSync', 'version' => '1.1.0'],
            'storefront'     => ['base_url' => 'https://shop.example'],
            'url_patterns'   => ['product' => '/products/{slug}/'],
            'capabilities'   => ['sync_modes' => ['full', 'price_stock']],
        ];
    }

    /**
     * @param array<string, mixed> $settingsOverrides
     * @return array{0:PingController,1:Request,2:Response,3:Settings,4:SyncRunner,5:EntityFactory,6:Describer}
     */
    private function harness(bool $isPost, string $rawBody, bool $activeRun, array $settingsOverrides = []): array
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

        // Ключа `enabled` здесь НЕТ (как на уже настроенных установках) — все прочие тесты класса
        // гоняются на этих настройках и стерегут дефолт «отсутствует = включено».
        $cfg = array_merge(['token' => self::TOKEN, 'channel_code' => self::CHANNEL], $settingsOverrides);

        $settings = $this->createMock(Settings::class);
        $settings->expects($this->any())->method('get')->willReturnCallback(function (string $key) use ($cfg) {
            if ($key === Contract::SETTINGS_KEY) {
                return $cfg;
            }

            return null;
        });

        $jobs = $this->createMock(CoreSyncJobsEntity::class);
        $jobs->expects($this->any())->method('hasActiveRun')->willReturn($activeRun);

        $factory = $this->createMock(EntityFactory::class);
        $factory->expects($this->any())->method('get')->willReturn($jobs);

        $syncRunner = $this->createMock(SyncRunner::class);

        $describer = $this->createMock(Describer::class);
        $describer->expects($this->any())->method('describe')->willReturn($this->description());

        return [new PingController(), $request, $response, $settings, $syncRunner, $factory, $describer];
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
        [$ctl, $req, $resp, $set, $runner, $factory, $describer] = $this->harness(true, $body, false);

        $runner->expects($this->once())->method('run'); // прогон запущен
        $set->expects($this->never())->method('set');   // не помечаем «запланирован»
        $resp->expects($this->never())->method('setStatusCode'); // не 404

        $captured = [];
        $resp->method('setContent')->willReturnCallback(function ($content) use (&$captured, $resp) {
            $captured = json_decode((string) $content, true) ?: [];

            return $resp;
        });

        $ctl->ping($req, $resp, $set, $runner, $factory, $describer, $this->logger());

        $this->assertSame('started', $captured['action'] ?? null);
    }

    public function testValidSignatureDuringRunSchedules(): void
    {
        $body = $this->body();
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $this->sign($body);
        [$ctl, $req, $resp, $set, $runner, $factory, $describer] = $this->harness(true, $body, true);

        $runner->expects($this->never())->method('run'); // второй прогон НЕ запускаем
        $set->expects($this->once())->method('set')->with(Contract::SETTINGS_PING_PENDING_KEY, 1);

        $captured = [];
        $resp->method('setContent')->willReturnCallback(function ($content) use (&$captured, $resp) {
            $captured = json_decode((string) $content, true) ?: [];

            return $resp;
        });

        $ctl->ping($req, $resp, $set, $runner, $factory, $describer, $this->logger());

        $this->assertSame('scheduled', $captured['action'] ?? null);
    }

    public function testInvalidSignatureIs404AndBodyNotLogged(): void
    {
        $body = $this->body();
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $this->sign($body, 'WRONG-TOKEN');
        [$ctl, $req, $resp, $set, $runner, $factory, $describer] = $this->harness(true, $body, false);

        $runner->expects($this->never())->method('run');
        $set->expects($this->never())->method('set');
        $resp->expects($this->atLeastOnce())->method('setStatusCode')->with(404);

        $logger = $this->logger();
        $ctl->ping($req, $resp, $set, $runner, $factory, $describer, $logger);

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
        [$ctl, $req, $resp, $set, $runner, $factory, $describer] = $this->harness(true, $body, false);

        $runner->expects($this->never())->method('run');
        $resp->expects($this->atLeastOnce())->method('setStatusCode')->with(404);

        $ctl->ping($req, $resp, $set, $runner, $factory, $describer, $this->logger());
    }

    public function testNonPostIs404(): void
    {
        $body = $this->body();
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $this->sign($body);
        [$ctl, $req, $resp, $set, $runner, $factory, $describer] = $this->harness(false, $body, false);

        $runner->expects($this->never())->method('run');
        $resp->expects($this->atLeastOnce())->method('setStatusCode')->with(404);

        $ctl->ping($req, $resp, $set, $runner, $factory, $describer, $this->logger());
    }

    public function testValidSignatureStartsRegardlessOfBodyChannelCode(): void
    {
        // Стык SAT-RT: URL-настройка модуля = channel_id ядра, а тело пинка несёт строковый
        // channel_code — идентификаторы разные, поэтому тело channel_code НЕ сверяется. Валидная
        // подпись (per-channel token) запускает прогон независимо от строки в теле.
        $body = $this->body(['channel_code' => 'main-site-string-code']);
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $this->sign($body);
        [$ctl, $req, $resp, $set, $runner, $factory, $describer] = $this->harness(true, $body, false);

        $runner->expects($this->once())->method('run'); // не 404 — прогон запущен
        $resp->expects($this->never())->method('setStatusCode');

        $ctl->ping($req, $resp, $set, $runner, $factory, $describer, $this->logger());
    }

    // ------------------------------------------------------------------
    // Церемония подключения (SATFEED-M §A/§B)
    // ------------------------------------------------------------------

    /**
     * KILL-ПРОБА §A. Церемония НЕ имеет права запустить синхронизацию каталога: до правки приёмник
     * звал syncRunner->run() по факту валидной подписи, не глядя в тело. Мутация «убрать ветвление
     * по action» обязана красить этот тест.
     */
    public function testDescribeActionDoesNotStartRun(): void
    {
        $body = $this->describeBody();
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $this->sign($body);
        [$ctl, $req, $resp, $set, $runner, $factory, $describer] = $this->harness(true, $body, false);

        $runner->expects($this->never())->method('run'); // ← сердце пункта A
        $set->expects($this->never())->method('set');    // прогон не трогаем вообще
        $resp->expects($this->never())->method('setStatusCode');

        $captured = [];
        $resp->method('setContent')->willReturnCallback(function ($content) use (&$captured, $resp) {
            $captured = json_decode((string) $content, true) ?: [];

            return $resp;
        });

        $ctl->ping($req, $resp, $set, $runner, $factory, $describer, $this->logger());

        $this->assertSame($this->description(), $captured);
    }

    /** Церемония во время прогона — тоже просто описание: ни второго прогона, ни «запланирован». */
    public function testDescribeActionDuringActiveRunStillDescribesAndDoesNotTouchRun(): void
    {
        $body = $this->describeBody();
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $this->sign($body);
        [$ctl, $req, $resp, $set, $runner, $factory, $describer] = $this->harness(true, $body, true);

        $runner->expects($this->never())->method('run');
        $set->expects($this->never())->method('set'); // НЕ помечаем отложенный пинок
        $resp->expects($this->never())->method('setStatusCode');

        $captured = [];
        $resp->method('setContent')->willReturnCallback(function ($content) use (&$captured, $resp) {
            $captured = json_decode((string) $content, true) ?: [];

            return $resp;
        });

        $ctl->ping($req, $resp, $set, $runner, $factory, $describer, $this->logger());

        $this->assertSame('1.0.0', $captured['schema_version'] ?? null);
    }

    /**
     * Тело неизвестной формы прогон не запускает (threat-model §2: тяжёлая работа не стартует по
     * телу, которое никто не проверил) — и отказ неразличим, как любой другой.
     *
     * @dataProvider unknownBodies
     */
    public function testUnknownBodyShapeDoesNotStartRun(string $body): void
    {
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $this->sign($body);
        [$ctl, $req, $resp, $set, $runner, $factory, $describer] = $this->harness(true, $body, false);

        $runner->expects($this->never())->method('run');
        $set->expects($this->never())->method('set');
        $resp->expects($this->atLeastOnce())->method('setStatusCode')->with(404);

        $ctl->ping($req, $resp, $set, $runner, $factory, $describer, $this->logger());
    }

    /**
     * @return array<string, array{0:string}>
     */
    public function unknownBodies(): array
    {
        return [
            'unknown action'    => ['{"action":"selfdestruct"}'],
            'empty object'      => ['{}'],
            'not json'          => ['nonsense'],
            'json list'         => ['[1,2,3]'],
            'empty body'        => [''],
            'half ping body'    => ['{"channel_code":"demo"}'],
            'describe as value' => ['{"foo":"describe"}'],
        ];
    }

    /**
     * KILL-ПРОБА §4 приёмки: подпись остаётся ПЕРВЫМ гейтом. Аноним не получает описание и не может
     * отличить «модуль знает describe» от «не знает».
     */
    public function testDescribeWithoutValidSignatureIs404AndLeaksNothing(): void
    {
        $body = $this->describeBody();
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $this->sign($body, 'WRONG-TOKEN');
        [$ctl, $req, $resp, $set, $runner, $factory, $describer] = $this->harness(true, $body, false);

        $describer->expects($this->never())->method('describe'); // описание даже не собирается
        $runner->expects($this->never())->method('run');
        $resp->expects($this->atLeastOnce())->method('setStatusCode')->with(404);

        $captured = [];
        $resp->method('setContent')->willReturnCallback(function ($content) use (&$captured, $resp) {
            $captured[] = (string) $content;

            return $resp;
        });

        $ctl->ping($req, $resp, $set, $runner, $factory, $describer, $this->logger());

        foreach ($captured as $content) {
            $this->assertStringNotContainsString('shop.example', $content);
            $this->assertStringNotContainsString('url_patterns', $content);
            $this->assertStringNotContainsString('schema_version', $content);
        }
    }

    /**
     * Threat-model §4: снаружи «плохая подпись» и «неизвестный action» обязаны выглядеть ОДИНАКОВО —
     * иначе аноним пробирует, какие действия модуль знает.
     */
    public function testBadSignatureAndUnknownActionAreIndistinguishable(): void
    {
        $badSignature = $this->capture($this->describeBody(), 'WRONG-TOKEN');
        $unknownAction = $this->capture('{"action":"selfdestruct"}', self::TOKEN);

        $this->assertSame($badSignature, $unknownAction);
    }

    /**
     * Не настроен адрес витрины → описание не собирается. Ответ — та же неразличимая 404 (новых
     * кодов/текстов не заводим), причина — только в лог модуля, оператору.
     */
    public function testDescribeWhenUnavailableIs404(): void
    {
        $body = $this->describeBody();
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $this->sign($body);
        [$ctl, $req, $resp, $set, $runner, $factory, $describer] = $this->harness(true, $body, false);

        $describer = $this->createMock(Describer::class);
        $describer->expects($this->once())->method('describe')
            ->willThrowException(new DescribeUnavailableException('Не задан адрес витрины (storefront_base_url)'));

        $runner->expects($this->never())->method('run');
        $resp->expects($this->atLeastOnce())->method('setStatusCode')->with(404);

        $ctl->ping($req, $resp, $set, $runner, $factory, $describer, $this->logger());
    }

    /** Церемония, как и пинок, только POST. */
    public function testDescribeViaNonPostIs404(): void
    {
        $body = $this->describeBody();
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $this->sign($body);
        [$ctl, $req, $resp, $set, $runner, $factory, $describer] = $this->harness(false, $body, false);

        $describer->expects($this->never())->method('describe');
        $resp->expects($this->atLeastOnce())->method('setStatusCode')->with(404);

        $ctl->ping($req, $resp, $set, $runner, $factory, $describer, $this->logger());
    }

    // ------------------------------------------------------------------
    // Стоп-кран (SATGO-1 §A) — вход «пинок»
    // ------------------------------------------------------------------

    /**
     * ВХОД 2 из 3 (публичный приёмник пинка). Выключенный модуль по пинку прогон не запускает.
     * Семантика отказа — та же НЕРАЗЛИЧИМАЯ 404, что и на прочих отказах: снаружи нельзя отличить
     * «модуль выключен» от «нет такого роута», иначе выключенный модуль становится детектируемым.
     *
     * KILL-ПРОБА (мутация 2 из 3, независимая): убрать гейт из PingController → ответ станет
     * `{"ok":true,"action":"started"}` вместо 404 → тест красный. Гейт в SyncRunner этот тест НЕ
     * прикрывает: он останавливает работу, но приёмник всё равно отвечал бы «started» — то есть врал.
     */
    public function testDisabledModulePingIs404AndDoesNotStartRun(): void
    {
        $body = $this->body();
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $this->sign($body);
        [$ctl, $req, $resp, $set, $runner, $factory, $describer] = $this->harness(true, $body, false, ['enabled' => 0]);

        $runner->expects($this->never())->method('run');
        $resp->expects($this->atLeastOnce())->method('setStatusCode')->with(404);
        // D-SAT-PING-PENDING-UNREAD не усугубляем: выключенный модуль не копит отложенный пинок
        // (иначе включение задним числом выстрелило бы прогоном «за прошлое»).
        $set->expects($this->never())->method('set');

        $ctl->ping($req, $resp, $set, $runner, $factory, $describer, $this->logger());
    }

    /**
     * Выключение не должно давать наружу нового различимого сигнала: «модуль выключен» и «плохая
     * подпись» — байт-в-байт один и тот же ответ.
     */
    public function testDisabledModulePingIsIndistinguishableFromBadSignature(): void
    {
        $disabled = $this->capture($this->body(), self::TOKEN, ['enabled' => 0]);
        $badSignature = $this->capture($this->body(), 'WRONG-TOKEN');

        $this->assertSame($badSignature, $disabled);
    }

    /**
     * Приёмка §5: церемония подключения гейтом НЕ закрыта — describe отвечает как прежде.
     * Он ничего не синхронизирует (чистое чтение), а ядру нужно уметь подключить/переподключить
     * сателлит, который оператор ещё не включил. Стоп-кран останавливает обмен, а не знакомство.
     */
    public function testDisabledModuleStillAnswersDescribe(): void
    {
        $body = $this->describeBody();
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $this->sign($body);
        [$ctl, $req, $resp, $set, $runner, $factory, $describer] = $this->harness(true, $body, false, ['enabled' => 0]);

        $runner->expects($this->never())->method('run');
        $resp->expects($this->never())->method('setStatusCode');

        $captured = [];
        $resp->method('setContent')->willReturnCallback(function ($content) use (&$captured, $resp) {
            $captured = json_decode((string) $content, true) ?: [];

            return $resp;
        });

        $ctl->ping($req, $resp, $set, $runner, $factory, $describer, $this->logger());

        $this->assertSame($this->description(), $captured);
    }

    /**
     * Снимок наблюдаемого снаружи ответа: [код, тело]. Для сравнения отказов между собой.
     *
     * @param array<string, mixed> $settingsOverrides
     * @return array{0:array<int, int>, 1:array<int, string>}
     */
    private function capture(string $body, string $token, array $settingsOverrides = []): array
    {
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $this->sign($body, $token);
        [$ctl, $req, $resp, $set, $runner, $factory, $describer] = $this->harness(true, $body, false, $settingsOverrides);

        $codes = [];
        $contents = [];
        $resp->method('setStatusCode')->willReturnCallback(function ($code) use (&$codes, $resp) {
            $codes[] = (int) $code;

            return $resp;
        });
        $resp->method('setContent')->willReturnCallback(function ($content) use (&$contents, $resp) {
            $contents[] = (string) $content;

            return $resp;
        });

        $ctl->ping($req, $resp, $set, $runner, $factory, $describer, $this->logger());

        return [$codes, $contents];
    }
}
