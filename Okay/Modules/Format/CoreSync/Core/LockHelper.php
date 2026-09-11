<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Core\Config;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Flock-lock прогона ПОВЕРХ scheduler-overlap: защищает AJAX-путь «Запустить сейчас»,
 * который scheduler'ом не охраняется. Второй запуск при живом lock → false (не ошибка).
 *
 * Каталог замка — {root_dir}/files/coresync/locks, НЕ sys_get_temp_dir(). Причина: PHP витрины
 * исполняется потомком apache с PrivateTmp=yes, а cron — обычным php с настоящим /tmp; на общем
 * системном tmp у HTTP- и CLI-трасс оказывались РАЗНЫЕ файлы одного замка, взаимного исключения
 * между ними не было по построению (D-CORESYNC-LOCK-PRIVATE-TMP-NOT-SHARED: два одновременных
 * apply на витрине заказчика). root_dir считается из __DIR__ (Okay/Core/Config.php:137) и одинаков
 * у обеих трасс; попутно замок становится per-install — несколько витрин на одном хосте больше не
 * блокируют друг друга общим именем ресурса.
 */
class LockHelper
{
    const RESOURCE = 'format_coresync_sync_runner';

    /**
     * Причины отказа {@see acquire()}. Снаружи оба исхода — один и тот же false, и знание о том,
     * какой именно, есть ТОЛЬКО здесь: вызывающая сторона вывести его не может, а второй гейт у неё
     * стал бы вторым источником истины и разъехался бы с этим при следующей правке.
     */
    const FAILURE_BUSY = 'busy';
    const FAILURE_UNAVAILABLE = 'unavailable';

    /** @var Config */
    private $config;

    /** @var LoggerInterface|null */
    private $logger;

    /** @var LockInterface|null Строится ЛЕНИВО: конструктор не трогает ФС (см. acquire()). */
    private $lock;

    /** @var string|null Причина ПОСЛЕДНЕГО acquire(): одна из FAILURE_*, null — отказа не было. */
    private $lastFailure;

    public function __construct(Config $config, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Fail-closed: недоступный каталог замка не пускает прогон (false), но и не выбрасывает наружу —
     * LockHelper инстанцируется DI при открытии админ-панели, а FlockStore::__construct бросает на
     * непригодном каталоге, и бросок отсюда превратил бы деградацию ФС в 500 у оператора.
     */
    public function acquire(): bool
    {
        // Сброс на КАЖДОМ вызове, включая успешный: липкая причина прошлого отказа — тот же класс
        // склейки исходов, только отложенный во времени (ручка отвечала бы отказом по следу
        // предыдущего вызова). Причина рождается ровно там же, где false, — ниже, в двух местах.
        $this->lastFailure = null;

        try {
            if ($this->lock()->acquire()) {
                return true;
            }

            $this->lastFailure = self::FAILURE_BUSY;

            return false;
        } catch (\Throwable $e) {
            // Исход отличается от «замок занят другим прогоном» (тот описывает вызывающая сторона
            // своей info-строкой): оператор витрины читает только лог, и неразличимые исходы
            // означали бы вечный тихий пропуск тиков.
            $this->lastFailure = self::FAILURE_UNAVAILABLE;
            $this->logUnavailable($e);

            return false;
        }
    }

    /**
     * Причина отказа ПОСЛЕДНЕГО acquire(): FAILURE_BUSY — замок держит другой прогон (штатно),
     * FAILURE_UNAVAILABLE — каталог/файл замка недоступен (деградация ФС, подробности в логе),
     * null — отказа не было. Аксессор аддитивен: тип возврата acquire() остаётся bool, поэтому
     * существующие вызовы и моки не правятся.
     */
    public function lastFailure(): ?string
    {
        return $this->lastFailure;
    }

    public function release(): void
    {
        if ($this->lock !== null && $this->lock->isAcquired()) {
            $this->lock->release();
        }
    }

    /** Каталог замка внутри установки; сосед staging/updates под тем же files/coresync. */
    private function lockDir(): string
    {
        $root = rtrim((string) $this->config->get('root_dir'), '/\\');

        return $root . '/files/coresync/locks';
    }

    /** @throws \RuntimeException каталог замка не создать */
    private function lock(): LockInterface
    {
        if ($this->lock === null) {
            $dir = $this->lockDir();
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('не удалось создать каталог замка');
            }

            $this->lock = (new LockFactory(new FlockStore($dir)))->createLock(self::RESOURCE, 3600);
        }

        return $this->lock;
    }

    private function logUnavailable(\Throwable $e): void
    {
        if ($this->logger !== null) {
            $this->logger->error(
                'CoreSync: каталог замка недоступен, прогон не запускается — '
                . $this->lockDir() . ': ' . $e->getMessage()
            );
        }
    }
}
