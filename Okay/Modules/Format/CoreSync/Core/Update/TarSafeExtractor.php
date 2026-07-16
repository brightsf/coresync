<?php

namespace Okay\Modules\Format\CoreSync\Core\Update;

/**
 * Безопасная распаковка релизного .tar.gz модуля [SECURITY-SENSITIVE].
 *
 * Контракт артефакта (tools/build-artifact.sh): ustar-tar, gzip, единственный корень `CoreSync/`,
 * только каталоги и обычные файлы (права нормализованы). Мы САМИ разбираем ustar, а не зовём PharData —
 * ради полного контроля над threat-model §4 (path traversal): каждая запись валидируется вручную.
 *
 * Отвергается (throw UpdateException) ВСЁ, что не укладывается в контракт:
 *  - запись вне корня `CoreSync/` (в т.ч. абсолютный путь, ведущий `/`);
 *  - любой `..`-сегмент в пути (обход staging → запись поверх чужих файлов);
 *  - симлинк/хардлинк/устройство/FIFO (typeflag ≠ файл/каталог) — вектор ре-таргета swap'а;
 *  - битый заголовок (checksum) / обрезанный поток.
 *
 * Анти-zip-bomb: поток декомпрессируется чанками с жёстким капом суммарного объёма (маленький gz не
 * смеет развернуться в гигабайты и выесть память ДО валидации).
 */
class TarSafeExtractor
{
    /** Единственный допустимый корень внутри архива (контракт build-artifact.sh). */
    public const ROOT = 'CoreSync';

    /** Размер блока ustar. */
    private const BLOCK = 512;

    /** Кап суммарного декомпрессированного объёма (модуль — десятки КБ; кап с большим запасом). */
    private const MAX_DECOMPRESSED_BYTES = 67108864; // 64 MiB

    /**
     * Распаковать $tarGzPath в $destDir. Возвращает путь к извлечённому корню модуля
     * ($destDir/CoreSync), готовому к swap'у.
     *
     * @throws UpdateException распаковка невозможна/небезопасна
     */
    public function extract(string $tarGzPath, string $destDir): string
    {
        if (!is_file($tarGzPath)) {
            throw new UpdateException('Обновление: артефакт для распаковки не найден: ' . $tarGzPath);
        }

        $tar = $this->gunzipCapped($tarGzPath);

        if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            throw new UpdateException('Обновление: не удалось создать каталог распаковки: ' . $destDir);
        }
        $destReal = rtrim($destDir, '/\\');

        $length = strlen($tar);
        $offset = 0;
        $sawRoot = false;

        while ($offset + self::BLOCK <= $length) {
            $header = substr($tar, $offset, self::BLOCK);
            $offset += self::BLOCK;

            // Финальный маркер архива — полностью нулевой блок.
            if (trim($header, "\0") === '') {
                break;
            }

            $entry = $this->parseHeader($header);
            $name = $entry['name'];
            $size = $entry['size'];
            $type = $entry['type'];

            $relative = $this->safeRelativePath($name); // валидирует корень/traversal, возвращает путь под CoreSync/
            $sawRoot = true;

            if ($type === '5') {
                // Каталог: тела нет.
                $dir = $destReal . '/' . self::ROOT . ($relative === '' ? '' : '/' . $relative);
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new UpdateException('Обновление: не удалось создать каталог из архива: ' . $dir);
                }
                continue;
            }

            // Обычный файл ('0' или "\0").
            $dataBlocks = intdiv($size + self::BLOCK - 1, self::BLOCK);
            if ($offset + $dataBlocks * self::BLOCK > $length) {
                throw new UpdateException('Обновление: обрезанный tar-поток (тело записи не помещается)');
            }
            $data = substr($tar, $offset, $size);
            $offset += $dataBlocks * self::BLOCK;

            if ($relative === '') {
                // Файл прямо в корне архива без имени — не наш контракт.
                throw new UpdateException('Обновление: запись архива без относительного пути');
            }
            $filePath = $destReal . '/' . self::ROOT . '/' . $relative;
            $parent = dirname($filePath);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
                throw new UpdateException('Обновление: не удалось создать каталог файла из архива: ' . $parent);
            }
            if (file_put_contents($filePath, $data) === false) {
                throw new UpdateException('Обновление: не удалось записать файл из архива: ' . $filePath);
            }
        }

        if (!$sawRoot) {
            throw new UpdateException('Обновление: архив пуст или не содержит корня ' . self::ROOT . '/');
        }

        $moduleRoot = $destReal . '/' . self::ROOT;
        if (!is_dir($moduleRoot)) {
            throw new UpdateException('Обновление: в архиве нет каталога ' . self::ROOT . '/');
        }

        return $moduleRoot;
    }

    /**
     * Декомпрессия gzip чанками с капом суммарного объёма (анти-bomb).
     *
     * @throws UpdateException
     */
    private function gunzipCapped(string $tarGzPath): string
    {
        $fh = @gzopen($tarGzPath, 'rb');
        if ($fh === false) {
            throw new UpdateException('Обновление: не удалось открыть gzip-артефакт: ' . $tarGzPath);
        }

        $buffer = '';
        try {
            while (!gzeof($fh)) {
                $chunk = gzread($fh, 524288); // 512 KiB
                if ($chunk === false) {
                    throw new UpdateException('Обновление: ошибка чтения gzip-потока артефакта');
                }
                $buffer .= $chunk;
                if (strlen($buffer) > self::MAX_DECOMPRESSED_BYTES) {
                    throw new UpdateException('Обновление: распакованный артефакт превышает лимит объёма');
                }
            }
        } finally {
            gzclose($fh);
        }

        if ($buffer === '') {
            throw new UpdateException('Обновление: пустой gzip-артефакт');
        }

        return $buffer;
    }

    /**
     * Разбор ustar-заголовка. Валидирует checksum и тип записи (только файл/каталог).
     *
     * @return array{name: string, size: int, type: string}
     * @throws UpdateException
     */
    private function parseHeader(string $header): array
    {
        $type = substr($header, 156, 1);
        if ($type !== '0' && $type !== "\0" && $type !== '5') {
            // Симлинк(2)/хардлинк(1)/устройства(3,4)/fifo(6)/pax(x,g)/gnu-long(L,K) — не наш контракт.
            throw new UpdateException('Обновление: недопустимый тип записи tar (0x' . bin2hex($type) . ') — отказ');
        }

        if (!$this->checksumValid($header)) {
            throw new UpdateException('Обновление: битый ustar-заголовок (checksum) — отказ');
        }

        $name = $this->trimField(substr($header, 0, 100));
        $prefix = $this->trimField(substr($header, 345, 155));
        if ($prefix !== '') {
            $name = $name === '' ? $prefix : $prefix . '/' . $name;
        }

        $size = $this->octal(substr($header, 124, 12));

        return ['name' => $name, 'size' => $size, 'type' => $type === "\0" ? '0' : $type];
    }

    /**
     * Валидировать путь записи: корень строго `CoreSync/`, без абсолютного пути и `..`-сегментов.
     * Возвращает путь ОТНОСИТЕЛЬНО корня CoreSync/ ('' — сам корень).
     *
     * @throws UpdateException
     */
    private function safeRelativePath(string $name): string
    {
        $name = str_replace('\\', '/', $name);

        if ($name === '' || $name[0] === '/') {
            throw new UpdateException('Обновление: абсолютный/пустой путь в архиве — отказ: ' . $name);
        }

        $segments = explode('/', rtrim($name, '/'));
        if ($segments[0] !== self::ROOT) {
            throw new UpdateException('Обновление: запись вне корня ' . self::ROOT . '/ — отказ: ' . $name);
        }
        foreach ($segments as $segment) {
            if ($segment === '..' || $segment === '.') {
                throw new UpdateException('Обновление: path traversal в архиве — отказ: ' . $name);
            }
        }

        array_shift($segments); // убрать сам корень CoreSync

        return implode('/', $segments);
    }

    /** Обрезать NUL-терминированное поле заголовка. */
    private function trimField(string $field): string
    {
        $nul = strpos($field, "\0");

        return $nul === false ? rtrim($field) : rtrim(substr($field, 0, $nul));
    }

    /** Октальное числовое поле ustar → int (пустое/битое → 0). */
    private function octal(string $field): int
    {
        $field = trim($this->trimField($field));
        if ($field === '' || preg_match('/^[0-7]+$/', $field) !== 1) {
            return 0;
        }

        return (int) octdec($field);
    }

    /**
     * ustar-checksum: беззнаковая сумма всех байт заголовка, где поле chksum (8 байт с offset 148)
     * трактуется как пробелы. Сверяется с октальным значением в самом поле.
     */
    private function checksumValid(string $header): bool
    {
        $stored = $this->octal(substr($header, 148, 8));

        $sum = 0;
        for ($i = 0; $i < self::BLOCK; $i++) {
            $byte = ($i >= 148 && $i < 156) ? 0x20 : ord($header[$i]);
            $sum += $byte;
        }

        return $sum === $stored;
    }
}
