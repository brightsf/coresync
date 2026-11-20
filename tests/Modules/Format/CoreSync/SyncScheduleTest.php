<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Scheduler\Scheduler;
use Okay\Core\Scheduler\Task;
use Okay\Modules\Format\CoreSync\Core\SyncRunner;
use Okay\Modules\Format\CoreSync\Core\SyncSchedule;
use Okay\Modules\Format\CoreSync\Init\Init;
use Tests\Modules\Format\CoreSync\Support\RecordingLockFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;

// MODULE_TYPE_* (свойство AbstractInit) живут в Okay/Core/config/constants.php; bootstrap их не грузит.
require_once dirname(__DIR__, 4) . '/Okay/Core/config/constants.php';

/**
 * Замок планировщика ядра у задачи CoreSync уникален для витрины.
 *
 * Ядро (`Okay\Core\Scheduler\Scheduler`) кладёт замки задач в общий sys_get_temp_dir() и называет их
 * по форме команды. Пока команда была `[SyncRunner::class, 'run']`, имя замка совпадало у всех витрин
 * на хосте: тик одной витрины делал `Task::isDue()` другой ложным, и её тик молча пропускался
 * (t-ind.ru и sivik-online.ru под одним пользователем). Команда теперь замыкание с ключом инстанса в
 * статической переменной, а ядро включает статические переменные замыкания в имя замка.
 *
 * KILL-ПРОБА: вернуть общий ключ (SyncSchedule::instanceKey() отдаёт константу) → красные
 * testDifferentRootsGetDifferentLockNames и testLockHeldByOneStorefrontDoesNotBlockAnother.
 */
class SyncScheduleTest extends TestCase
{
    /** @var string[] */
    private $dirs = [];

    /** @var LockInterface[] */
    private $held = [];

    protected function tearDown(): void
    {
        foreach ($this->held as $lock) {
            if ($lock->isAcquired()) {
                $lock->release();
            }
        }
        $this->held = [];
        foreach ($this->dirs as $dir) {
            $this->rrmdir($dir);
        }
        $this->dirs = [];
        parent::tearDown();
    }

    public function testSameRootGetsSameLockNameAcrossBuilds(): void
    {
        $factory = $this->factory();
        $root = $this->makeDir('root');

        SyncSchedule::build($root)->buildTask($factory);
        // Корень ядра приходит с хвостовым разделителем (Okay/Core/Config.php) — это та же витрина.
        SyncSchedule::build($root . '/')->buildTask($factory);

        $this->assertCount(2, $factory->resources);
        $this->assertSame($factory->resources[0], $factory->resources[1], 'тик за тиком одна витрина — одно имя замка');
    }

    public function testSymlinkedRootResolvesToTheSameLockName(): void
    {
        $factory = $this->factory();
        $root = $this->makeDir('root');
        $link = $this->makeDir('links') . '/site';
        if (!@symlink($root, $link)) {
            $this->markTestSkipped('symlink недоступен в этой ФС');
        }

        SyncSchedule::build($root)->buildTask($factory);
        SyncSchedule::build($link)->buildTask($factory);

        $this->assertSame($factory->resources[0], $factory->resources[1], 'ключ из реального пути корня');
    }

    public function testDifferentRootsGetDifferentLockNames(): void
    {
        $factory = $this->factory();

        SyncSchedule::build($this->makeDir('tind'))->buildTask($factory);
        SyncSchedule::build($this->makeDir('sivik'))->buildTask($factory);

        $this->assertCount(2, $factory->resources);
        $this->assertNotSame($factory->resources[0], $factory->resources[1], 'две витрины на хосте не делят замок');
    }

    public function testLockHeldByOneStorefrontDoesNotBlockAnother(): void
    {
        $factory = $this->factory();
        $rootA = $this->makeDir('tind');
        $rootB = $this->makeDir('sivik');

        // Тик витрины A идёт: её замок держит отдельный экземпляр (как отдельный процесс scheduler:task).
        $runningA = SyncSchedule::build($rootA, '* * * * *')->buildTask($factory);
        $this->hold($factory->locks[0]);

        $tickB = SyncSchedule::build($rootB, '* * * * *')->buildTask($factory);
        $tickA = SyncSchedule::build($rootA, '* * * * *')->buildTask($factory);

        $this->assertInstanceOf(Task::class, $runningA);
        $this->assertTrue($tickB->isDue(), 'чужой идущий тик не делает задачу витрины B недопустимой');
        $this->assertFalse($tickA->isDue(), 'overlap(false): второй тик той же витрины не накладывается');
    }

    public function testFreeLockLeavesTaskDue(): void
    {
        $factory = $this->factory();

        $task = SyncSchedule::build($this->makeDir('root'), '* * * * *')->buildTask($factory);

        $this->assertTrue($task->isDue(), 'без идущего тика задача допустима, isDue сам замок не оставляет');
        $this->assertTrue($task->isDue(), 'повторная проверка тоже допустима: isDue отпускает замок');
    }

    public function testTaskKeepsNameScheduleTimeoutAndDelegatesToSyncRunner(): void
    {
        $task = SyncSchedule::build($this->makeDir('root'))->buildTask($this->factory());

        $this->assertSame('CoreSync snapshot pull', $task->getName());
        $this->assertSame('*/30 * * * *', $task->getSchedule()->getExpression());
        $this->assertSame(3600, $task->getTimeout());

        $command = $task->getCommand();
        $this->assertInstanceOf(\Closure::class, $command);
        // Ядро резолвит аргументы замыкания по типу (MethodDI): SyncRunner приходит из сервис-локатора,
        // как раньше для [SyncRunner::class, 'run'].
        $params = (new \ReflectionFunction($command))->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame(SyncRunner::class, $params[0]->getType()->getName());

        $runner = $this->createMock(SyncRunner::class);
        $runner->expects($this->once())->method('run')->willReturn(SyncRunner::OUTCOME_DISABLED);
        $this->assertSame(SyncRunner::OUTCOME_DISABLED, $command($runner));
    }

    public function testOverlapStaysForbidden(): void
    {
        $schedule = SyncSchedule::build($this->makeDir('root'));
        $overlap = new \ReflectionProperty($schedule, 'overlap');
        $overlap->setAccessible(true);

        $this->assertFalse($overlap->getValue($schedule));
    }

    /**
     * Подключение в Init: init() регистрирует задачу только через registerSyncSchedule(root_dir), а он
     * кладёт в планировщик ядра замыкание с ключом инстанса. KILL-ПРОБА: вернуть в init() регистрацию
     * `new Schedule([SyncRunner::class, 'run'])` → красный.
     */
    public function testInitRegistersPerInstanceScheduleFromRootDir(): void
    {
        $scheduler = new Scheduler($this->makeDir('log'));
        // Init без ServiceLocator (та же форма, что в InstallTableGuardTest): регистрация уходит в
        // настоящий планировщик ядра, который сам строит Task и имя замка.
        $init = new class($scheduler) extends Init {
            /** @var Scheduler */
            private $testScheduler;

            public function __construct(Scheduler $scheduler)
            {
                $this->testScheduler = $scheduler;
            }

            public function registerSchedule(\Okay\Core\Scheduler\Schedule $schedule): void
            {
                $this->testScheduler->registerSchedule($schedule);
            }

            public function registerSyncScheduleForTest(string $rootDir): void
            {
                $this->registerSyncSchedule($rootDir);
            }
        };
        $root = $this->makeDir('root');

        $init->registerSyncScheduleForTest($root . '/');

        $tasks = $scheduler->getTasks();
        $this->assertCount(1, $tasks);
        /** @var Task $task */
        $task = reset($tasks);
        $this->assertSame(SyncSchedule::NAME, $task->getName());
        $command = $task->getCommand();
        $this->assertInstanceOf(\Closure::class, $command, 'команда не массив: имя замка массива общее для всех витрин');
        $this->assertSame(
            ['instance' => SyncSchedule::instanceKey($root)],
            (new \ReflectionFunction($command))->getStaticVariables()
        );

        $initMethod = new \ReflectionMethod(Init::class, 'init');
        $lines = file((string) $initMethod->getFileName());
        $body = implode('', array_slice(
            $lines,
            $initMethod->getStartLine() - 1,
            $initMethod->getEndLine() - $initMethod->getStartLine() + 1
        ));
        $this->assertSame(1, substr_count($body, '$this->registerSyncSchedule('), 'init() регистрирует задачу CoreSync');
        $this->assertStringContainsString("get('root_dir')", $body, 'ключ инстанса из корня сайта');
        $this->assertStringNotContainsString('registerSchedule(', $body, 'других регистраций задач в init() нет');
        $this->assertStringNotContainsString('new Schedule(', $body);
    }

    private function factory(): RecordingLockFactory
    {
        return new RecordingLockFactory(new FlockStore($this->makeDir('flock')));
    }

    private function hold(LockInterface $lock): void
    {
        $this->assertTrue($lock->acquire(), 'замок свободен до начала теста');
        $this->held[] = $lock;
    }

    private function makeDir(string $label): string
    {
        $dir = sys_get_temp_dir() . '/coresync_sched_' . $label . '_' . uniqid('', true);
        mkdir($dir, 0775, true);
        $this->dirs[] = $dir;

        return $dir;
    }

    private function rrmdir(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            unlink($dir);

            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->rrmdir($dir . '/' . $entry);
            }
        }
        rmdir($dir);
    }
}
