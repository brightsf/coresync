<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Console\Command;
use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Console\ReapplyCommand;
use Okay\Modules\Format\CoreSync\Console\RebindCommand;
use Okay\Modules\Format\CoreSync\Console\RetryImagesCommand;
use Okay\Modules\Format\CoreSync\Console\StatusCommand;
use Okay\Modules\Format\CoreSync\Core\Ops\ResetCeremony;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncCategoryImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncImagesEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncJobsEntity;
use Okay\Modules\Format\CoreSync\Entities\CoreSyncMapEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

class CoreSyncConsoleCommandTest extends TestCase
{
    public function testDestructiveCommandsDeclareYesOptionInTheirRealDefinitions(): void
    {
        $symfonyConstructor = new \ReflectionMethod(
            \Symfony\Component\Console\Command\Command::class,
            '__construct'
        );

        foreach ([RebindCommand::class, ReapplyCommand::class, RetryImagesCommand::class] as $commandClass) {
            $command = (new \ReflectionClass($commandClass))->newInstanceWithoutConstructor();
            $symfonyConstructor->invoke($command);
            $definition = $command->getDefinition();

            self::assertTrue(
                $definition->hasOption('yes'),
                $commandClass . ' must declare --yes in configure()'
            );
            self::assertFalse($definition->getOption('yes')->acceptValue());
        }
    }

    public function testRebindWithoutYesRefusesBeforeStartingModulesOrReadingState(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('--yes'));

        $command = $this->getMockBuilder(RebindCommand::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['startEnabledModules', 'settings', 'jobs', 'resetCeremony', 'logger'])
            ->getMock();
        $command->expects($this->never())->method('startEnabledModules');
        $command->expects($this->never())->method('settings');
        $command->expects($this->never())->method('jobs');
        $command->expects($this->never())->method('resetCeremony');
        $command->method('logger')->willReturn($logger);

        [$exitCode, $display] = $this->executeCommand($command, false);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('--yes', $display);
    }

    public function testRebindRefusesWhileRunIsActiveWithoutCallingResetService(): void
    {
        $jobs = $this->createMock(CoreSyncJobsEntity::class);
        $jobs->expects($this->once())->method('hasActiveRun')->willReturn(true);
        $ceremony = $this->createMock(ResetCeremony::class);
        $ceremony->expects($this->never())->method('rebind');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('active run'));

        $command = $this->getMockBuilder(RebindCommand::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['startEnabledModules', 'settings', 'jobs', 'resetCeremony', 'logger'])
            ->getMock();
        $command->expects($this->never())->method('startEnabledModules');
        $command->expects($this->never())->method('settings');
        $command->method('jobs')->willReturn($jobs);
        $command->method('resetCeremony')->willReturn($ceremony);
        $command->method('logger')->willReturn($logger);

        [$exitCode, $display] = $this->executeCommand($command, true);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('active run', $display);
    }

    public function testRebindRefusesWhenModuleIsDisabledWithoutCallingResetService(): void
    {
        $jobs = $this->createMock(CoreSyncJobsEntity::class);
        $jobs->method('hasActiveRun')->willReturn(false);
        $settings = $this->createMock(Settings::class);
        $settings->expects($this->once())->method('get')
            ->with(Contract::SETTINGS_KEY)
            ->willReturn(['enabled' => 0]);
        $ceremony = $this->createMock(ResetCeremony::class);
        $ceremony->expects($this->never())->method('rebind');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('Модуль выключен'));

        $command = $this->getMockBuilder(RebindCommand::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['startEnabledModules', 'settings', 'jobs', 'resetCeremony', 'logger'])
            ->getMock();
        $command->expects($this->never())->method('startEnabledModules');
        $command->method('settings')->willReturn($settings);
        $command->method('jobs')->willReturn($jobs);
        $command->method('resetCeremony')->willReturn($ceremony);
        $command->method('logger')->willReturn($logger);

        [$exitCode, $display] = $this->executeCommand($command, true);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Модуль выключен', $display);
    }

    public function testRebindSuccessCallsExactlyTheSharedResetService(): void
    {
        $jobs = $this->createMock(CoreSyncJobsEntity::class);
        $jobs->method('hasActiveRun')->willReturn(false);
        $settings = $this->createMock(Settings::class);
        $settings->expects($this->once())->method('get')
            ->with(Contract::SETTINGS_KEY)
            ->willReturn(['enabled' => 1]);
        $ceremony = $this->createMock(ResetCeremony::class);
        $ceremony->expects($this->once())->method('rebind');

        $command = $this->getMockBuilder(RebindCommand::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['startEnabledModules', 'settings', 'jobs', 'resetCeremony', 'logger'])
            ->getMock();
        $command->expects($this->once())->method('startEnabledModules');
        $command->method('settings')->willReturn($settings);
        $command->method('jobs')->willReturn($jobs);
        $command->method('resetCeremony')->willReturn($ceremony);

        [$exitCode, $display] = $this->executeCommand($command, true);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('rebind completed', $display);
    }

    public function testRebindUnexpectedErrorReturnsTwoAndLogsWarning(): void
    {
        $jobs = $this->createMock(CoreSyncJobsEntity::class);
        $jobs->method('hasActiveRun')->willReturn(false);
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturn(['enabled' => 1]);
        $ceremony = $this->createMock(ResetCeremony::class);
        $ceremony->expects($this->once())->method('rebind')
            ->willThrowException(new \RuntimeException('database reset failed'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('database reset failed'));

        $command = $this->getMockBuilder(RebindCommand::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['startEnabledModules', 'settings', 'jobs', 'resetCeremony', 'logger'])
            ->getMock();
        $command->method('settings')->willReturn($settings);
        $command->method('jobs')->willReturn($jobs);
        $command->method('resetCeremony')->willReturn($ceremony);
        $command->method('logger')->willReturn($logger);

        [$exitCode, $display] = $this->executeCommand($command, true);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('database reset failed', $display);
    }

    public function testReapplyWithoutYesRefusesBeforeStartingModulesOrReadingState(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('--yes'));

        $command = $this->getMockBuilder(ReapplyCommand::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['startEnabledModules', 'settings', 'jobs', 'resetCeremony', 'logger'])
            ->getMock();
        $command->expects($this->never())->method('startEnabledModules');
        $command->expects($this->never())->method('settings');
        $command->expects($this->never())->method('jobs');
        $command->expects($this->never())->method('resetCeremony');
        $command->method('logger')->willReturn($logger);

        [$exitCode, $display] = $this->executeCommand($command, false);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('--yes', $display);
    }

    public function testReapplyRefusesWhileRunIsActiveWithoutCallingResetService(): void
    {
        $jobs = $this->createMock(CoreSyncJobsEntity::class);
        $jobs->expects($this->once())->method('hasActiveRun')->willReturn(true);
        $ceremony = $this->createMock(ResetCeremony::class);
        $ceremony->expects($this->never())->method('reapply');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('active run'));

        $command = $this->getMockBuilder(ReapplyCommand::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['startEnabledModules', 'settings', 'jobs', 'resetCeremony', 'logger'])
            ->getMock();
        $command->expects($this->never())->method('startEnabledModules');
        $command->expects($this->never())->method('settings');
        $command->method('jobs')->willReturn($jobs);
        $command->method('resetCeremony')->willReturn($ceremony);
        $command->method('logger')->willReturn($logger);

        [$exitCode, $display] = $this->executeCommand($command, true);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('active run', $display);
    }

    public function testReapplySuccessCallsExactlyTheSharedResetService(): void
    {
        $jobs = $this->createMock(CoreSyncJobsEntity::class);
        $jobs->expects($this->once())->method('hasActiveRun')->willReturn(false);
        $ceremony = $this->createMock(ResetCeremony::class);
        $ceremony->expects($this->once())->method('reapply');

        $command = $this->getMockBuilder(ReapplyCommand::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['startEnabledModules', 'settings', 'jobs', 'resetCeremony', 'logger'])
            ->getMock();
        $command->expects($this->once())->method('startEnabledModules');
        $command->expects($this->never())->method('settings');
        $command->method('jobs')->willReturn($jobs);
        $command->method('resetCeremony')->willReturn($ceremony);

        [$exitCode, $display] = $this->executeCommand($command, true);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('reapply completed', $display);
    }

    public function testReapplyUnexpectedErrorReturnsTwoAndLogsWarning(): void
    {
        $jobs = $this->createMock(CoreSyncJobsEntity::class);
        $jobs->method('hasActiveRun')->willReturn(false);
        $ceremony = $this->createMock(ResetCeremony::class);
        $ceremony->expects($this->once())->method('reapply')
            ->willThrowException(new \RuntimeException('image reset failed'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('image reset failed'));

        $command = $this->getMockBuilder(ReapplyCommand::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['startEnabledModules', 'settings', 'jobs', 'resetCeremony', 'logger'])
            ->getMock();
        $command->method('jobs')->willReturn($jobs);
        $command->method('resetCeremony')->willReturn($ceremony);
        $command->method('logger')->willReturn($logger);

        [$exitCode, $display] = $this->executeCommand($command, true);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('image reset failed', $display);
    }

    /** C1. Сброс попыток fail-closed так же, как reapply: без --yes ни модулей, ни чтения состояния. */
    public function testRetryImagesWithoutYesRefusesBeforeStartingModulesOrReadingState(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('--yes'));

        $command = $this->getMockBuilder(RetryImagesCommand::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['startEnabledModules', 'settings', 'jobs', 'entityFactory', 'resetCeremony', 'logger'])
            ->getMock();
        $command->expects($this->never())->method('startEnabledModules');
        $command->expects($this->never())->method('settings');
        $command->expects($this->never())->method('jobs');
        $command->expects($this->never())->method('entityFactory');
        $command->expects($this->never())->method('resetCeremony');
        $command->method('logger')->willReturn($logger);

        [$exitCode, $display] = $this->executeCommand($command, false);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('--yes', $display);
    }

    /** C2. Активный прогон: отказ код 1, сервис не вызван, счётчики не читаются. */
    public function testRetryImagesRefusesWhileRunIsActiveWithoutCallingResetService(): void
    {
        $jobs = $this->createMock(CoreSyncJobsEntity::class);
        $jobs->expects($this->once())->method('hasActiveRun')->willReturn(true);
        $ceremony = $this->createMock(ResetCeremony::class);
        $ceremony->expects($this->never())->method('retryFailedImages');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('active run'));

        $command = $this->getMockBuilder(RetryImagesCommand::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['startEnabledModules', 'settings', 'jobs', 'entityFactory', 'resetCeremony', 'logger'])
            ->getMock();
        $command->expects($this->never())->method('startEnabledModules');
        $command->expects($this->never())->method('settings');
        $command->expects($this->never())->method('entityFactory');
        $command->method('jobs')->willReturn($jobs);
        $command->method('resetCeremony')->willReturn($ceremony);
        $command->method('logger')->willReturn($logger);

        [$exitCode, $display] = $this->executeCommand($command, true);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('active run', $display);
    }

    /** C3. Успех зовёт РОВНО общий сервис и печатает счётчики обеих очередей до и после. */
    public function testRetryImagesSuccessCallsExactlyTheSharedServiceAndPrintsQueueCounters(): void
    {
        $jobs = $this->createMock(CoreSyncJobsEntity::class);
        $jobs->expects($this->once())->method('hasActiveRun')->willReturn(false);
        $ceremony = $this->createMock(ResetCeremony::class);
        $ceremony->expects($this->once())->method('retryFailedImages');
        $ceremony->expects($this->never())->method('reapply');
        $ceremony->expects($this->never())->method('rebind');

        $images = $this->createMock(CoreSyncImagesEntity::class);
        $images->method('countByState')->willReturnOnConsecutiveCalls(
            ['pending' => 0, 'done' => 7, 'failed' => 4],
            ['pending' => 0, 'done' => 7, 'failed' => 4]
        );
        $images->method('countRetryableFailed')
            ->with(Contract::IMAGE_TICK_RETRY_MAX_ATTEMPTS)
            ->willReturnOnConsecutiveCalls(1, 4);
        $categoryImages = $this->createMock(CoreSyncCategoryImagesEntity::class);
        $categoryImages->method('countByState')->willReturn(['pending' => 0, 'done' => 2, 'failed' => 0]);
        $categoryImages->method('countRetryableFailed')->willReturn(0);

        $factory = $this->createMock(EntityFactory::class);
        $factory->method('get')->willReturnCallback(
            static function (string $class) use ($images, $categoryImages) {
                if ($class === CoreSyncImagesEntity::class) {
                    return $images;
                }
                if ($class === CoreSyncCategoryImagesEntity::class) {
                    return $categoryImages;
                }

                throw new \InvalidArgumentException('Unexpected entity: ' . $class);
            }
        );

        $command = $this->getMockBuilder(RetryImagesCommand::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['startEnabledModules', 'settings', 'jobs', 'entityFactory', 'resetCeremony', 'logger'])
            ->getMock();
        $command->expects($this->once())->method('startEnabledModules');
        $command->expects($this->never())->method('settings');
        $command->method('jobs')->willReturn($jobs);
        $command->method('entityFactory')->willReturn($factory);
        $command->method('resetCeremony')->willReturn($ceremony);

        [$exitCode, $display] = $this->executeCommand($command, true);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('retry-images completed', $display);
        // Сброшенные попытки видны оператором как рост retryable за счёт exhausted.
        self::assertStringContainsString('"failed_retryable":1', $display);
        self::assertStringContainsString('"exhausted":3', $display);
        self::assertStringContainsString('"failed_retryable":4', $display);
        self::assertStringContainsString('"exhausted":0', $display);
    }

    public function testRetryImagesUnexpectedErrorReturnsTwoAndLogsWarning(): void
    {
        $jobs = $this->createMock(CoreSyncJobsEntity::class);
        $jobs->method('hasActiveRun')->willReturn(false);
        $ceremony = $this->createMock(ResetCeremony::class);
        $ceremony->expects($this->once())->method('retryFailedImages')
            ->willThrowException(new \RuntimeException('attempts reset failed'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('attempts reset failed'));

        $images = $this->createMock(CoreSyncImagesEntity::class);
        $images->method('countByState')->willReturn(['pending' => 0, 'done' => 0, 'failed' => 0]);
        $images->method('countRetryableFailed')->willReturn(0);
        $categoryImages = $this->createMock(CoreSyncCategoryImagesEntity::class);
        $categoryImages->method('countByState')->willReturn(['pending' => 0, 'done' => 0, 'failed' => 0]);
        $categoryImages->method('countRetryableFailed')->willReturn(0);
        $factory = $this->createMock(EntityFactory::class);
        $factory->method('get')->willReturnCallback(
            static function (string $class) use ($images, $categoryImages) {
                return $class === CoreSyncImagesEntity::class ? $images : $categoryImages;
            }
        );

        $command = $this->getMockBuilder(RetryImagesCommand::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['startEnabledModules', 'settings', 'jobs', 'entityFactory', 'resetCeremony', 'logger'])
            ->getMock();
        $command->method('jobs')->willReturn($jobs);
        $command->method('entityFactory')->willReturn($factory);
        $command->method('resetCeremony')->willReturn($ceremony);
        $command->method('logger')->willReturn($logger);

        [$exitCode, $display] = $this->executeCommand($command, true);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('attempts reset failed', $display);
    }

    /** Команда доезжает до оператора только через bin/coresync — регистрация рядом с тремя соседями. */
    public function testBinScriptRegistersTheRetryImagesCommand(): void
    {
        $script = dirname(__DIR__, 4) . '/Okay/Modules/Format/CoreSync/bin/coresync';
        $source = (string) file_get_contents($script);

        self::assertStringContainsString(
            'use Okay\\Modules\\Format\\CoreSync\\Console\\RetryImagesCommand;',
            $source
        );
        self::assertStringContainsString('$app->registerCommand(RetryImagesCommand::class);', $source);
    }

    /**
     * Регистрация в исходнике bin/coresync — ещё не работающая команда: класс обязан подняться в
     * ЖИВОМ Okay\Console\Application (форма пробы — CoreSyncCliBootstrapTest для трёх соседей).
     */
    public function testLiveOkayApplicationRegistersTheRetryImagesCommand(): void
    {
        $hostRoot = (string) getenv('CORESYNC_OKAY_ROOT');
        $suiteBootstrap = dirname(__DIR__, 4) . '/tools/php74-suite/bootstrap.php';
        $probe = 'require ' . var_export($suiteBootstrap, true) . ';'
            . 'chdir(' . var_export($hostRoot, true) . ');'
            // Ядро резолвит referer-parser по classmap ядра, которого у пробы нет (форма из CoreSyncCliBootstrapTest).
            . 'require ' . var_export($hostRoot . '/vendor/snowplow/referer-parser/php/src/Snowplow/RefererParser/Config/ConfigReaderInterface.php', true) . ';'
            . 'require ' . var_export($hostRoot . '/vendor/snowplow/referer-parser/php/src/Snowplow/RefererParser/Config/ConfigFileReaderTrait.php', true) . ';'
            . 'require ' . var_export($hostRoot . '/vendor/snowplow/referer-parser/php/src/Snowplow/RefererParser/Config/JsonConfigReader.php', true) . ';'
            . '$app=new Okay\\Core\\Console\\Application();'
            . '$app->registerCommand(' . RetryImagesCommand::class . '::class);'
            . 'if (!$app->has("coresync:retry-images")) { fwrite(STDERR,"missing coresync:retry-images"); exit(3); }'
            . 'echo "registered=coresync:retry-images";';

        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($probe) . ' 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertSame('registered=coresync:retry-images', implode("\n", $output));
    }

    public function testStatusPrintsTheAdminPanelStateWithoutToken(): void
    {
        $job = (object) [
            'id' => 17,
            'status' => 'applied',
            'phase' => 'done',
            'snapshot_version' => 42,
            'files_total' => 9,
            'files_done' => 9,
            'cancel_requested' => 0,
            'error_message' => null,
            'started_at' => '2026-09-02 10:00:00',
            'finished_at' => '2026-09-02 10:01:00',
        ];
        $jobs = $this->createMock(CoreSyncJobsEntity::class);
        $jobs->expects($this->once())->method('findLatest')->willReturn($job);
        $map = $this->createMock(CoreSyncMapEntity::class);
        $map->expects($this->once())->method('countByType')->willReturn(['product' => 3, 'variant' => 5]);
        $images = $this->createMock(CoreSyncImagesEntity::class);
        $images->expects($this->once())->method('countByState')->willReturn(['pending' => 2, 'done' => 7, 'failed' => 1]);
        $categoryImages = $this->createMock(CoreSyncCategoryImagesEntity::class);
        $categoryImages->expects($this->once())->method('countByState')->willReturn(['pending' => 1, 'done' => 4, 'failed' => 2]);

        $factory = $this->createMock(EntityFactory::class);
        $factory->method('get')->willReturnCallback(
            static function (string $class) use ($map, $images, $categoryImages) {
                if ($class === CoreSyncMapEntity::class) {
                    return $map;
                }
                if ($class === CoreSyncImagesEntity::class) {
                    return $images;
                }
                if ($class === CoreSyncCategoryImagesEntity::class) {
                    return $categoryImages;
                }

                throw new \InvalidArgumentException('Unexpected entity: ' . $class);
            }
        );
        $settings = $this->createMock(Settings::class);
        $settings->expects($this->once())->method('get')
            ->with(Contract::SETTINGS_KEY)
            ->willReturn(['enabled' => 1, 'token' => 'must-not-leak']);

        $command = $this->getMockBuilder(StatusCommand::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['startEnabledModules', 'settings', 'jobs', 'entityFactory', 'logger'])
            ->getMock();
        $command->expects($this->once())->method('startEnabledModules');
        $command->method('settings')->willReturn($settings);
        $command->method('jobs')->willReturn($jobs);
        $command->method('entityFactory')->willReturn($factory);

        [$exitCode, $display] = $this->executeCommand($command, false);
        $payload = json_decode(trim($display), true);

        self::assertSame(0, $exitCode);
        self::assertSame(true, $payload['enabled'] ?? null);
        self::assertSame(17, $payload['job']['id'] ?? null);
        self::assertSame(['product' => 3, 'variant' => 5], $payload['ownership'] ?? null);
        self::assertSame(['pending' => 3, 'done' => 11, 'failed' => 3], $payload['images'] ?? null);
        self::assertArrayNotHasKey('token', $payload);
        self::assertStringNotContainsString('must-not-leak', $display);
    }

    /** @return array{0:int,1:string} */
    private function executeCommand(Command $command, bool $yes): array
    {
        $definition = new InputDefinition([
            new InputOption('yes', null, InputOption::VALUE_NONE),
        ]);
        $input = new ArrayInput($yes ? ['--yes' => true] : [], $definition);
        $output = new BufferedOutput();

        foreach (['input' => $input, 'output' => $output] as $property => $value) {
            $reflection = new \ReflectionProperty(Command::class, $property);
            $reflection->setAccessible(true);
            $reflection->setValue($command, $value);
        }

        $handle = new \ReflectionMethod($command, 'handle');
        $handle->setAccessible(true);
        $exitCode = (int) $handle->invoke($command);

        return [$exitCode, $output->fetch()];
    }
}
