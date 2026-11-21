<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Console\Command;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Console\RunCommand;
use Okay\Modules\Format\CoreSync\Core\BackgroundRun;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\LockHelper;
use Okay\Modules\Format\CoreSync\Core\SyncRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `bin/coresync coresync:run`: тонкая команда фонового прогона по пинку ядра. Гонит тот же
 * SyncRunner::run(), что и крон. Если замок занят (процесс проиграл гонку другому прогону), пинок не
 * теряется: ставится отметка pending, её добирает держатель замка.
 */
class CoreSyncRunCommandTest extends TestCase
{
    public function testRunDelegatesToSyncRunnerAndReportsOutcome(): void
    {
        $runner = $this->createMock(SyncRunner::class);
        $runner->expects($this->once())->method('run')->willReturn(SyncRunner::OUTCOME_STARTED);
        $runner->expects($this->never())->method('runScheduled');
        $settings = $this->createMock(Settings::class);
        $settings->expects($this->never())->method('set');

        [$exitCode, $display] = $this->execute($this->command($runner, $settings));

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('CoreSync run: started', $display);
    }

    /**
     * Kill-проба: убрать отметку pending на lock_busy -> красный. Кэш настроек перечитывается до
     * записи: Settings::set() не пишет значение, равное устаревшему кэшу.
     */
    public function testBusyLockLeavesPendingMarkForTheLockHolder(): void
    {
        $runner = $this->createMock(SyncRunner::class);
        $runner->method('run')->willReturn(SyncRunner::OUTCOME_LOCK_BUSY);
        $settings = $this->createMock(Settings::class);
        $order = [];
        $settings->expects($this->once())->method('initSettings')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'init';
        });
        $settings->expects($this->once())->method('set')->with(Contract::SETTINGS_PING_PENDING_KEY, 1)
            ->willReturnCallback(static function () use (&$order): void {
                $order[] = 'set';
            });

        [$exitCode, $display] = $this->execute($this->command($runner, $settings));

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('lock_busy', $display);
        $this->assertSame(['init', 'set'], $order);
    }

    /**
     * F1: после бутстрапа процесс проверяет замок и снимает маркер запуска, до прогона. Дальше от
     * второго процесса защищает замок. Kill-проба: не снимать маркер -> красный.
     */
    public function testClaimIsClearedAfterLockCheckBeforeTheRun(): void
    {
        $order = [];
        $runner = $this->createMock(SyncRunner::class);
        $runner->method('run')->willReturnCallback(static function () use (&$order): string {
            $order[] = 'run';

            return SyncRunner::OUTCOME_STARTED;
        });
        $lock = $this->createMock(LockHelper::class);
        $lock->method('acquire')->willReturnCallback(static function () use (&$order): bool {
            $order[] = 'acquire';

            return true;
        });
        $lock->method('release')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'release';
        });
        $background = $this->createMock(BackgroundRun::class);
        $background->expects($this->once())->method('clearClaim')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'clear';
        });

        $this->execute($this->command($runner, $this->createMock(Settings::class), $lock, $background));

        $this->assertSame(['acquire', 'release', 'clear', 'run'], $order);
    }

    public function testUnavailableLockAndErrorsExitNonZero(): void
    {
        $runner = $this->createMock(SyncRunner::class);
        $runner->method('run')->willReturn(SyncRunner::OUTCOME_LOCK_UNAVAILABLE);
        [$exitCode] = $this->execute($this->command($runner, $this->createMock(Settings::class)));
        $this->assertSame(1, $exitCode);

        $runner = $this->createMock(SyncRunner::class);
        $runner->method('run')->willThrowException(new \RuntimeException('boom'));
        [$exitCode, $display] = $this->execute($this->command($runner, $this->createMock(Settings::class)));
        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('boom', $display);
    }

    public function testBinScriptRegistersTheRunCommand(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/Okay/Modules/Format/CoreSync/bin/coresync');

        $this->assertStringContainsString('$app->registerCommand(RunCommand::class);', $source);
        $this->assertStringContainsString('use Okay\Modules\Format\CoreSync\Console\RunCommand;', $source);

        $symfonyConstructor = new \ReflectionMethod(\Symfony\Component\Console\Command\Command::class, '__construct');
        $command = (new \ReflectionClass(RunCommand::class))->newInstanceWithoutConstructor();
        $symfonyConstructor->invoke($command);
        $this->assertSame('coresync:run', $command->getName());
        $this->assertSame([], $command->getDefinition()->getArguments(), 'команда без аргументов: данных извне нет');
        $this->assertSame([], $command->getDefinition()->getOptions());
    }

    private function command(
        SyncRunner $runner,
        Settings $settings,
        ?LockHelper $lock = null,
        ?BackgroundRun $background = null
    ): RunCommand {
        if ($lock === null) {
            $lock = $this->createMock(LockHelper::class);
            $lock->method('acquire')->willReturn(true);
        }
        $command = $this->getMockBuilder(RunCommand::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['startEnabledModules', 'settings', 'syncRunner', 'logger', 'lockHelper', 'backgroundRun'])
            ->getMock();
        $command->method('lockHelper')->willReturn($lock);
        $command->method('backgroundRun')->willReturn($background ?? $this->createMock(BackgroundRun::class));
        $command->expects($this->once())->method('startEnabledModules');
        $command->method('syncRunner')->willReturn($runner);
        $command->method('settings')->willReturn($settings);
        $command->method('logger')->willReturn(null);

        return $command;
    }

    /** @return array{0:int,1:string} */
    private function execute(Command $command): array
    {
        $input = new ArrayInput([], new InputDefinition([]));
        $output = new BufferedOutput();
        foreach (['input' => $input, 'output' => $output] as $property => $value) {
            $reflection = new \ReflectionProperty(Command::class, $property);
            $reflection->setAccessible(true);
            $reflection->setValue($command, $value);
        }
        $handle = new \ReflectionMethod($command, 'handle');
        $handle->setAccessible(true);

        return [(int) $handle->invoke($command), $output->fetch()];
    }
}
