<?php

namespace Okay\Modules\Format\CoreSync\Core\Update;

/**
 * Скачивание релизного артефакта под пином префикса [SECURITY-SENSITIVE].
 *
 * threat-model §1: url приходит ОТ ЯДРА — качаем ТОЛЬКО если он проходит {@see ReleasePin} (проверка
 * ДО любого сетевого обращения). GitHub Releases 302-редиректит на свой CDN — редиректы следуем
 * (пин уже сработал на исходном url; целостность гарантирует sha256 на скачанном файле, а не хост CDN).
 *
 * Поток пишется на диск чанками с жёстким капом объёма (враждебный ответ не выест диск/память),
 * при ЛЮБОМ сбое частичный файл удаляется — на swap не должен попасть обрезанный артефакт.
 */
class ArtifactDownloader
{
    /** Сетевой шов вынесен в protected {@see openSource} — тесты подменяют его фикстурой (реальный HTTP в round-trip). */
    private const CHUNK = 262144; // 256 KiB

    /**
     * Скачать $url в $destPath. Кап $maxBytes — верхняя граница объёма (превышение → отказ).
     *
     * @throws UpdateException не под пином / сеть / превышение капа / пустой ответ
     */
    public function download(string $url, string $destPath, int $maxBytes): void
    {
        if (!ReleasePin::isPinned($url)) {
            // Отказ ДО скачивания: чужой/подделанный url ядра дальше пина не проходит.
            throw new UpdateException('Обновление: url артефакта вне канонического префикса релизов — отказ до скачивания');
        }

        $dir = dirname($destPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new UpdateException('Обновление: не удалось создать каталог загрузки: ' . $dir);
        }

        $src = $this->openSource($url);
        if ($src === false) {
            throw new UpdateException('Обновление: не удалось открыть источник артефакта: ' . $url);
        }
        $dst = @fopen($destPath, 'wb');
        if ($dst === false) {
            fclose($src);
            throw new UpdateException('Обновление: не удалось открыть файл загрузки на запись: ' . $destPath);
        }

        $written = 0;
        try {
            while (!feof($src)) {
                $chunk = fread($src, self::CHUNK);
                if ($chunk === false) {
                    throw new UpdateException('Обновление: ошибка чтения потока артефакта');
                }
                $written += strlen($chunk);
                if ($written > $maxBytes) {
                    throw new UpdateException('Обновление: артефакт превышает лимит объёма (' . $maxBytes . ' байт)');
                }
                if ($chunk !== '' && fwrite($dst, $chunk) === false) {
                    throw new UpdateException('Обновление: ошибка записи артефакта на диск');
                }
            }
        } catch (\Throwable $e) {
            fclose($src);
            fclose($dst);
            @unlink($destPath);
            throw $e instanceof UpdateException ? $e : new UpdateException('Обновление: сбой скачивания: ' . $e->getMessage());
        }

        fclose($src);
        fclose($dst);

        if ($written === 0) {
            @unlink($destPath);
            throw new UpdateException('Обновление: пустой ответ при скачивании артефакта');
        }
    }

    /**
     * Открыть сетевой поток к артефакту (следуя редиректам GitHub→CDN). Шов для тестируемости —
     * тест подменяет его чтением фикстуры (реальный HTTP проверяется в round-trip, не в unit).
     *
     * @return resource|false
     */
    protected function openSource(string $url)
    {
        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => 60,
                'follow_location' => 1,   // GitHub Releases → CDN
                'max_redirects'   => 5,
                'header'          => "Accept: application/octet-stream\r\n",
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        return @fopen($url, 'rb', false, $context);
    }
}
