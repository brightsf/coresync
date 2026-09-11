<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Config;
use Okay\Modules\Format\CoreSync\Core\LockHelper;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Замок прогона живёт в каталоге установки ({root_dir}/files/coresync/locks), а не в системном tmp.
 *
 * Почему это замок, а не косметика: PHP витрины исполняется потомком apache с PrivateTmp=yes, cron —
 * обычным php с настоящим /tmp, поэтому на общем sys_get_temp_dir() HTTP- и CLI-прогоны видели РАЗНЫЕ
 * файлы одного замка и шли одновременно (D-CORESYNC-LOCK-PRIVATE-TMP-NOT-SHARED). root_dir считается
 * из __DIR__ и у обеих трасс одинаков, а заодно делает замок per-install: несколько витрин на одном
 * хосте больше не блокируют друг друга.
 */
class LockHelperTest extends TestCase
{
    /** @var string[] */
    private $roots = [];

    /** @var LockHelper[] */
    private $held = [];

    protected function tearDown(): void
    {
        foreach ($this->held as $helper) {
            $helper->release();
        }
        $this->held = [];
        foreach ($this->roots as $root) {
            $this->rrmdir($root);
        }
        $this->roots = [];
        parent::tearDown();
    }

    /** Одноразовый корень установки; путь замка выводится из него, а не из окружения процесса. */
    private function makeRoot(): string
    {
        $root = sys_get_temp_dir() . '/coresync_lock_' . uniqid('', true);
        mkdir($root, 0775, true);
        $this->roots[] = $root;

        return $root;
    }

    private function configFor(string $root): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(static function (string $key) use ($root) {
            // У root_dir ядра есть хвостовой разделитель (Okay/Core/Config.php:137) — воспроизводим.
            return $key === 'root_dir' ? $root . '/' : null;
        });

        return $config;
    }

    private function helper(string $root, ?AbstractLogger $logger = null): LockHelper
    {
        $helper = new LockHelper($this->configFor($root), $logger);
        $this->held[] = $helper;

        return $helper;
    }

    private function locksDir(string $root): string
    {
        return $root . '/files/coresync/locks';
    }

    /** @return string[] файлы замка ЭТОГО ресурса в каталоге (маска вендора FlockStore) */
    private function lockFiles(string $dir): array
    {
        $found = glob($dir . '/sf.' . LockHelper::RESOURCE . '.*.lock');

        return $found === false ? [] : $found;
    }

    private function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var array<int, array{level: string, message: string}> */
            public $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        };
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rrmdir($path . '/' . $entry);
        }
        @rmdir($path);
    }

    // ------------------------------------------------------------------
    // A. Путь замка выведен из каталога установки
    // ------------------------------------------------------------------

    /**
     * A1. Файл замка ложится ровно в {root_dir}/files/coresync/locks, и в системном tmp по маске
     * замка не появляется НИ ОДНОГО нового файла (сверка полного списка до/после, а не «файл где-то
     * есть»: sys_get_temp_dir() кэшируется процессом и переназначению через putenv не поддаётся).
     */
    public function testAcquirePutsLockFileUnderInstallRootAndNotInSystemTmp(): void
    {
        $root = $this->makeRoot();
        $systemTmpBefore = $this->lockFiles(sys_get_temp_dir());

        $helper = $this->helper($root);
        $this->assertTrue($helper->acquire(), 'свежий корень → замок берётся');

        $this->assertCount(
            1,
            $this->lockFiles($this->locksDir($root)),
            'ровно один файл замка и он в {root_dir}/files/coresync/locks'
        );
        $this->assertSame(
            $systemTmpBefore,
            $this->lockFiles(sys_get_temp_dir()),
            'в системном tmp по маске замка не появилось ни одного нового файла'
        );
    }

    /**
     * A2. Идентичность замка несёт ПУТЬ, а не имя ресурса: два корня → два каталога, и оба захвата
     * проходят. Это одновременно замок против возврата к общему sys_get_temp_dir() (там второй
     * захват отказал бы) и доказательство per-install изоляции нескольких витрин на одном хосте.
     */
    public function testTwoInstallRootsDoNotBlockEachOther(): void
    {
        $rootA = $this->makeRoot();
        $rootB = $this->makeRoot();

        $first = $this->helper($rootA);
        $second = $this->helper($rootB);

        $this->assertTrue($first->acquire(), 'первая установка берёт свой замок');
        $this->assertTrue($second->acquire(), 'вторая установка не заблокирована первой — путь свой');

        $this->assertCount(1, $this->lockFiles($this->locksDir($rootA)));
        $this->assertCount(1, $this->lockFiles($this->locksDir($rootB)));
        $this->assertNotSame($this->locksDir($rootA), $this->locksDir($rootB));
    }

    /**
     * A3. Взаимное исключение внутри ОДНОГО корня сохранено (регресс-замок: обязан быть зелёным и до
     * правки). Второй процесс не нужен: на целевом рантайме PHP 7.4 два независимых fopen одного
     * файла в одном процессе дают flock(LOCK_EX|LOCK_NB) true/false.
     */
    public function testSecondHelperOverTheSameRootIsRefusedUntilRelease(): void
    {
        $root = $this->makeRoot();
        $first = $this->helper($root);
        $second = $this->helper($root);

        $this->assertTrue($first->acquire(), 'первый прогон захватывает замок');
        $this->assertFalse($second->acquire(), 'второй прогон над тем же корнем отказан');

        $first->release();
        $this->assertTrue($second->acquire(), 'после release замок свободен для следующего прогона');
    }

    /**
     * A4. Каталог замка создаёт САМ модуль своим идемпотентным примитивом (0775, как
     * SnapshotDownloader.php:43), а не вендорный fallback FlockStore::__construct (там mkdir 0777).
     * Под нулевым umask эти два создателя различимы по режиму — иначе проба «убрать mkdir» была бы
     * семантическим no-op. Повторный acquire каталог не пересоздаёт (сверка inode).
     */
    public function testLocksDirectoryIsCreatedByTheModuleAndReusedOnRepeatedAcquire(): void
    {
        $root = $this->makeRoot();
        $dir = $this->locksDir($root);
        $this->assertDirectoryDoesNotExist($dir, 'каталога замка ещё нет');

        $previousUmask = umask(0);
        try {
            $first = $this->helper($root);
            $this->assertTrue($first->acquire());

            clearstatcache();
            $this->assertDirectoryExists($dir, 'каталог замка создан');
            $this->assertSame(
                '0775',
                substr(sprintf('%o', fileperms($dir)), -4),
                'каталог создан примитивом модуля (0775), а не вендорным mkdir(0777)'
            );
            $inode = fileinode($dir);
            $first->release();

            $second = $this->helper($root);
            $this->assertTrue($second->acquire(), 'повторный acquire в существующем каталоге не падает');
            clearstatcache();
            $this->assertSame($inode, fileinode($dir), 'существующий каталог не пересоздан');
        } finally {
            umask($previousUmask);
        }
    }

    // ------------------------------------------------------------------
    // B. Fail-closed на деградации ФС + различимые в логе исходы
    // ------------------------------------------------------------------

    /**
     * B1. Конструктор не трогает ФС ни одним обращением: LockHelper инстанцируется DI при открытии
     * админ-панели (CoreSyncAdmin::previewGalleryAdoption/applyGalleryAdoption), а FlockStore
     * бросает на непригодном каталоге — бросок или mkdir из конструктора превратил бы деградацию ФС
     * в 500 на странице оператора.
     */
    public function testConstructorTouchesNothingOnTheFilesystem(): void
    {
        $root = $this->makeRoot();
        $absent = $root . '/absent-install';

        new LockHelper($this->configFor($absent), $this->recordingLogger());

        clearstatcache();
        $this->assertDirectoryDoesNotExist($absent, 'конструктор не создаёт корень установки');
        $this->assertDirectoryDoesNotExist($this->locksDir($absent), 'конструктор не создаёт каталог замка');
    }

    /**
     * B2. Каталог замка создать нельзя → acquire() отдаёт false, исключение наружу НЕ выходит, в лог
     * ушла error-строка с полным путём. Механизм недоступности — обычный ФАЙЛ на месте каталога
     * locks: сьют исполняется от root (замерено, uid=0), поэтому chmod 0555 барьером записи не
     * является и кейс на нём был бы тавтологией.
     */
    public function testUnavailableLockDirectoryFailsClosedWithoutThrowing(): void
    {
        $root = $this->makeRoot();
        mkdir($root . '/files/coresync', 0775, true);
        file_put_contents($this->locksDir($root), 'файл на месте каталога замка');

        $logger = $this->recordingLogger();
        $helper = $this->helper($root, $logger);

        $this->assertFalse($helper->acquire(), 'каталог замка недоступен → прогон не идёт (fail-closed)');

        $this->assertCount(1, $logger->records, 'ровно одна запись об исходе');
        $this->assertSame('error', $logger->records[0]['level']);
        $this->assertStringContainsString('недоступен', $logger->records[0]['message']);
        $this->assertStringContainsString($this->locksDir($root), $logger->records[0]['message']);

        $helper->release(); // release при незахваченном замке безопасен и ничего не удаляет
        $this->assertFileExists($this->locksDir($root), 'release не трогает путь замка');
    }

    /**
     * B2'. Вторая конфигурация отказа: каталог замка исправен, а САМ ЗАХВАТ бросает — на месте файла
     * замка битый симлинк, и все три вендорных fopen ('r+', 'x', 'r') обречены (замерено на целевом
     * рантайме; каталог на месте файла НЕ подходит — fopen каталога под root проходит и flock на нём
     * возвращает true). Тот же исход: false + error-строка, наружу ничего не летит.
     */
    public function testThrowingAcquireIsAlsoFailClosed(): void
    {
        $root = $this->makeRoot();
        $warmup = $this->helper($root);
        $this->assertTrue($warmup->acquire());
        $warmup->release();

        $files = $this->lockFiles($this->locksDir($root));
        $this->assertCount(1, $files);
        unlink($files[0]);
        symlink($this->locksDir($root) . '/nowhere/target', $files[0]); // битый симлинк на месте файла замка

        $logger = $this->recordingLogger();
        $blocked = $this->helper($root, $logger);

        $this->assertFalse($blocked->acquire(), 'захват бросил → false, а не исключение наружу');
        $this->assertCount(1, $logger->records);
        $this->assertSame('error', $logger->records[0]['level']);
        $this->assertStringContainsString($this->locksDir($root), $logger->records[0]['message']);
    }

    /**
     * B4. Два исхода false различимы ПО СОДЕРЖИМОМУ лога: «занято другим прогоном» LockHelper не
     * комментирует вовсе (этот исход описывает вызывающая сторона своей info-строкой, см.
     * SyncRunnerTest::testBusyLockStopsTheTickBeforeSchemaUpgradeAndDoRun), «каталог недоступен» —
     * новая error-строка с маркером и путём. Оператор витрины читает только лог: слипшиеся исходы
     * означали бы вечный тихий пропуск тиков.
     */
    public function testBusyAndUnavailableOutcomesAreDistinguishableInTheLog(): void
    {
        $busyRoot = $this->makeRoot();
        $holder = $this->helper($busyRoot);
        $this->assertTrue($holder->acquire());

        $busyLogger = $this->recordingLogger();
        $busy = $this->helper($busyRoot, $busyLogger);
        $this->assertFalse($busy->acquire(), 'замок занят другим прогоном');
        $this->assertSame([], $busyLogger->records, 'занятый замок не печатает строку недоступности');

        $brokenRoot = $this->makeRoot();
        mkdir($brokenRoot . '/files/coresync', 0775, true);
        file_put_contents($this->locksDir($brokenRoot), 'файл на месте каталога замка');

        $brokenLogger = $this->recordingLogger();
        $broken = $this->helper($brokenRoot, $brokenLogger);
        $this->assertFalse($broken->acquire(), 'каталог замка недоступен');
        $this->assertCount(1, $brokenLogger->records, 'недоступность описана ровно одной записью');
        $this->assertSame('error', $brokenLogger->records[0]['level']);
        $this->assertStringContainsString('недоступен', $brokenLogger->records[0]['message']);
        $this->assertStringContainsString($this->locksDir($brokenRoot), $brokenLogger->records[0]['message']);
    }

    /**
     * B5. Логгер — опциональная зависимость того же вида, что уже принята в зоне (PingController
     * принимает ?LoggerInterface $logger = null): без него деградация ФС всё равно даёт тихий false,
     * а не фатал по обращению к null.
     */
    public function testFailClosedWorksWithoutLogger(): void
    {
        $root = $this->makeRoot();
        mkdir($root . '/files/coresync', 0775, true);
        file_put_contents($this->locksDir($root), 'файл на месте каталога замка');

        $helper = new LockHelper($this->configFor($root));

        $this->assertFalse($helper->acquire(), 'без логгера исход тот же — прогон не идёт');
        $helper->release();
    }

    // ------------------------------------------------------------------
    // C. Причину отказа читает ВЫЗЫВАЮЩАЯ сторона (этап coresync-admin-lock-outcomes, кейсы A1-A5)
    //
    // Различимость в логе (B4) закрыла оператора, который лог читает. Оператор витрины читает
    // ответ РУЧКИ: снаружи оба исхода — один и тот же false, и три ручки админки отвечали на них
    // одинаково («Другой прогон CoreSync уже держит общий замок», а кнопка «Запустить сейчас» —
    // вообще success:true). Знание о причине есть только внутри замка, поэтому и живёт оно здесь,
    // а не выводится вызывающей стороной вторым гейтом (тот стал бы вторым источником истины).
    // ------------------------------------------------------------------

    /**
     * A1. Замок занят другим прогоном → false, и причина названа «занято». Заодно замок против
     * вырожденной пары: две константы обязаны различаться, иначе весь чанк зелен по построению.
     */
    public function testBusyLockNamesBusyAsTheReasonOfRefusal(): void
    {
        $this->assertNotSame(
            LockHelper::FAILURE_BUSY,
            LockHelper::FAILURE_UNAVAILABLE,
            'две причины отказа — два разных значения, иначе различать нечего'
        );

        $root = $this->makeRoot();
        $holder = $this->helper($root);
        $this->assertTrue($holder->acquire(), 'первый прогон держит замок');

        $blocked = $this->helper($root);
        $this->assertFalse($blocked->acquire(), 'второй прогон над тем же корнем отказан');
        $this->assertSame(
            LockHelper::FAILURE_BUSY,
            $blocked->lastFailure(),
            'штатное «занято другим прогоном», а не деградация ФС'
        );
    }

    /**
     * A2. Каталог замка недоступен (обычный ФАЙЛ на месте каталога locks — механизм, принятый в
     * этом файле: сьют идёт от root, где chmod 0555 барьером не является) → false, причина
     * «недоступен», а error-строка в логе прежняя: новая наблюдаемость не заменяет старую.
     */
    public function testUnavailableLockDirectoryNamesUnavailableAsTheReasonOfRefusal(): void
    {
        $root = $this->makeRoot();
        mkdir($root . '/files/coresync', 0775, true);
        file_put_contents($this->locksDir($root), 'файл на месте каталога замка');

        $logger = $this->recordingLogger();
        $helper = $this->helper($root, $logger);

        $this->assertFalse($helper->acquire(), 'недоступный каталог замка → прогон не идёт');
        $this->assertSame(
            LockHelper::FAILURE_UNAVAILABLE,
            $helper->lastFailure(),
            'деградация ФС, а не чужой прогон'
        );

        $this->assertCount(1, $logger->records, 'error-строка недоступности осталась на месте');
        $this->assertSame('error', $logger->records[0]['level']);
        $this->assertStringContainsString('недоступен', $logger->records[0]['message']);
        $this->assertStringContainsString($this->locksDir($root), $logger->records[0]['message']);
    }

    /**
     * A3. Вторая конфигурация недоступности: каталог исправен, а сам захват бросает (битый симлинк
     * на месте файла замка — механизм, принятый в B2'). Причина та же «недоступен»: вызывающей
     * стороне важно не КАК сломалась ФС, а что чужого прогона ждать бессмысленно.
     */
    public function testThrowingAcquireAlsoNamesUnavailableAsTheReasonOfRefusal(): void
    {
        $root = $this->makeRoot();
        $warmup = $this->helper($root);
        $this->assertTrue($warmup->acquire());
        $warmup->release();

        $files = $this->lockFiles($this->locksDir($root));
        $this->assertCount(1, $files);
        unlink($files[0]);
        symlink($this->locksDir($root) . '/nowhere/target', $files[0]);

        $blocked = $this->helper($root, $this->recordingLogger());

        $this->assertFalse($blocked->acquire(), 'захват бросил → false');
        $this->assertSame(LockHelper::FAILURE_UNAVAILABLE, $blocked->lastFailure());
    }

    /**
     * A4. Успех причины не имеет: до первого вызова, после успешного захвата и после повторного
     * успешного захвата (через release) аксессор отдаёт null. Иначе вызывающая сторона отвечала бы
     * отказом на состоявшемся прогоне.
     */
    public function testSuccessfulAcquireHasNoFailureReason(): void
    {
        $root = $this->makeRoot();
        $helper = $this->helper($root);

        $this->assertNull($helper->lastFailure(), 'до первого вызова причины нет');

        $this->assertTrue($helper->acquire());
        $this->assertNull($helper->lastFailure(), 'успешный захват причины не оставляет');

        $helper->release();
        $this->assertTrue($helper->acquire(), 'повторный захват после release проходит');
        $this->assertNull($helper->lastFailure(), 'и он тоже без причины');
    }

    /**
     * A5. Причина НЕ липнет: тот же экземпляр, получивший отказ «занято», после освобождения замка
     * захватывает его и обнуляет причину. Липкое значение прошлого отказа — тот же класс склейки,
     * только отложенный во времени: ручка отвечала бы отказом по следу предыдущего вызова.
     */
    public function testFailureReasonIsClearedByTheNextSuccessfulAcquire(): void
    {
        $root = $this->makeRoot();
        $holder = $this->helper($root);
        $this->assertTrue($holder->acquire());

        $second = $this->helper($root);
        $this->assertFalse($second->acquire());
        $this->assertSame(LockHelper::FAILURE_BUSY, $second->lastFailure(), 'отказ «занято» назван');

        $holder->release();

        $this->assertTrue($second->acquire(), 'замок освободился — тот же экземпляр его берёт');
        $this->assertNull($second->lastFailure(), 'успех обнуляет причину прошлого отказа');
    }
}
