<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Modules\Format\CoreSync\Core\Exceptions\NdjsonReadException;

/**
 * Потоковый ридер NDJSON.gz: gzopen/gzgets построчно (память O(строка) — .gz НЕ распаковывается
 * целиком в память). Каждая строка: json_decode + проверка обязательных ключей
 * {external_id, hash, data}. Битая строка (не JSON-объект / нет ключа) → счётчик broken, поток не
 * падает; при доле broken выше порога (Contract::NDJSON_MAX_BROKEN_RATIO) — fail всего файла.
 */
class NdjsonGzReader
{
    /**
     * Пройти файл построчно, вызывая $onLine(array $line, int $lineNo, string $rawJson) на каждой
     * валидной строке. Raw JSON сохраняет container shape, которую associative decode теряет.
     * Возвращает статистику {total, valid, broken}. Применение — ответственность вызывающего.
     *
     * @param callable $onLine function(array $decodedLine, int $lineNo, string $rawJson): void
     * @return array{total:int, valid:int, broken:int}
     * @throws NdjsonReadException файл не открылся / доля битых строк выше порога
     */
    public function each(string $path, callable $onLine): array
    {
        $gz = @gzopen($path, 'rb');
        if ($gz === false) {
            throw new NdjsonReadException('Не удалось открыть NDJSON.gz: ' . $path);
        }

        $total = 0;
        $valid = 0;
        $broken = 0;
        $lineNo = 0;

        try {
            while (($raw = gzgets($gz)) !== false) {
                $lineNo++;
                $trimmed = trim($raw);
                if ($trimmed === '') {
                    continue; // пустые строки не считаются
                }

                $total++;
                $decoded = json_decode($trimmed, true);
                if (!is_array($decoded) || !$this->hasRequiredKeys($decoded)) {
                    $broken++;
                    continue;
                }

                $valid++;
                $onLine($decoded, $lineNo, $trimmed);
            }
        } finally {
            gzclose($gz);
        }

        if ($total > 0 && ($broken / $total) > Contract::NDJSON_MAX_BROKEN_RATIO) {
            throw new NdjsonReadException(sprintf(
                'Файл %s: битых строк %d из %d (порог %d%%) — файл не применён',
                basename($path),
                $broken,
                $total,
                (int) (Contract::NDJSON_MAX_BROKEN_RATIO * 100)
            ));
        }

        return ['total' => $total, 'valid' => $valid, 'broken' => $broken];
    }

    /**
     * Прочитать все валидные строки файла в массив (для мелких словарей: categories/brands/features,
     * где нужен второй проход по forward-ref). Товары читаются потоково через each().
     *
     * @return array{lines: array<int, array<string, mixed>>, raw_lines: string[], stats: array{total:int, valid:int,broken:int}}
     * @throws NdjsonReadException
     */
    public function readAll(string $path): array
    {
        $lines = [];
        $rawLines = [];
        $stats = $this->each($path, static function (array $line, int $lineNo, string $rawJson) use (&$lines, &$rawLines): void {
            $lines[] = $line;
            $rawLines[] = $rawJson;
        });

        return ['lines' => $lines, 'raw_lines' => $rawLines, 'stats' => $stats];
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function hasRequiredKeys(array $decoded): bool
    {
        foreach (Contract::NDJSON_REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $decoded)) {
                return false;
            }
        }
        // data обязан быть объектом (ассоц-массивом)
        return is_array($decoded['data']);
    }
}
