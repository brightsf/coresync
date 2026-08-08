<?php

namespace Tests\Modules\Format\CoreSync;

use Okay\Modules\Format\CoreSync\Core\Contract;
use PHPUnit\Framework\TestCase;

/**
 * C. Чек-лист готовности подключения (stage-coresync-ui-diag). Чистая функция от массива настроек:
 * без сети, без БД — оператор должен увидеть незакрытые пункты ДО того, как упрётся в отказ
 * (пустая церемония подключения, fail-closed на применении и т.п.), а не постфактум из текста
 * подсказки под полем.
 *
 * Каждый пункт — это ПОСЛЕДСТВИЕ незаполненного поля, а не название поля (решение брифа §C).
 */
class ContractReadinessTest extends TestCase
{
    /** @return array<string, mixed> полный, «всё заполнено» набор настроек */
    private function completeConfig(): array
    {
        return [
            'core_url' => 'https://core.example',
            'channel_code' => '42',
            'token' => 'secret',
            'source_instance' => 'grundfos',
            'storefront_base_url' => 'https://shop.example',
            'currency_map' => ['UAH' => 1],
            'enabled' => 1,
        ];
    }

    /** Полностью заполненные настройки → пустой список (ничего не занимает место на странице). */
    public function testCompleteConfigHasNoOutstandingItems(): void
    {
        $this->assertSame([], Contract::readiness($this->completeConfig()));
    }

    /**
     * §C, поле «Адрес витрины». Пункт обязан называть ПОСЛЕДСТВИЕ (церемония подключения ядра не
     * отвечает), а не имя поля — буквальное требование брифа.
     *
     * KILL-ПРОБА §C (заявлена брифом): убрать проверку пустого storefront_base_url из readiness() →
     * тест краснеет (пункт для поля с реальным значением исчезает из списка).
     */
    public function testEmptyStorefrontBaseUrlNamesConsequenceNotFieldName(): void
    {
        $cfg = $this->completeConfig();
        $cfg['storefront_base_url'] = '';

        $items = Contract::readiness($cfg);
        $match = $this->findByField($items, 'storefront_base_url');

        $this->assertNotNull($match, 'пустой адрес витрины обязан попасть в чек-лист');
        $this->assertStringContainsStringIgnoringCase('церемони', $match['message']);
        $this->assertStringNotContainsStringIgnoringCase('заполните', $match['message'], 'пункт формулируется последствием, а не командой заполнить поле');
    }

    public function testEmptyCoreUrlIsListed(): void
    {
        $cfg = $this->completeConfig();
        $cfg['core_url'] = '';

        $this->assertNotNull($this->findByField(Contract::readiness($cfg), 'core_url'));
    }

    public function testEmptyChannelCodeIsListed(): void
    {
        $cfg = $this->completeConfig();
        $cfg['channel_code'] = '';

        $this->assertNotNull($this->findByField(Contract::readiness($cfg), 'channel_code'));
    }

    public function testEmptyTokenIsListed(): void
    {
        $cfg = $this->completeConfig();
        $cfg['token'] = '';

        $this->assertNotNull($this->findByField(Contract::readiness($cfg), 'token'));
    }

    public function testEmptySourceInstanceIsListed(): void
    {
        $cfg = $this->completeConfig();
        $cfg['source_instance'] = '';

        $this->assertNotNull($this->findByField(Contract::readiness($cfg), 'source_instance'));
    }

    /** Пусто = ни одной строки соответствия валют — fail-closed на применении (M2). */
    public function testEmptyCurrencyMapIsListed(): void
    {
        $cfg = $this->completeConfig();
        $cfg['currency_map'] = [];

        $match = $this->findByField(Contract::readiness($cfg), 'currency_map');
        $this->assertNotNull($match);
        $this->assertStringContainsStringIgnoringCase('валют', $match['message']);
    }

    /** Снятая галка «Модуль включён» — тот же предикат, что читает раннер/кнопки (Contract::isEnabled). */
    public function testDisabledModuleIsListed(): void
    {
        $cfg = $this->completeConfig();
        $cfg['enabled'] = 0;

        $match = $this->findByField(Contract::readiness($cfg), 'enabled');
        $this->assertNotNull($match);
        $this->assertStringContainsStringIgnoringCase('выключен', $match['message']);
    }

    /** Отсутствующий ключ enabled = включено (Contract::isEnabled) — тот же дефолт и здесь. */
    public function testMissingEnabledKeyIsNotListed(): void
    {
        $cfg = $this->completeConfig();
        unset($cfg['enabled']);

        $this->assertNull($this->findByField(Contract::readiness($cfg), 'enabled'));
    }

    /**
     * @param array<int, array{field:string, message:string}> $items
     * @return array{field:string, message:string}|null
     */
    private function findByField(array $items, string $field): ?array
    {
        foreach ($items as $item) {
            if (($item['field'] ?? null) === $field) {
                return $item;
            }
        }

        return null;
    }
}
