<?php

namespace Okay\Modules\Format\CoreSync\Core\Update;

use Okay\Core\Config;
use Okay\Core\EntityFactory;
use Okay\Entities\ModulesEntity;
use Okay\Modules\Format\CoreSync\Init\Init;

/**
 * Каталог доступных схемных миграций модуля + целевая версия схемы (module.json на диске).
 *
 * targetVersion() = версия из живого Init/module.json (тот же источник, что читают swap/Describer).
 * migrations() = карта [версия => idempotent-callable] по методам update_X_Y_Z класса Init,
 *   обнаруженным рефлексией по ТОЙ ЖЕ регулярке, что ядро ({@see \Okay\Core\Modules\Installer}
 *   :getUpdateMethods, ~^update_([0-9]+_[0-9]+_[0-9]+)~) ⇒ конвенция совместима с ручной кнопкой
 *   «Обновить»: любой update_X_Y_Z виден обоим путям.
 *
 * ⚠ Мина same-process: рефлексия идёт по УЖЕ ЗАГРУЖЕННОМУ классу Init. В тике на СТАРТЕ прогона
 * (до swap этого же процесса) класс консистентен module.json; догон делает только следующий
 * (свежий) процесс, где загружен НОВЫЙ Init — построение каталога тут безопасно.
 */
class SchemaMigrationCatalog
{
    /** Регекс имени апгрейд-метода — ВЕРБАТИМ из ядра (Installer::getUpdateMethods). */
    private const UPDATE_METHOD_PATTERN = '~^update_([0-9]+_[0-9]+_[0-9]+)~';

    /** @var EntityFactory */
    private $entityFactory;
    /** @var Config */
    private $config;

    public function __construct(EntityFactory $entityFactory, Config $config)
    {
        $this->entityFactory = $entityFactory;
        $this->config = $config;
    }

    /** Целевая версия схемы = версия живого module.json (null — нет/битый файл). */
    public function targetVersion(): ?string
    {
        $path = $this->root() . '/Okay/Modules/Format/CoreSync/Init/module.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) && isset($data['version']) ? (string) $data['version'] : null;
    }

    /** @return array<string, callable():void> версия => миграция (по возрастанию версии) */
    public function migrations(): array
    {
        $init = $this->buildInit();

        return $init === null ? [] : self::discover($init);
    }

    /**
     * Чистая рефлексия update_X_Y_Z с объекта Init → [версия => callable]. Public и статична для
     * прямого теста конвенции имён (совместимость с Installer::getUpdateMethods) без БД/построения Init.
     *
     * @return array<string, callable():void>
     */
    public static function discover(object $init): array
    {
        $migrations = [];
        foreach ((new \ReflectionClass($init))->getMethods() as $method) {
            $matches = [];
            if (preg_match(self::UPDATE_METHOD_PATTERN, $method->name, $matches)) {
                $version = str_replace('_', '.', $matches[1]);
                $name = $method->name;
                $migrations[$version] = static function () use ($init, $name): void {
                    $init->{$name}();
                };
            }
        }
        uksort($migrations, 'version_compare');

        return $migrations;
    }

    /** Построить живой Init модуля (moduleId из __modules). null — модуль не найден в БД. */
    protected function buildInit(): ?object
    {
        /** @var ModulesEntity $modulesEntity */
        $modulesEntity = $this->entityFactory->get(ModulesEntity::class);
        $row = $modulesEntity->getByVendorModuleName('Format', 'CoreSync');
        if (!is_object($row) || !isset($row->id)) {
            return null;
        }

        return new Init((int) $row->id, 'Format', 'CoreSync');
    }

    private function root(): string
    {
        return rtrim((string) $this->config->get('root_dir'), '/\\');
    }
}
