<?php

namespace Okay\Modules\Format\CoreSync\Core;

/**
 * Чекпоинт-хранилище состояний файлов набора (resume/докачка).
 * Прод-реализация — поверх таблицы __format__coresync_job_files (JobFilesCheckpointStore);
 * тесты подставляют in-memory реализацию.
 */
interface FileCheckpointStore
{
    /** @return string|null один из Contract::FILE_*; null — файл ещё не зарегистрирован */
    public function getStatus(string $name): ?string;

    public function setStatus(string $name, string $status): void;
}
