<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Config;
use Okay\Core\EntityFactory;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Controllers\PingController;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Describer;
use Okay\Modules\Format\CoreSync\Core\LockHelper;
use Okay\Modules\Format\CoreSync\Core\SyncRunner;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncJobsEntity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tests\Modules\Format\CoreSync\Support\RecordingBackgroundRun;

if (!defined('RESPONSE_JSON')) {
    require_once dirname(__DIR__, 4) . '/Okay/Core/config/constants.php';
}

/**
 * Пинок ядра в режиме `run_trigger = core` (coresync-trigger-module): после всех прежних гейтов
 * (подпись, форма тела, стоп-кран, активный прогон с отметкой pending) прогон не идёт в веб-запросе,
 * а запускается фоновым CLI-процессом, ответ `{"ok":true,"action":"spawned"}` сразу. Режим `cron`
 * ведёт себя как раньше.
 */
class PingControllerCoreTriggerTest extends TestCase
{
    const TOKEN = 'secret-token-abcdef';
    const PHP = '/opt/php74/bin/php';

    /** @var array<int, array{0:string,1:string}> */
    private $logs = [];

    /** @var array<string, mixed> */
    private $captured = [];

    /** @var int|null */
    private $status;

    /** @var array<int, array{0:string,1:mixed}> */
    private $sets = [];

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_SATELLITE_SIGNATURE']);
        parent::tearDown();
    }

    /**
     * Acceptance 1: режим `cron` (явный) ведёт себя как прежний пинок: run() в запросе, фоновый
     * процесс не запускается, ответ started.
     */
    public function testCronModeRunsInRequestAsBefore(): void
    {
        $background = $this->background();
        $runner = $this->createMock(SyncRunner::class);
        $runner->expects($this->once())->method('run');

        $this->ping(['run_trigger' => 'cron'], $runner, $background);

        $this->assertSame(['ok' => true, 'action' => 'started'], $this->captured);
        $this->assertNull($this->status);
        $this->assertSame([], $background->launched);
        $this->assertSame([], $background->probes, 'режим cron не пробует PHP CLI');
    }

    /**
     * Acceptance 2: режим `core`, простой -> ровно один фоновый запуск, run() в запросе не зовётся,
     * отметка pending не ставится.
     * Kill-проба: в PingController оставить $syncRunner->run() для режима core -> красный.
     */
    public function testCoreModeSpawnsBackgroundRunAndAnswersImmediately(): void
    {
        $background = $this->background();
        $runner = $this->createMock(SyncRunner::class);
        $runner->expects($this->never())->method('run');

        $this->ping(['run_trigger' => 'core', 'php_cli_path' => self::PHP], $runner, $background);

        $this->assertSame(['ok' => true, 'action' => 'spawned'], $this->captured);
        $this->assertNull($this->status);
        $this->assertSame([$background->command(self::PHP)], $background->launched);
        $this->assertSame([], $this->sets);
    }

    /** Acceptance 2: второй пинок при идущем прогоне ставит pending, как раньше, и ничего не запускает. */
    public function testCoreModeDuringActiveRunSetsPendingAndSpawnsNothing(): void
    {
        $background = $this->background();
        $runner = $this->createMock(SyncRunner::class);
        $runner->expects($this->never())->method('run');

        $this->ping(['run_trigger' => 'core', 'php_cli_path' => self::PHP], $runner, $background, true);

        $this->assertSame(['ok' => true, 'action' => 'scheduled'], $this->captured);
        $this->assertSame([['init', null], [Contract::SETTINGS_PING_PENDING_KEY, 1]], $this->sets, 'кэш перечитан до записи pending');
        $this->assertSame([], $background->launched);
    }

    /**
     * Замок занят без активного job (обновление, догон схемы, только что запущенный процесс ещё не
     * взял job): пинок ставит pending и не плодит второй процесс.
     * Kill-проба: на исходе busy не ставить pending -> красный.
     */
    public function testCoreModeWithBusyLockSetsPendingAndSpawnsNothing(): void
    {
        $lock = $this->createMock(LockHelper::class);
        $lock->method('acquire')->willReturn(false);
        $lock->method('lastFailure')->willReturn(LockHelper::FAILURE_BUSY);
        $background = $this->background($lock);

        $this->ping(['run_trigger' => 'core', 'php_cli_path' => self::PHP], $this->createMock(SyncRunner::class), $background);

        $this->assertSame(['ok' => true, 'action' => 'scheduled'], $this->captured);
        $this->assertSame([['init', null], [Contract::SETTINGS_PING_PENDING_KEY, 1]], $this->sets, 'кэш перечитан до записи pending');
        $this->assertSame([], $background->launched);
    }

    /**
     * Acceptance 3: включённый режим `core` без возможности фонового запуска -> 500 (ядро ретраит) и
     * запись в лог, прогона в запросе нет.
     * Kill-проба: на отказе отвечать 200/404 -> красный.
     */
    public function testCoreModeWithoutCapabilityAnswers500AndLogs(): void
    {
        $background = $this->background();
        $background->functions = ['proc_open' => false, 'exec' => false];
        $runner = $this->createMock(SyncRunner::class);
        $runner->expects($this->never())->method('run');

        $this->ping(['run_trigger' => 'core', 'php_cli_path' => self::PHP], $runner, $background);

        $this->assertSame(500, $this->status);
        $this->assertSame(['ok' => false, 'error' => 'background_run_unavailable'], $this->captured);
        $this->assertSame([], $background->launched);
        $this->assertSame([], $this->sets);
        $this->assertTrue($this->logged('error', 'proc_open'), 'причина в логе: ' . json_encode($this->logs, JSON_UNESCAPED_UNICODE));
    }

    public function testCoreModeWithoutLauncherServiceAnswers500(): void
    {
        $runner = $this->createMock(SyncRunner::class);
        $runner->expects($this->never())->method('run');

        $this->ping(['run_trigger' => 'core'], $runner, null);

        $this->assertSame(500, $this->status);
        $this->assertTrue($this->logged('error', 'фоновый'));
    }

    /** Стоп-кран остаётся раньше: выключенный модуль в режиме core отвечает той же 404 и ничего не запускает. */
    public function testCoreModeOnDisabledModuleStaysIndistinguishable404(): void
    {
        $background = $this->background();

        $this->ping(['run_trigger' => 'core', 'enabled' => 0, 'php_cli_path' => self::PHP],
            $this->createMock(SyncRunner::class), $background);

        $this->assertSame(404, $this->status);
        $this->assertSame([], $background->launched);
        $this->assertSame([], $this->sets);
    }

    /** Подпись остаётся первым гейтом и в режиме core. */
    public function testCoreModeWithBadSignatureSpawnsNothing(): void
    {
        $background = $this->background();

        $this->ping(['run_trigger' => 'core', 'php_cli_path' => self::PHP],
            $this->createMock(SyncRunner::class), $background, false, null, 'bad');

        $this->assertSame(404, $this->status);
        $this->assertSame([], $background->launched);
        $this->assertSame([], $background->probes);
    }

    /**
     * Acceptance 5: поля тела пинка не попадают в команду. Тело несёт поле с именем настройки пути
     * PHP и значения с метасимволами оболочки; команда равна собранной только из настроек.
     * Kill-проба: в PingController передать в spawn() настройки, слитые с телом
     * (`array_merge($cfg, $payload)`) -> красный.
     */
    public function testRequestBodyNeverReachesTheCommand(): void
    {
        $background = $this->background();
        $marker = '/tmp/pwn-marker';
        $background->versions[$marker] = RecordingBackgroundRun::cliBanner();
        $body = (string) json_encode([
            'channel_code'     => 'demo; touch ' . $marker . ' #',
            'snapshot_version' => '1 && touch ' . $marker,
            'php_cli_path'     => $marker,
        ]);

        $this->ping(['run_trigger' => 'core', 'php_cli_path' => self::PHP],
            $this->createMock(SyncRunner::class), $background, false, $body);

        $this->assertSame([$background->command(self::PHP)], $background->launched);
        $this->assertStringNotContainsString('pwn-marker', implode("\n", array_merge($background->launched, $background->probes)));
    }

    private function background(?MockObject $lock = null): RecordingBackgroundRun
    {
        if ($lock === null) {
            $lock = $this->createMock(LockHelper::class);
            $lock->method('acquire')->willReturn(true);
        }
        $run = new RecordingBackgroundRun($this->createMock(Config::class), $lock);
        $run->versions = [self::PHP => RecordingBackgroundRun::cliBanner()];

        return $run;
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function ping(
        array $cfg,
        SyncRunner $runner,
        ?RecordingBackgroundRun $background,
        bool $activeRun = false,
        ?string $body = null,
        ?string $signature = null
    ): void {
        $body = $body ?? (string) json_encode(['channel_code' => 'demo', 'snapshot_version' => 7]);
        $_SERVER['HTTP_X_SATELLITE_SIGNATURE'] = $signature ?? hash_hmac('sha256', $body, self::TOKEN);

        $request = $this->createMock(Request::class);
        $request->expects($this->any())->method('method')->willReturnCallback(static function ($method = null) {
            return $method === null ? 'POST' : strtolower((string) $method) === 'post';
        });
        $request->expects($this->any())->method('post')->willReturn($body);

        $this->captured = [];
        $this->status = null;
        $response = $this->createMock(Response::class);
        $response->method('setStatusCode')->willReturnCallback(function ($code) use ($response) {
            $this->status = (int) $code;

            return $response;
        });
        $response->method('setContent')->willReturnCallback(function ($content) use ($response) {
            $this->captured = json_decode((string) $content, true) ?: [];

            return $response;
        });

        $cfg = array_merge(['token' => self::TOKEN, 'channel_code' => '42'], $cfg);
        $this->sets = [];
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(static function (string $key) use ($cfg) {
            return $key === Contract::SETTINGS_KEY ? $cfg : null;
        });
        $settings->method('set')->willReturnCallback(function (string $key, $value): void {
            $this->sets[] = [$key, $value];
        });
        $settings->method('initSettings')->willReturnCallback(function (): void {
            $this->sets[] = ['init', null];
        });

        $jobs = $this->createMock(CoreSyncJobsEntity::class);
        $jobs->method('hasActiveRun')->willReturn($activeRun);
        $factory = $this->createMock(EntityFactory::class);
        $factory->method('get')->willReturn($jobs);

        $this->logs = [];
        $logger = $this->createMock(LoggerInterface::class);
        foreach (['error', 'warning', 'info'] as $level) {
            $logger->method($level)->willReturnCallback(function ($message) use ($level): void {
                $this->logs[] = [$level, (string) $message];
            });
        }

        (new PingController())->ping(
            $request,
            $response,
            $settings,
            $runner,
            $factory,
            $this->createMock(Describer::class),
            $logger,
            null,
            $background
        );
    }

    private function logged(string $level, string $needle): bool
    {
        foreach ($this->logs as [$logLevel, $message]) {
            if ($logLevel === $level && strpos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
