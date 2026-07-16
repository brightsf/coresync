<?php

namespace Okay\Modules\Format\CoreSync\Core\Update;

use Okay\Core\EntityFactory;
use Okay\Entities\ModulesEntity;

/**
 * Durable-маркер «какая версия схемы модуля применена» = ЧЕСТНЫЙ modules.version ядра
 * (таблица __modules), а НЕ отдельный Settings-ключ. Обоснование выбора (Scope B брифа):
 *  - единый источник истины: весь модульный слой Okay уже трактует modules.version как
 *    «установленная/применённая версия» (install() ставит, Installer::update() поднимает);
 *  - унифицирует два триггера апгрейда — тик ({@see SchemaUpgrader}) и ручную кнопку «Обновить»
 *    ядра ({@see \Okay\Core\Modules\Installer::update}): оба читают/пишут ОДИН маркер, дивергенции
 *    нет, после ручного апгрейда тик не видит разрыва и не гоняет миграции повторно;
 *  - fail-closed сохраняется на обоих путях: методы update_X_Y_Z бросают при провале DDL
 *    ({@see SchemaMigration}), и цикл Installer::update пробрасывает бросок ДО своей строки bump'а
 *    modules.version — маркер не поднимается ни на одном пути.
 *
 * Self-updater ({@see ModuleSwapper}) свапает ТОЛЬКО файлы и modules.version НЕ трогает ⇒ после
 * swap module.json = новая версия, modules.version = старая: этот разрыв и есть сигнал
 * «схему надо догнать».
 */
class SchemaMarker
{
    /** @var EntityFactory */
    private $entityFactory;
    /** @var string */
    private $vendor;
    /** @var string */
    private $moduleName;

    public function __construct(EntityFactory $entityFactory, string $vendor = 'Format', string $moduleName = 'CoreSync')
    {
        $this->entityFactory = $entityFactory;
        $this->vendor = $vendor;
        $this->moduleName = $moduleName;
    }

    /** Применённая версия схемы = modules.version модуля (null — строки нет / поле битое). */
    public function appliedVersion(): ?string
    {
        $row = $this->row();

        return $row !== null && isset($row->version) ? (string) $row->version : null;
    }

    /**
     * Поднять маркер до $version (bump modules.version). false — строку не нашли/ошибка записи;
     * зовущий ({@see SchemaUpgrader}) трактует false как провал фиксации (маркер не поднят).
     */
    public function markApplied(string $version): bool
    {
        $row = $this->row();
        if ($row === null || !isset($row->id)) {
            return false;
        }

        return (bool) $this->modulesEntity()->update((int) $row->id, ['version' => $version]);
    }

    /** @return object|null строка __modules модуля */
    protected function row()
    {
        $row = $this->modulesEntity()->getByVendorModuleName($this->vendor, $this->moduleName);

        return is_object($row) ? $row : null;
    }

    private function modulesEntity(): ModulesEntity
    {
        /** @var ModulesEntity $entity */
        $entity = $this->entityFactory->get(ModulesEntity::class);

        return $entity;
    }
}
