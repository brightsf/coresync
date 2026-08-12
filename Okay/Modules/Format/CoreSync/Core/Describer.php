<?php

namespace Okay\Modules\Format\CoreSync\Core;

use Okay\Core\Router;
use Okay\Core\Settings;
use Okay\Modules\Format\CoreSync\Core\Exceptions\CoreSyncException;
use Okay\Modules\Format\CoreSync\Core\Exceptions\DescribeUnavailableException;

/**
 * Описание модуля для церемонии подключения (SATFEED-M §B, спека ядра §4) [SECURITY-SENSITIVE].
 *
 * Ядро спрашивает «опиши себя» и сохраняет ответ целиком, чтобы строить в фидах ссылки на витрину
 * сателлита. Отвечает модуль, потому что только он знает свой роутинг и свой домен. Форма ответа —
 * контракт `schema/v1/describe.schema.json` (vendored-копия истины из b2bCRM
 * `docs/contracts/satellite/v1/`); ядро читает из него ровно `storefront.base_url` +
 * `url_patterns.product`, остальное хранит для диагностики.
 *
 * **Чистая функция над настройками и роутером — в сеть не ходит и ничего не пишет.** Церемония это
 * чтение: модуль по её результату НИЧЕГО у себя не применяет.
 *
 * **Fail-closed (threat-model §3).** Всё, что здесь собирается, уезжает в `<url>` боевого фида,
 * который читает внешняя площадка. Поэтому:
 *  - `base_url` — ТОЛЬКО из настроек модуля. Заголовок `Host` подделывается, а Okay CMS канонического
 *    адреса витрины не хранит вообще (`Config::root_url` удалён в пользу `Request::getRootUrl()`,
 *    то есть `$_SERVER['HTTP_HOST']`) — поэтому адрес заводит оператор полем `storefront_base_url`;
 *  - шаблоны — из ЖИВОГО роутера витрины ({@see Router::generateUrl}), не из хардкода;
 *  - не собралось честно → исключение, а не догадка. Приёмник превратит его в неразличимую 404.
 */
class Describer
{
    /** Плейсхолдер контракта: сюда ядро подставляет опубликованный slug из реестра канала. */
    const SLUG_PLACEHOLDER = '{slug}';

    /**
     * Заглушка вместо slug'а для генератора урлов. Только [a-z]: должна пережить strtr/strip_tags/
     * htmlspecialchars внутри Router::generateUrl и не столкнуться с реальным slug'ом.
     */
    const SLUG_TOKEN = 'coresyncslugplaceholdertoken';

    /** Поле настроек модуля с каноническим адресом витрины (Okay такого источника не имеет). */
    const SETTING_BASE_URL = 'storefront_base_url';

    /** @var Settings */
    private $settings;

    /** @var ManifestValidator */
    private $manifestValidator;

    public function __construct(Settings $settings, ManifestValidator $manifestValidator)
    {
        $this->settings = $settings;
        $this->manifestValidator = $manifestValidator;
    }

    /**
     * Собрать описание. Форма — describe.schema.json.
     *
     * @return array<string, mixed>
     * @throws DescribeUnavailableException честно описать себя невозможно
     */
    public function describe(): array
    {
        $urlPatterns = [];
        foreach (['product', 'category'] as $routeName) {
            $pattern = $this->urlPattern($routeName);
            if ($pattern !== null) {
                $urlPatterns[$routeName] = $pattern;
            }
        }

        // product — обязателен по контракту (ядро без него ссылку не построит). Не вывелся —
        // не отвечаем вовсе: молчание чинится, кривая ссылка в боевом фиде — нет.
        if (!isset($urlPatterns['product'])) {
            throw new DescribeUnavailableException(
                'Не удалось вывести шаблон карточки товара из роутера витрины — описание не собрано'
            );
        }

        try {
            $schemaVersion = $this->manifestValidator->supportedSchemaVersion();
        } catch (CoreSyncException $e) {
            throw new DescribeUnavailableException('Не определяется поддерживаемый schema_version: ' . $e->getMessage());
        }

        $capabilities = [
            // По факту кода, а не впрок: ядро на capabilities не ветвится (спека §4).
            'sync_modes' => [Contract::SYNC_MODE_FULL, Contract::SYNC_MODE_PRICE_STOCK],
            'snapshot_schema_versions' => [
                Contract::SNAPSHOT_SCHEMA_V1,
                Contract::SNAPSHOT_SCHEMA_V2,
            ],
        ];
        $sourceIdentity = $this->productSourceIdentityCapability();
        if ($sourceIdentity !== null) {
            $capabilities['product_source_identity'] = $sourceIdentity;
        }

        return [
            'schema_version' => $schemaVersion,
            'module'         => [
                'name'    => 'Format/CoreSync',
                'version' => $this->moduleVersion(),
            ],
            'storefront'     => [
                'base_url' => $this->baseUrl(),
            ],
            'url_patterns'   => $urlPatterns,
            'capabilities'   => $capabilities,
        ];
    }

    /** @return array<string, mixed>|null */
    private function productSourceIdentityCapability(): ?array
    {
        $cfg = $this->settings->get(Contract::SETTINGS_KEY);
        $cfg = is_array($cfg) ? $cfg : [];
        $instance = $cfg[Contract::SETTINGS_SOURCE_INSTANCE_FIELD] ?? null;
        if (!is_string($instance) || !Contract::isValidSourceInstance($instance)) {
            return null;
        }

        return [
            'namespace' => Contract::SOURCE_IDENTITY_NAMESPACE,
            'instance' => $instance,
            'entities' => Contract::SOURCE_IDENTITY_ENTITIES,
        ];
    }

    /**
     * Канонический адрес витрины из настроек модуля.
     *
     * @throws DescribeUnavailableException не задан или непригоден
     */
    private function baseUrl(): string
    {
        $cfg = $this->settings->get(Contract::SETTINGS_KEY);
        $cfg = is_array($cfg) ? $cfg : [];
        $baseUrl = trim((string) ($cfg[self::SETTING_BASE_URL] ?? ''));

        if ($baseUrl === '') {
            throw new DescribeUnavailableException(sprintf(
                'Не задан адрес витрины (%s) в настройках модуля — заполните его на странице «Синхронизация сателлита»',
                self::SETTING_BASE_URL
            ));
        }

        // Те же правила, что применит потребитель в ядре (SatelliteStorefront): схема http(s),
        // непустой хост, без CRLF/управляющих символов. Расходиться с ним нельзя — приняли бы
        // настройку, из которой ядро всё равно не соберёт ссылку.
        if ($this->hasControlChars($baseUrl)) {
            throw new DescribeUnavailableException('Адрес витрины содержит управляющие символы');
        }

        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new DescribeUnavailableException(
                'Адрес витрины должен быть абсолютным http(s)-адресом с хостом: ' . $baseUrl
            );
        }

        return rtrim($baseUrl, '/');
    }

    /**
     * Шаблон пути роута витрины с плейсхолдером `{slug}` — из живого роутера (учитывает стратегию
     * роутинга, префикс и слеш в конце, настроенные оператором).
     *
     * @return string|null null — шаблон не выводится (роута нет / плейсхолдер потерялся / путь
     *                     оказался не путём витрины). Для product это fatal, для category — норма.
     */
    private function urlPattern(string $routeName): ?string
    {
        try {
            $path = $this->routePath($routeName, self::SLUG_TOKEN);
        } catch (\Throwable $e) {
            // Роутер не поднят / роут не зарегистрирован — врать шаблоном не будем.
            return null;
        }

        if (strpos($path, self::SLUG_TOKEN) === false) {
            return null;
        }

        $path = str_replace(self::SLUG_TOKEN, self::SLUG_PLACEHOLDER, $path);
        if (strpos($path, '/') !== 0) {
            $path = '/' . ltrim($path, '/');
        }

        // Шаблон обязан быть ПУТЁМ витрины: абсолютный/протокол-относительный увёл бы ссылку фида
        // на чужой домен в обход base_url (то же правило fail-closed держит ядро на приёме).
        if (strpos($path, '//') === 0 || strpos($path, '://') !== false || preg_match('/\s/', $path) === 1) {
            return null;
        }

        return $path;
    }

    /**
     * Шов к генератору урлов витрины. Вынесен ради тестируемости: боевой Router::generateUrl —
     * статический и требует поднятого ServiceLocator/роутера (в приёмнике пинка он поднят).
     */
    protected function routePath(string $routeName, string $slugToken): string
    {
        return (string) Router::generateUrl($routeName, ['url' => $slugToken]);
    }

    /** Версия модуля из живого module.json (тот же файл читает ядро Okay). */
    private function moduleVersion(): string
    {
        $path = dirname(__DIR__) . '/Init/module.json';
        if (!is_file($path)) {
            return '';
        }

        $params = json_decode((string) file_get_contents($path), true);

        return is_array($params) ? (string) ($params['version'] ?? '') : '';
    }

    private function hasControlChars(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }
}
