<?php

namespace Tests\Modules\Format\CoreSync\Support;

/**
 * Сборка ustar-tar.gz фикстур для тестов безопасной распаковки — включая ЗЛОНАМЕРЕННЫЕ записи
 * (path traversal `..`, симлинк-typeflag, корень не CoreSync/), которые PharData «санитайзит» и
 * потому не даёт проверить фильтр. Здесь мы штампуем сырые 512-байтовые заголовки руками, поэтому
 * можем положить в архив что угодно и убедиться, что экстрактор это ОТВЕРГАЕТ.
 */
final class TarFixtureBuilder
{
    private const BLOCK = 512;

    /** @var string сырой tar (до gzip) */
    private $tar = '';

    /**
     * Добавить обычный файл. typeflag по умолчанию '0'.
     */
    public function addFile(string $name, string $content, string $typeflag = '0', string $prefix = ''): self
    {
        $this->tar .= $this->header($name, strlen($content), $typeflag, $prefix);
        $this->tar .= $this->pad($content);

        return $this;
    }

    /** Добавить каталог (typeflag '5', без тела). */
    public function addDir(string $name, string $prefix = ''): self
    {
        $this->tar .= $this->header(rtrim($name, '/') . '/', 0, '5', $prefix);

        return $this;
    }

    /** Записать .tar.gz в путь. */
    public function writeGz(string $path): string
    {
        $raw = $this->tar . str_repeat("\0", self::BLOCK * 2); // два нулевых блока — конец архива
        $gz = gzencode($raw, 6);
        file_put_contents($path, $gz);

        return $path;
    }

    /** Валидный минимальный модуль CoreSync/ (module.json нужной версии + Describer.php). */
    public static function validModule(string $version): self
    {
        $b = new self();
        $b->addDir('CoreSync');
        $b->addDir('CoreSync/Init');
        $b->addDir('CoreSync/Core');
        $b->addFile('CoreSync/Init/module.json', json_encode(['version' => $version]));
        $b->addFile('CoreSync/Core/Describer.php', "<?php\n// stub\n");

        return $b;
    }

    private function header(string $name, int $size, string $typeflag, string $prefix): string
    {
        $h = str_repeat("\0", self::BLOCK);
        $h = substr_replace($h, substr($name, 0, 100), 0, min(100, strlen($name)));
        $h = $this->put($h, 100, sprintf('%07o', 0644) . "\0");   // mode
        $h = $this->put($h, 108, sprintf('%07o', 0) . "\0");       // uid
        $h = $this->put($h, 116, sprintf('%07o', 0) . "\0");       // gid
        $h = $this->put($h, 124, sprintf('%011o', $size) . "\0");  // size
        $h = $this->put($h, 136, sprintf('%011o', 0) . "\0");      // mtime
        $h = $this->put($h, 156, $typeflag);                        // typeflag
        $h = $this->put($h, 257, "ustar\0");                       // magic
        $h = $this->put($h, 263, '00');                             // version
        if ($prefix !== '') {
            $h = $this->put($h, 345, substr($prefix, 0, 155));
        }
        // checksum: 8 пробелов на время подсчёта
        $h = $this->put($h, 148, str_repeat(' ', 8));
        $sum = 0;
        for ($i = 0; $i < self::BLOCK; $i++) {
            $sum += ord($h[$i]);
        }
        $h = $this->put($h, 148, sprintf('%06o', $sum) . "\0 ");

        return $h;
    }

    /** Записать строку в позицию без изменения длины блока. */
    private function put(string $block, int $offset, string $value): string
    {
        return substr_replace($block, $value, $offset, strlen($value));
    }

    /** Дополнить тело до кратности блока. */
    private function pad(string $content): string
    {
        $mod = strlen($content) % self::BLOCK;
        if ($mod === 0) {
            return $content;
        }

        return $content . str_repeat("\0", self::BLOCK - $mod);
    }
}
