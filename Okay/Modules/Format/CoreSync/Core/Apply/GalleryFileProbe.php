<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

use Okay\Core\Config;

/**
 * ЕДИНСТВЕННЫЙ набор проверок файла галереи витрины перед усыновлением. Вынесен из
 * {@see LegacyGalleryAdopter} без изменения поведения донора: у обоих путей усыновления (подписанный
 * план и автоматическое усыновление по содержимому) обязан быть один набор проверок, второй писать
 * нельзя.
 *
 * Что именно проверяется (порядок и тексты отказов сохранены дословно от донора):
 *  - имя файла — только basename, без NUL;
 *  - lstat: цель существует, НЕ симлинк, обычный файл;
 *  - realpath лежит ВНУТРИ корня оригиналов и читаем;
 *  - размер совпадает с ожидаемым, если ожидаемый задан;
 *  - fstat открытого дескриптора совпадает с lstat по dev/ino/size (файл не подменили между stat и open);
 *  - sha256 считается ПОТОКОМ по всему файлу, прочитанных байт ровно size;
 *  - повторный lstat после чтения: dev/ino/size те же, цель по-прежнему не симлинк.
 *
 * Класс НЕ выносит решения «усыновлять или нет» — он измеряет файл и отказывает на небезопасном.
 * Решение по содержимому принимает вызыватель ({@see LegacyGalleryAdopter} сверяет с планом,
 * {@see GalleryContentAdopter} — с обещанием ядра).
 */
class GalleryFileProbe
{
    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Корень оригиналов галереи витрины. Отказ, а не пустая строка: небезопасный корень запрещает
     * усыновление целиком.
     *
     * @throws GalleryAdoptionException
     */
    public function root(): string
    {
        $configured = (string) $this->config->root_dir . (string) $this->config->original_images_dir;
        $root = realpath($configured);
        if ($root === false || is_link($configured) || !is_dir($root) || !is_readable($root)) {
            throw new GalleryAdoptionException('Gallery adoption legacy originals root is unsafe or unavailable.');
        }

        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    /**
     * Измерить файл галереи: размер и sha256 СОДЕРЖИМОГО, со всеми проверками безопасности и
     * стабильности. $expectedSize !== null → размер сверяется с ожидаемым ДО чтения (путь плана,
     * где размер известен заранее); null → размером считается фактический lstat (путь усыновления по
     * содержимому, где авторитет — сам хеш).
     *
     * @return array{size:int, sha256:string}
     * @throws GalleryAdoptionException
     */
    public function measure(string $root, string $filename, ?int $expectedSize = null): array
    {
        if ($filename === '' || basename($filename) !== $filename || strpos($filename, "\0") !== false) {
            throw new GalleryAdoptionException('Gallery adoption legacy filename is unsafe.');
        }
        $path = $root . DIRECTORY_SEPARATOR . $filename;
        $stat = @lstat($path);
        $real = realpath($path);
        if (!is_array($stat) || is_link($path) || ($stat['mode'] & 0170000) !== 0100000) {
            throw new GalleryAdoptionException('Gallery adoption legacy file type is unsafe.');
        }
        if ($real === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0 || !is_readable($real)) {
            throw new GalleryAdoptionException('Gallery adoption legacy file location is unsafe.');
        }
        if ($expectedSize !== null && (int) $stat['size'] !== $expectedSize) {
            throw new GalleryAdoptionException('Gallery adoption legacy file size drifted.');
        }
        $size = (int) $stat['size'];
        $stream = @fopen($real, 'rb');
        if ($stream === false) {
            throw new GalleryAdoptionException('Gallery adoption legacy file cannot be opened safely.');
        }
        try {
            $opened = fstat($stream);
            if (!is_array($opened) || (int) $opened['dev'] !== (int) $stat['dev']
                || (int) $opened['ino'] !== (int) $stat['ino'] || (int) $opened['size'] !== $size) {
                throw new GalleryAdoptionException('Gallery adoption legacy file changed while opening.');
            }
            $context = hash_init('sha256');
            $hashedBytes = hash_update_stream($context, $stream);
            $sha = hash_final($context);
        } finally {
            fclose($stream);
        }
        $after = @lstat($path);
        if (!is_int($hashedBytes) || $hashedBytes !== $size
            || !is_array($after) || is_link($path)
            || (int) $after['dev'] !== (int) $stat['dev'] || (int) $after['ino'] !== (int) $stat['ino']
            || (int) $after['size'] !== $size) {
            throw new GalleryAdoptionException('Gallery adoption legacy file fingerprint drifted.');
        }

        return ['size' => $size, 'sha256' => $sha];
    }
}
