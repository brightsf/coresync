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
}
