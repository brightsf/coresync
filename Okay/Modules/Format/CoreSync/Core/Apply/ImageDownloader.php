<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

/**
 * Зеркалирование картинки на витрину: скачать по URL, сохранить локально, вернуть локальное имя
 * файла (или null при неудаче). Прод-реализация — CurlImageDownloader (curl по образцу
 * Okay\Core\Image::downloadImage); тесты подставляют фейк (реальный HTTP — SAT-RT).
 */
interface ImageDownloader
{
    /**
     * @return string|null локальное имя файла (в original_images_dir) или null при неудаче
     */
    public function download(string $url): ?string;

    /**
     * Remove a freshly downloaded file before ownership was handed to ImagesEntity.
     */
    public function deleteOwned(string $filename): bool;
}
