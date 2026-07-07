<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\Exceptions\NdjsonReadException;
use Okay\Modules\Format\CoreSync\Core\NdjsonGzReader;
use PHPUnit\Framework\TestCase;

/**
 * Потоковый ридер NDJSON.gz на РЕАЛЬНЫХ .gz-файлах (создаются на лету): построчное чтение,
 * счёт битых строк, порог fail-файла, и smoke на память (gzgets не держит файл целиком).
 */
class NdjsonGzReaderTest extends TestCase
{
    /** @var list<string> */
    private $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /**
     * @param list<string> $rawLines сырые строки (валидный JSON или мусор)
     */
    private function gzFile(array $rawLines): string
    {
        $path = sys_get_temp_dir() . '/ndjson_' . uniqid('', true) . '.ndjson.gz';
        $this->tmpFiles[] = $path;
        $gz = gzopen($path, 'wb9');
        foreach ($rawLines as $line) {
            gzwrite($gz, $line . "\n");
        }
        gzclose($gz);

        return $path;
    }

    private function validLine(string $externalId): string
    {
        return (string) json_encode([
            'external_id' => $externalId,
            'hash'        => str_repeat('a', 64),
            'data'        => ['name' => 'n' . $externalId],
        ]);
    }

    public function testReadsAllValidLinesStreaming(): void
    {
        $path = $this->gzFile([$this->validLine('1'), $this->validLine('2'), '', $this->validLine('3')]);

        $collected = [];
        $reader = new NdjsonGzReader();
        $stats = $reader->each($path, static function (array $line) use (&$collected): void {
            $collected[] = $line['external_id'];
        });

        $this->assertSame(['1', '2', '3'], $collected, 'пустая строка не считается, порядок сохранён');
        $this->assertSame(['total' => 3, 'valid' => 3, 'broken' => 0], $stats);
    }

    public function testBrokenLinesUnderThresholdAreCountedNotFatal(): void
    {
        // 1 битая строка из 30 = 3.3% < 5% → поток не падает.
        $lines = [];
        for ($i = 1; $i <= 29; $i++) {
            $lines[] = $this->validLine((string) $i);
        }
        $lines[] = '{ not valid json';
        $path = $this->gzFile($lines);

        $reader = new NdjsonGzReader();
        $stats = $reader->each($path, static function (): void {
        });

        $this->assertSame(30, $stats['total']);
        $this->assertSame(29, $stats['valid']);
        $this->assertSame(1, $stats['broken']);
    }

    public function testMissingRequiredKeyIsBroken(): void
    {
        // Строка без ключа "data" — битая (обязательные ключи контракта).
        $noData = (string) json_encode(['external_id' => '1', 'hash' => str_repeat('a', 64)]);
        $lines = [$noData];
        for ($i = 2; $i <= 40; $i++) {
            $lines[] = $this->validLine((string) $i);
        }
        $path = $this->gzFile($lines);

        $reader = new NdjsonGzReader();
        $stats = $reader->each($path, static function (): void {
        });

        $this->assertSame(1, $stats['broken'], 'строка без обязательного ключа — битая');
        $this->assertSame(39, $stats['valid']);
    }

    public function testBrokenRatioAboveThresholdFailsFile(): void
    {
        // 2 битых из 3 = 66% > 5% → NdjsonReadException (fail файла).
        $path = $this->gzFile([$this->validLine('1'), 'garbage-1', 'garbage-2']);

        $reader = new NdjsonGzReader();
        $this->expectException(NdjsonReadException::class);
        $reader->each($path, static function (): void {
        });
    }

    public function testMemoryStaysBoundedOnLargeFile(): void
    {
        // 20000 строк: если бы .gz распаковывался целиком — память выросла бы кратно объёму.
        $path = sys_get_temp_dir() . '/ndjson_big_' . uniqid('', true) . '.ndjson.gz';
        $this->tmpFiles[] = $path;
        $gz = gzopen($path, 'wb1');
        for ($i = 1; $i <= 20000; $i++) {
            gzwrite($gz, $this->validLine((string) $i) . "\n");
        }
        gzclose($gz);

        $reader = new NdjsonGzReader();
        $before = memory_get_usage();
        $count = 0;
        $stats = $reader->each($path, static function () use (&$count): void {
            $count++;
        });
        $delta = memory_get_usage() - $before;

        $this->assertSame(20000, $count);
        $this->assertSame(20000, $stats['valid']);
        // Не удерживаем строки → рост памяти близок к нулю (порог с большим запасом).
        $this->assertLessThan(2 * 1024 * 1024, $delta, 'память не должна расти с числом строк (потоковое чтение)');
    }
}
