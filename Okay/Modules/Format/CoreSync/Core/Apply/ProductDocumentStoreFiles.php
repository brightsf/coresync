<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

use Okay\Core\Config;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use Okay\Modules\Format\CoreSync\Core\StagedSnapshotFile;

/**
 * Файловая часть своего хранилища документов [SECURITY-SENSITIVE]: каталог
 * files/coresync/documents/ в корне витрины, имя файла строится только из проверенного sha256.
 * Ничего вне этого каталога не создаёт, не переносит и не удаляет; симлинки не разыменовывает.
 */
class ProductDocumentStoreFiles
{
    const RELATIVE_DIR = 'files/coresync/documents';
    const SHA_PATTERN = '/\A[0-9a-f]{64}\z/';
    const NAME_PATTERN = '/\A([0-9a-f]{64})\.pdf\z/';

    /** @var string */
    private $root;

    public function __construct(Config $config)
    {
        $this->root = rtrim((string) $config->get('root_dir'), '/\\');
    }

    /** Путь от корня витрины, им же строится ссылка на сайте. */
    public function relativePath(string $sha256): string
    {
        return self::RELATIVE_DIR . '/' . $this->name($sha256);
    }

    /** Файл на месте: обычный файл (не симлинк) нужного размера. Хеш сверен при переносе. */
    public function isStored(string $sha256, int $bytes): bool
    {
        $stat = @lstat($this->path($sha256));

        return is_array($stat) && ($stat['mode'] & 0170000) === 0100000 && $stat['size'] === $bytes;
    }

    /**
     * Перенести проверенный файл пакета из staging в хранилище одним rename (та же ФС:
     * staging живёт в files/coresync/<версия>). Не прошёл сверку sha256/размера/PDF - false.
     *
     * @throws ManifestException каталог хранилища небезопасен или rename не удался
     */
    public function ingest(string $stagingDir, string $sha256, int $bytes): bool
    {
        $staged = rtrim($stagingDir, '/') . '/document-' . $this->sha($sha256) . '.pdf';
        if (!StagedSnapshotFile::matches($staged, $sha256, ['role' => 'product_document', 'bytes' => $bytes])) {
            return false;
        }
        $target = $this->path($sha256, true);
        if (is_link($target) || (file_exists($target) && !is_file($target))) {
            throw new ManifestException('Небезопасный файл в хранилище документов');
        }
        if (!@rename($staged, $target)) {
            throw new ManifestException('Не удалось перенести документ в хранилище');
        }
        @chmod($target, 0644);

        return true;
    }

    /**
     * Файл уже лежит в хранилище без строки (обрыв между rename и записью строки): принять его
     * можно только после полной сверки содержимого.
     */
    public function adopt(string $sha256, int $bytes): bool
    {
        $path = $this->path($sha256);

        return StagedSnapshotFile::matches($path, $sha256, ['bytes' => $bytes])
            && self::hasPdfSignature($path);
    }

    /** Удалить файл хранилища (симлинк с таким именем снимается сам, цель не трогается). */
    public function delete(string $sha256): bool
    {
        $path = $this->path($sha256);
        if (is_link($path) || is_file($path)) {
            @unlink($path);
        }

        return !is_link($path) && !file_exists($path);
    }

    /** @return list<string> sha256 файлов, лежащих в каталоге хранилища */
    public function storedShas(): array
    {
        $dir = $this->dir();
        if (!is_dir($dir) || is_link($dir)) {
            return [];
        }
        $shas = [];
        foreach (scandir($dir) ?: [] as $name) {
            if (preg_match(self::NAME_PATTERN, $name, $match) === 1) {
                $shas[] = $match[1];
            }
        }

        return $shas;
    }

    private function name(string $sha256): string
    {
        return $this->sha($sha256) . '.pdf';
    }

    private function sha(string $sha256): string
    {
        if (preg_match(self::SHA_PATTERN, $sha256) !== 1) {
            throw new ManifestException('Некорректный sha256 документа');
        }

        return $sha256;
    }

    private function path(string $sha256, bool $create = false): string
    {
        $name = $this->name($sha256);
        if ($create) {
            $this->ensureDir();
        }

        return $this->dir() . '/' . $name;
    }

    private function dir(): string
    {
        return $this->root . '/' . self::RELATIVE_DIR;
    }

    /** @throws ManifestException каталог или его родитель подменён симлинком / не каталог */
    private function ensureDir(): void
    {
        $base = $this->root . '/files/coresync';
        $dir = $this->dir();
        foreach ([$this->root . '/files', $base, $dir] as $path) {
            if (is_link($path) || (file_exists($path) && !is_dir($path))) {
                throw new ManifestException('Небезопасный каталог хранилища документов');
            }
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ManifestException('Не удалось создать каталог хранилища документов');
        }
        $root = realpath($this->root);
        if ($root === false || realpath($dir) !== $root . '/' . self::RELATIVE_DIR) {
            throw new ManifestException('Каталог хранилища документов вне корня витрины');
        }
    }

    private static function hasPdfSignature(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            return false;
        }
        $signature = fread($handle, 5);
        fclose($handle);

        return $signature === '%PDF-';
    }
}
