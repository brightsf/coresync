<?php

namespace Tests\Modules\Format\CoreSync\Support;

use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use Okay\Modules\Format\CoreSync\Core\SnapshotHttpClient;

/**
 * Подмена HTTP-клиента: отдаёт заранее заданное содержимое файлов на диск и считает скачивания.
 * (Реальный HTTP не трогаем — проверяется в SAT-RT round-trip.)
 */
final class FakeHttpClient extends SnapshotHttpClient
{
    /** @var array<string, string> имя файла => байты */
    private $contents;

    /** @var string */
    private $manifestBody;

    /** @var array<int, string> имена файлов в порядке фактического скачивания */
    public $downloadedNames = [];

    /**
     * @param array<string, string> $contents
     */
    public function __construct(array $contents = [], string $manifestBody = '')
    {
        parent::__construct(null);
        $this->contents = $contents;
        $this->manifestBody = $manifestBody;
    }

    public function fetchManifest(string $baseUrl, string $channelCode, string $token): string
    {
        return $this->manifestBody;
    }

    public function downloadFile(
        string $baseUrl,
        string $channelCode,
        string $token,
        string $fileName,
        string $destPath
    ): void {
        $this->downloadedNames[] = $fileName;

        if (!array_key_exists($fileName, $this->contents)) {
            throw new ManifestException('FakeHttpClient: нет содержимого для ' . $fileName);
        }

        $dir = dirname($destPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($destPath, $this->contents[$fileName]);
    }
}
