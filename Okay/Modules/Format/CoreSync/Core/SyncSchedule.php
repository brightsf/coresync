<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Core\Scheduler\Schedule;

/**
 * Задача крона CoreSync с замком планировщика, уникальным для витрины.
 *
 * Ядро (`Okay\Core\Scheduler\Scheduler`) держит замки задач в FlockStore на sys_get_temp_dir() и
 * называет их по форме команды (`Schedule::buildTask`): массив — md5(serialize($command)), замыкание —
 * md5(serialize([исходник, статические переменные])). У команды `[SyncRunner::class, 'run']` имя замка
 * совпадало у всех витрин на хосте, и пока тик одной витрины держал замок, `Task::isDue()` другой
 * видел его занятым и молча пропускал тик. Ядро модулем не правим, поэтому команда — замыкание, а
 * ключ инстанса лежит в его статической переменной (`use`): ядро само включает её в имя замка.
 *
 * Ключ — реальный путь корня сайта. Замок локален хосту (flock в tmp хоста), а два сайта одного хоста
 * не делят корень, поэтому путь уникален там, где это нужно; он же не меняется между тиками и между
 * релизами модуля (самообновление меняет каталог модуля внутри того же корня). realpath() сводит
 * хвостовой разделитель и симлинки к одному значению.
 *
 * Внутренний замок прогона ({@see LockHelper}) живёт в {root_dir}/files/coresync/locks и уже
 * per-install; эта задача чинит только пропуск тика на уровне планировщика.
 */
final class SyncSchedule
{
    const NAME = 'CoreSync snapshot pull';
    const TIME = '*/30 * * * *';
    const TIMEOUT = 3600;

    /** @param string $time расписание; параметр для тестов, витрина берёт {@see self::TIME} */
    public static function build(string $rootDir, string $time = self::TIME): Schedule
    {
        $instance = self::instanceKey($rootDir);

        // $instance в теле не нужен: он нужен ядру, которое берёт статические переменные замыкания в имя
        // замка. SyncRunner ядро резолвит по типу параметра (MethodDI), как раньше для массива.
        // runScheduled(): в режиме run_trigger=core тик ничего не делает, в режиме cron это run().
        $command = static function (SyncRunner $runner) use ($instance): string {
            return $runner->runScheduled();
        };

        return (new Schedule($command))
            ->name(self::NAME)
            ->time($time)
            ->overlap(false)
            ->timeout(self::TIMEOUT);
    }

    public static function instanceKey(string $rootDir): string
    {
        $real = realpath($rootDir);
        $path = rtrim(str_replace('\\', '/', $real !== false ? $real : $rootDir), '/');

        return $path === '' ? '/' : $path;
    }
}
