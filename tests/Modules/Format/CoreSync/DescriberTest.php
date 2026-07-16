<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Describer;
use Okay\Modules\Format\CoreSync\Core\Exceptions\DescribeUnavailableException;
use Okay\Modules\Format\CoreSync\Core\ManifestValidator;
use PHPUnit\Framework\TestCase;

/**
 * Описатель модуля для церемонии подключения (SATFEED-M §B).
 *
 * Утверждения о форме ответа читаются ИЗ САМОЙ vendored-схемы (`schema/v1/describe.schema.json`,
 * побайтовая копия истины из b2bCRM `docs/contracts/satellite/v1/`), а не перепечатываются здесь:
 * перепечатка разъехалась бы с контрактом молча. Тот же приём, что у ManifestValidator
 * («обязательные поля берутся из схемы, не хардкод»).
 */
class DescriberTest extends TestCase
{
    const BASE_URL = 'https://shop.example';

    /** Что вернёт шов генератора урлов (в бою — Router::generateUrl). */
    private $routePaths = [
        'product'  => '/products/{token}/',
        'category' => '/catalog/{token}/',
    ];

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_NAME']);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $settingsOverrides
     */
    private function describer(array $settingsOverrides = []): Describer
    {
        $cfg = array_merge([
            'core_url'             => 'https://core.example',
            'channel_code'         => 'demo',
            'token'                => 'secret',
            'storefront_base_url'  => self::BASE_URL,
        ], $settingsOverrides);

        $settings = $this->createMock(Settings::class);
        $settings->expects($this->any())->method('get')->willReturnCallback(function (string $key) use ($cfg) {
            return $key === Contract::SETTINGS_KEY ? $cfg : null;
        });

        return new StubbedRouteDescriber($settings, new ManifestValidator(), $this->routePaths);
    }

    /**
     * @return array<string, mixed>
     */
    private function contractSchema(): array
    {
        $path = dirname(__DIR__, 4) . '/Okay/Modules/Format/CoreSync/schema/v1/describe.schema.json';
        $this->assertFileExists($path, 'vendored-копия describe.schema.json обязана лежать в модуле');

        return json_decode((string) file_get_contents($path), true);
    }

    public function testDescribeSatisfiesVendoredContractSchema(): void
    {
        $schema = $this->contractSchema();
        $out = $this->describer()->describe();

        foreach ((array) $schema['required'] as $key) {
            $this->assertArrayHasKey($key, $out, sprintf('контракт требует ключ "%s"', $key));
        }

        // Паттерны берём из схемы — не из головы.
        $this->assertMatchesRegularExpression(
            $this->toPcre($schema['properties']['schema_version']['pattern']),
            $out['schema_version']
        );
        $this->assertMatchesRegularExpression(
            $this->toPcre($schema['properties']['storefront']['properties']['base_url']['pattern']),
            $out['storefront']['base_url']
        );
        $this->assertMatchesRegularExpression(
            $this->toPcre($schema['properties']['url_patterns']['properties']['product']['pattern']),
            $out['url_patterns']['product']
        );

        foreach ((array) $schema['properties']['storefront']['required'] as $key) {
            $this->assertArrayHasKey($key, $out['storefront']);
        }
        foreach ((array) $schema['properties']['url_patterns']['required'] as $key) {
            $this->assertArrayHasKey($key, $out['url_patterns']);
        }
    }

    /**
     * Threat-model §3: `base_url` уезжает в <url> боевого фида ядра. Host подделывается — значение
     * обязано прийти из настроек. Kill-проба: реализация на Request::getRootUrl() покраснеет здесь.
     */
    public function testBaseUrlComesFromSettingsAndIgnoresHostHeader(): void
    {
        $_SERVER['HTTP_HOST'] = 'evil.attacker.example';
        $_SERVER['SERVER_NAME'] = 'evil.attacker.example';

        $out = $this->describer()->describe();

        $this->assertSame(self::BASE_URL, $out['storefront']['base_url']);
        $this->assertStringNotContainsString('evil.attacker.example', json_encode($out));
    }

    public function testBaseUrlTrailingSlashNormalised(): void
    {
        $out = $this->describer(['storefront_base_url' => 'https://shop.example/'])->describe();

        $this->assertSame('https://shop.example', $out['storefront']['base_url']);
    }

    /** Не задан — fail-closed: врать хостом из запроса нельзя, а неверный домен молча портит фид. */
    public function testMissingBaseUrlFailsClosed(): void
    {
        $this->expectException(DescribeUnavailableException::class);
        $this->describer(['storefront_base_url' => ''])->describe();
    }

    /**
     * @dataProvider badBaseUrls
     */
    public function testRejectsNonHttpOrTaintedBaseUrl(string $bad): void
    {
        $this->expectException(DescribeUnavailableException::class);
        $this->describer(['storefront_base_url' => $bad])->describe();
    }

    /**
     * @return array<string, array{0:string}>
     */
    public function badBaseUrls(): array
    {
        return [
            'javascript'   => ['javascript:alert(1)'],
            'data'         => ['data:text/html,x'],
            'no scheme'    => ['shop.example'],
            'no host'      => ['https://'],
            'crlf'         => ["https://shop.example\r\nX-Injected: 1"],
            'interior nul' => ["https://shop.\x00example"],
            'tab in host'  => ["https://shop.\texample"],
            'control char' => ["https://shop.example/\x1Fpath"],
        ];
    }

    /**
     * Хвостовой мусор (пробелы/NUL от кривой вставки в форму) — нормализуется, а не отвергается:
     * после trim это ровно тот же валидный адрес. Отвергать надо ЗАРАЖЁННЫЙ адрес (см. выше), а не
     * неаккуратно вставленный.
     */
    public function testTrailingWhitespaceAndNulNormalised(): void
    {
        $out = $this->describer(['storefront_base_url' => "  https://shop.example\x00"])->describe();

        $this->assertSame(self::BASE_URL, $out['storefront']['base_url']);
    }

    public function testProductPatternCarriesSlugPlaceholderFromRealRouting(): void
    {
        $out = $this->describer()->describe();

        $this->assertSame('/products/{slug}/', $out['url_patterns']['product']);
        $this->assertSame('/catalog/{slug}/', $out['url_patterns']['category']);
    }

    /** Роутер отдал не тот путь (плейсхолдер потерялся) → лучше не ответить, чем соврать. */
    public function testProductPatternWithoutPlaceholderFailsClosed(): void
    {
        $this->routePaths['product'] = '/products/';

        $this->expectException(DescribeUnavailableException::class);
        $this->describer()->describe();
    }

    /**
     * Абсолютный/протокол-относительный шаблон увёл бы ссылку фида на чужой домен в обход
     * base_url — ядро такое отвергает, модуль не имеет права такое отдавать.
     *
     * @dataProvider badProductPaths
     */
    public function testProductPatternWithSchemeOrHostFailsClosed(string $bad): void
    {
        $this->routePaths['product'] = $bad;

        $this->expectException(DescribeUnavailableException::class);
        $this->describer()->describe();
    }

    /**
     * @return array<string, array{0:string}>
     */
    public function badProductPaths(): array
    {
        return [
            'absolute'          => ['https://evil.example/products/{token}/'],
            'protocol-relative' => ['//evil.example/products/{token}/'],
        ];
    }

    /** Категория необязательна по контракту: не вывелась — ключ просто не едет, product живёт. */
    public function testCategoryPatternOptional(): void
    {
        $this->routePaths['category'] = '/catalog/';

        $out = $this->describer()->describe();

        $this->assertArrayNotHasKey('category', $out['url_patterns']);
        $this->assertSame('/products/{slug}/', $out['url_patterns']['product']);
    }

    /**
     * schema_version — живой механизм версий модуля (vendored manifest-схема, которую модуль реально
     * умеет применять), а не второй хардкод рядом с первым.
     */
    public function testSchemaVersionComesFromLiveVersionMechanism(): void
    {
        $out = $this->describer()->describe();

        $this->assertSame((new ManifestValidator())->supportedSchemaVersion(), $out['schema_version']);

        // …и это ровно то, что объявляет vendored-схема манифеста + мажор Contract. Схема
        // фиксирует версию через pattern (новая форма, как в ядре) либо исторический const —
        // describe отдаёт версию, которую эта схема принимает.
        $manifestSchema = json_decode((string) file_get_contents(
            dirname(__DIR__, 4) . '/Okay/Modules/Format/CoreSync/schema/v1/manifest.schema.json'
        ), true);
        $spec = $manifestSchema['properties']['schema_version'];
        if (isset($spec['const'])) {
            $this->assertSame($spec['const'], $out['schema_version']);
        } else {
            $this->assertMatchesRegularExpression('#' . $spec['pattern'] . '#', $out['schema_version']);
        }
        $this->assertSame((string) Contract::SCHEMA_MAJOR, explode('.', $out['schema_version'])[0]);
    }

    /** Версия модуля — из живого module.json, не из константы-дубля. */
    public function testModuleVersionComesFromModuleJson(): void
    {
        $moduleJson = json_decode((string) file_get_contents(
            dirname(__DIR__, 4) . '/Okay/Modules/Format/CoreSync/Init/module.json'
        ), true);

        $out = $this->describer()->describe();

        $this->assertSame($moduleJson['version'], $out['module']['version']);
        $this->assertSame('Format/CoreSync', $out['module']['name']);
    }

    /** Capabilities — по факту кода (живые константы Contract), а не выдуманный список впрок. */
    public function testCapabilitiesReportLiveSyncModes(): void
    {
        $out = $this->describer()->describe();

        $this->assertSame(
            [Contract::SYNC_MODE_FULL, Contract::SYNC_MODE_PRICE_STOCK],
            $out['capabilities']['sync_modes']
        );
    }

    private function toPcre(string $pattern): string
    {
        return '~' . str_replace('~', '\~', $pattern) . '~';
    }
}

/**
 * Шов генератора урлов: в бою Describer зовёт статический Router::generateUrl (требует поднятого
 * ServiceLocator/роутера), в тесте подменяем шов на заранее заданные пути.
 */
class StubbedRouteDescriber extends Describer
{
    /** @var array<string, string> */
    private $routePaths;

    /**
     * @param array<string, string> $routePaths
     */
    public function __construct(Settings $settings, ManifestValidator $validator, array $routePaths)
    {
        parent::__construct($settings, $validator);
        $this->routePaths = $routePaths;
    }

    protected function routePath(string $routeName, string $slugToken): string
    {
        if (!isset($this->routePaths[$routeName])) {
            throw new \Exception('Route "' . $routeName . '" not found');
        }

        return str_replace('{token}', $slugToken, $this->routePaths[$routeName]);
    }
}
