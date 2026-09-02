<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Console\Command;
use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Console\ReapplyCommand;
use Okay\Modules\Format\CoreSync\Console\RebindCommand;
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
