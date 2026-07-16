<?php

namespace Tests\Modules\Format\CoreSync\Update;

use Okay\Modules\Format\CoreSync\Core\Update\SchemaMigrationCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Конвенция имён update_X_Y_Z (acceptance-3): обнаружение миграций каталогом обязано совпадать с
 * ядром ({@see \Okay\Core\Modules\Installer::getUpdateMethods}, регекс
 * ~^update_([0-9]+_[0-9]+_[0-9]+)~) — тогда ручная кнопка «Обновить» гоняет ТЕ ЖЕ методы.
 * Ядро проверено `Core\Modules\ModulesInstallerTest`; здесь фиксируем совпадение на уровне паттерна.
 */
class SchemaMigrationCatalogTest extends TestCase
{
    public function testDiscoverPicksUpdateMethodsByCoreRegexInVersionOrder(): void
    {
        $init = new class {
            public $calls = [];

            public function install(): void {}
            public function init(): void {}
            public function updateFoo(): void {} // не апгрейд-метод (нет X_Y_Z) — игнор
            public function update_1_0_1(): void { $this->calls[] = '1.0.1'; }
            public function update_1_0_11(): void { $this->calls[] = '1.0.11'; }
            public function update_1_2_0(): void { $this->calls[] = '1.2.0'; }
        };

        $migrations = SchemaMigrationCatalog::discover($init);

        $this->assertSame(
            ['1.0.1', '1.0.11', '1.2.0'],
            array_keys($migrations),
            'обнаружены ровно update_X_Y_Z, ключи = версии, порядок = по возрастанию (1.0.11 > 1.0.1)'
        );

        // Callable реально дёргает соответствующий метод Init.
        $migrations['1.2.0']();
        $this->assertSame(['1.2.0'], $init->calls, 'callable версии зовёт её метод update_X_Y_Z на Init');
    }

    public function testDiscoverIgnoresNonUpdateMethods(): void
    {
        $init = new class {
            public function install(): void {}
            public function init(): void {}
            public function updated_1_0_0(): void {} // не начинается с update_ + цифры — игнор
            public function update(): void {}          // без версии — игнор
        };

        $this->assertSame([], SchemaMigrationCatalog::discover($init), 'без валидного update_X_Y_Z — пусто');
    }
}
