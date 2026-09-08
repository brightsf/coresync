<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Languages;
use Okay\Modules\Format\CoreSync\Core\Exceptions\ManifestException;
use Okay\Modules\Format\CoreSync\Core\ProductContentLanguageCatalog;
use PHPUnit\Framework\TestCase;

class ProductContentLanguageCatalogTest extends TestCase
{
    public function testReturnsOneSortedHrefLangMapWithoutPuttingIdsOnWire(): void
    {
        $catalog = $this->catalog([
            (object) ['href_lang' => 'uk', 'id' => 17],
            (object) ['href_lang' => 'ru', 'id' => 91],
            (object) ['href_lang' => 'en', 'id' => 42],
        ]);

        $this->assertSame(['en' => 42, 'ru' => 91, 'uk' => 17], $catalog->localIdsByHrefLang());
        $this->assertSame(['en', 'ru', 'uk'], $catalog->hrefLangs());
    }

    /** @dataProvider invalidLocalLanguages */
    public function testRejectsEmptyInvalidOrAmbiguousLocalCatalog(array $languages): void
    {
        $this->expectException(ManifestException::class);

        $this->catalog($languages)->localIdsByHrefLang();
    }

    /** @return array<string, array{array<int, object>}> */
    public function invalidLocalLanguages(): array
    {
        return [
            'empty catalog' => [[]],
            'empty href_lang' => [[(object) ['href_lang' => '', 'id' => 1]]],
            'unsafe href_lang' => [[(object) ['href_lang' => '../ru', 'id' => 1]]],
            'zero id' => [[(object) ['href_lang' => 'ru', 'id' => 0]]],
            'non-integer id' => [[(object) ['href_lang' => 'ru', 'id' => '1.5']]],
            'duplicate href_lang' => [[
                (object) ['href_lang' => 'ru', 'id' => 91],
                (object) ['href_lang' => 'ru', 'id' => 17],
            ]],
            'duplicate local id' => [[
                (object) ['href_lang' => 'ru', 'id' => 91],
                (object) ['href_lang' => 'uk', 'id' => 91],
            ]],
        ];
    }

    /** @param array<int, object> $rows */
    private function catalog(array $rows): ProductContentLanguageCatalog
    {
        $languages = $this->createMock(Languages::class);
        $languages->method('getAllLanguages')->willReturn($rows);

        return new ProductContentLanguageCatalog($languages);
    }
}
