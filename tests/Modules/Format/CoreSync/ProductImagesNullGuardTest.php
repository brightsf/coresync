<?php

namespace Tests\Modules\Format\CoreSync;

use PHPUnit\Framework\TestCase;

/**
 * Карточка товара БЕЗ картинок не должна падать в 500 (SATGO-1 §B).
 *
 * `{$product->images|count}` — это PHP-шный `count()`, применённый к свойству, которое у товара без
 * картинок равно null. На PHP 8 `count(null)` — TypeError, то есть fatal → 500 на карточке товара.
 *
 * Почему это чинит именно CoreSync-этап, хотя баг в темах и старше модуля: картинки модуль качает
 * ДОГОНЯЮЩЕЙ фазой (SAT-M3) — между применением товара и загрузкой его картинок есть окно, где товар
 * ЛЕГАЛЬНО без картинок. До CoreSync такой товар был экзотикой, с CoreSync — штатное состояние.
 *
 * Тест не перепечатывает вёрстку: он ЧИТАЕТ живые `design/*\/html/product.tpl`, выдёргивает из них
 * КАЖДОЕ реальное выражение над `$product->images` и прогоняет его через НАСТОЯЩИЙ Smarty на товаре
 * без картинок. Поэтому он ловит и те темы, о которых никто не вспомнил, и новые вхождения.
 */
class ProductImagesNullGuardTest extends TestCase
{
    /** @var string */
    private $compileDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compileDir = sys_get_temp_dir() . '/coresync-smarty-' . getmypid();
        if (!is_dir($this->compileDir)) {
            mkdir($this->compileDir, 0777, true);
        }
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    /**
     * Живые product.tpl всех тем витрины — списком с диска, а не хардкодом.
     *
     * @return array<string, array{0:string}>
     */
    public function productTemplates(): array
    {
        $cases = [];
        foreach (glob(dirname(__DIR__, 4) . '/design/*/html/product.tpl') ?: [] as $path) {
            $theme = basename(dirname(dirname($path)));
            $cases[$theme] = [$path];
        }

        return $cases;
    }

    /** Каркас темы на месте: если глоб перестал находить темы, тест обязан упасть, а не «пройти». */
    public function testThemesAreDiscovered(): void
    {
        $this->assertGreaterThanOrEqual(6, count($this->productTemplates()), 'темы витрины не найдены на диске');
    }

    /**
     * Guard обязан быть ПРОЗРАЧНЫМ: у товара С картинками счёт остаётся прежним, иначе мы бы
     * поменяли 500 на молча сломанную галерею (`default:[]` подставляется только вместо null).
     */
    public function testGuardIsTransparentWhenImagesArePresent(): void
    {
        $product = new \stdClass();
        $product->images = ['a', 'b'];

        $smarty = new \Smarty();
        $smarty->setCompileDir($this->compileDir);
        $smarty->setCacheDir($this->compileDir);
        $smarty->assign('product', $product);

        $this->assertSame('2', $smarty->fetch('string:{$product->images|default:[]|count}'));
        $this->assertSame('yes', $smarty->fetch('string:{if $product->images|default:[]|count > 1}yes{else}no{/if}'));

        $one = new \stdClass();
        $one->images = ['a'];
        $smarty->assign('product', $one);
        $this->assertSame('yes', $smarty->fetch('string:{if $product->images|default:[]|count == 1}yes{else}no{/if}'));
    }

    /**
     * @dataProvider productTemplates
     */
    public function testProductPageExpressionsSurviveProductWithoutImages(string $path): void
    {
        $source = (string) file_get_contents($path);

        // Каждое живое выражение над $product->images: от `$product->images|` до конца smarty-тега.
        // Даёт и `count == 1`, и `count > 4`, и голый `count` из {assign}.
        preg_match_all('/\$product->images\|[^}]*/', $source, $matches);
        $expressions = array_values(array_unique(array_map('trim', $matches[0])));

        $this->assertNotEmpty(
            $expressions,
            'в ' . basename(dirname(dirname($path))) . '/product.tpl нет выражений над $product->images — '
                . 'проверь регулярку, иначе тест зелен вхолостую'
        );

        $product = new \stdClass();
        $product->images = null; // товар в окне догоняющей фазы картинок

        foreach ($expressions as $expr) {
            $smarty = new \Smarty();
            $smarty->setCompileDir($this->compileDir);
            $smarty->setCacheDir($this->compileDir);
            $smarty->assign('product', $product);

            // Выражение живёт в булевом контексте — ровно как в теме ({if …} / {assign …}).
            $rendered = $smarty->fetch('string:{if ' . $expr . '}yes{else}no{/if}');

            $this->assertContains(
                $rendered,
                ['yes', 'no'],
                'выражение `' . $expr . '` в ' . $path . ' не пережило товар без картинок'
            );
        }
    }
}
