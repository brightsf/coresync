<?php

namespace Okay\Modules\Format\CoreSync\Core\Apply;

use Psr\Log\LoggerInterface;

/**
 * Автоматическое усыновление галереи витрины ПО СОДЕРЖИМОМУ: сопоставляет обещанные ядром content-хеши
 * с файлами, которые у товара УЖЕ лежат, чтобы не качать байты повторно и не создавать вторую строку
 * галереи рядом с существующей.
 *
 * Границы наследуются дословно от {@see LegacyGalleryAdopter}: класс НЕ пишет в `ok_images`, НЕ пишет
 * файлы и НЕ трогает `main_image_id` — он только измеряет файлы (единственным общим набором проверок
 * {@see GalleryFileProbe}) и возвращает решение. Записывает durable-строку вызыватель.
 *
 * 🔴 Матчинг возможен ТОЛЬКО в пределах одного товара: кандидатов подаёт вызыватель, выбирая их из
 * галереи ЭТОГО товара. Причина — замер: одни байты лежат под многими строками `ok_images` РАЗНЫХ
 * товаров (`rt-5277.png` — 36 раз). Поиск по одному хешу без товара выдал бы чужую строку, две
 * durable-строки указали бы на один `image_id`, и цикл удаления снёс бы живую картинку другого товара.
 */
class GalleryContentAdopter
{
    /** @var GalleryFileProbe */
    private $fileProbe;
    /** @var LoggerInterface|null */
    private $logger;

    public function __construct(GalleryFileProbe $fileProbe, ?LoggerInterface $logger = null)
    {
        $this->fileProbe = $fileProbe;
        $this->logger = $logger;
    }

    /**
     * Корень оригиналов галереи или null, если он небезопасен/недоступен. null = усыновление
     * пропускается целиком (fail-closed: лучше скачать, чем усыновить не пойми что).
     */
    public function root(): ?string
    {
        try {
            return $this->fileProbe->root();
        } catch (GalleryAdoptionException $e) {
            if ($this->logger !== null) {
                $this->logger->warning('CoreSync adopt: корень оригиналов галереи недоступен, усыновление пропущено');
            }

            return null;
        }
    }

    /**
     * Сопоставить обещанные хеши с файлами товара. Каждый кандидат отдаётся не более одному желаемому
     * ряду (один `image_id` — одна durable-строка); порядок разбора детерминирован: желаемые в порядке
     * подачи, кандидаты по (`position`, `image_id`).
     *
     * ⚠ `position` кандидата используется ТОЛЬКО как детерминированный порядок разбора: численного
     * равенства `position` витрины и `sort` снапшота нет (RECON §1, поправка 2026-08-17).
     *
     * @param list<array{key:int|string, sha256:string}>               $wanted     что ищем (уже в нужном порядке)
     * @param list<array{image_id:int, filename:string, position:int}> $candidates свободные строки галереи ЭТОГО товара
     * @return array<int|string, array{image_id:int, filename:string, sha256:string}> key => усыновлённая строка
     */
    public function match(string $root, array $wanted, array $candidates): array
    {
        usort($candidates, static function (array $a, array $b): int {
            return [$a['position'], $a['image_id']] <=> [$b['position'], $b['image_id']];
        });

        $hashes = [];   // image_id => sha256 | null (null = файл непригоден, второй раз не трогаем)
        $taken = [];    // image_id => true
        $matched = [];
        foreach ($wanted as $want) {
            $expected = (string) $want['sha256'];
            // Пустой/негодный хеш не усыновляет НИЧЕГО: обещания нет — значит качаем. Сверка идёт
            // hash_equals с хешем реального файла, поэтому «пусто совпадает с чем угодно» невозможно.
            if ($expected === '') {
                continue;
            }
            foreach ($candidates as $candidate) {
                $imageId = (int) $candidate['image_id'];
                if (isset($taken[$imageId])) {
                    continue;
                }
                if (!array_key_exists($imageId, $hashes)) {
                    $hashes[$imageId] = $this->contentHash($root, (string) $candidate['filename']);
                }
                $actual = $hashes[$imageId];
                if ($actual === null || !hash_equals($expected, $actual)) {
                    continue;
                }
                $taken[$imageId] = true;
                $matched[$want['key']] = [
                    'image_id' => $imageId,
                    'filename' => (string) $candidate['filename'],
                    'sha256'   => $actual,
                ];
                break;
            }
        }

        return $matched;
    }

    /**
     * sha256 содержимого файла галереи или null, если файл непригоден (симлинк, не обычный файл, вне
     * корня, нечитаем, подменён во время чтения). Непригодный файл НЕ усыновляется и НЕ ломает прогон.
     */
    private function contentHash(string $root, string $filename): ?string
    {
        try {
            return $this->fileProbe->measure($root, $filename)['sha256'];
        } catch (GalleryAdoptionException $e) {
            if ($this->logger !== null) {
                // Без имени файла и хеша: содержимое галереи клиента в логи не уезжает.
                $this->logger->warning('CoreSync adopt: файл галереи непригоден для усыновления');
            }

            return null;
        }
    }
}
