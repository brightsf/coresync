<?php

namespace Okay\Modules\Format\CoreSync\Init;

use Okay\Core\Config;
use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\AbstractInit;
use Okay\Core\Modules\EntityField;
use Okay\Core\QueryFactory;
use Okay\Core\Scheduler\Schedule;
use Okay\Core\ServiceLocator;
use Okay\Core\Settings;
use Okay\Entities\BrandsEntity;
use Okay\Entities\CategoriesEntity;
use Okay\Entities\FeaturesEntity;
use Okay\Helpers\OrdersHelper;
use Okay\Modules\Format\CoreSync\Core\Apply\VariantMapBackfill;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\SyncRunner;
use Okay\Modules\Format\CoreSync\Core\Update\SchemaMarker;
use Okay\Modules\Format\CoreSync\Core\Update\SchemaMigration;
use Okay\Modules\Format\CoreSync\Core\Update\SchemaUpgrader;
use Okay\Modules\Format\CoreSync\Extenders\OrdersHelperExtender;

class Init extends AbstractInit
{
    const PERMISSION = 'format_coresync';

    const MAP_TABLE        = '__format__coresync_map';
    const JOBS_TABLE       = '__format__coresync_jobs';
    const JOB_FILES_TABLE  = '__format__coresync_job_files';
    const IMAGES_TABLE     = '__format__coresync_images';
    const CATEGORY_IMAGES_TABLE = '__format__coresync_category_images';
    const ORDERS_OUT_TABLE = '__format__coresync_orders_out';
    const DICTIONARY_MARKER_FIELD = 'coresync_external_id';

    /**
     * Версия схемы модуля, начиная с которой колонка {@see self::DICTIONARY_MARKER_FIELD} есть
     * в `__categories`/`__brands` (её добавляет {@see self::update_1_4_0}). Раньше неё
     * runtime-регистрация поля запрещена: ядро кладёт колонку в SELECT словарей, и до догона
     * схемы каждый запрос падает 1054 Unknown column.
     */
    const DICTIONARY_IDENTITY_SCHEMA_VERSION = '1.4.0';

    /**
     * Версия схемы модуля, начиная с которой колонка {@see self::DICTIONARY_MARKER_FIELD} есть
     * в `__features` (её добавляет {@see self::update_1_6_2}). Порог у характеристик СВОЙ:
     * витрина, догнавшая схему до 1.4.0…1.6.1, имеет колонку у категорий/брендов, но НЕ у
     * характеристик — общий порог зарегистрировал бы поле, которого в таблице ещё нет, и каждый
     * запрос характеристик падал бы 1054 Unknown column до догона схемы тиком.
     */
    const FEATURE_IDENTITY_SCHEMA_VERSION = '1.6.2';

    public function install()
    {
        // ПЕРВЫМ шагом: durable-исход схемы принадлежит ПРОШЛОЙ установке модуля. Он переживает
        // «Удалить» в админке (кнопка стирает строку из `__modules`, но не строки `__settings`), а
        // SchemaUpgrader считает `upgraded` с `to == target` признаком сделанного self-heal. Не
        // сбросив его, витрина, уже стоявшая на версии X, после переустановки той же версии X с
        // падением install() посреди осталась бы с applied == target == X и чужим «сделано» —
        // self-heal был бы подавлен молча и навсегда (D-CORESYNC-SCHEMA-OUTCOME-SURVIVES-REINSTALL).
        $this->resetSchemaOutcome($this->installSettings());

        $this->setBackendMainController('CoreSyncAdmin');
        $this->installDictionaryIdentityFields();

        $this->installTables($this->installSchemaMigration());

        // Миграционный досев variant-строк карты по существующим товарам (M2→M3, §0.1). No-op на свежей.
        /** @var EntityFactory $entityFactory */
        $entityFactory = ServiceLocator::getInstance()->getService(EntityFactory::class);
        (new VariantMapBackfill($entityFactory))->run();
    }

    /**
     * Шов получения настроек для install() — по образцу {@see self::installSchemaMigration()}:
     * единственное место, где сброс исхода схемы ходит в ServiceLocator (в php74-сьюте он бросает,
     * поэтому добыча сервиса отделена от самого сброса).
     */
    protected function installSettings(): Settings
    {
        /** @var Settings $settings */
        $settings = ServiceLocator::getInstance()->getService(Settings::class);

        return $settings;
    }

    /**
     * Сброс durable-исхода схемы на старте установки: новая установка ничего про свою схему ещё не
     * знает, поэтому исход прошлой не должен считаться её собственным. Пустое значение читается
     * {@see SchemaUpgrader::selfHealRecorded()} как «исхода нет» → первый же тик сделает self-heal
     * и запишет свой исход. Отдельного settings-ключа не заводим (решение планировщика).
     */
    protected function resetSchemaOutcome(Settings $settings): void
    {
        $settings->set(SchemaUpgrader::SETTINGS_SCHEMA_STATUS_KEY, null);
    }

    /**
     * Шов получения примитива схемы для install(): единственное место, где путь установки таблиц
     * ходит в ServiceLocator. Вынесен отдельно, чтобы тесты фикс-состава полей могли подменить
     * добычу живых Database/QueryFactory/Config так же, как они уже глушат остальные не-схемные шаги.
     */
    protected function installSchemaMigration(): SchemaMigration
    {
        $sl = ServiceLocator::getInstance();
        /** @var Database $db */
        $db = $sl->getService(Database::class);
        /** @var QueryFactory $queryFactory */
        $queryFactory = $sl->getService(QueryFactory::class);
        /** @var Config $config */
        $config = $sl->getService(Config::class);

        return new SchemaMigration($db, $queryFactory, $config);
    }

    /**
     * Создание таблиц модуля — идемпотентно, вынесено примитивом ради прямого теста контракта.
     *
     * ⚠ Зачем guard. Кнопка «Удалить» в админке Okay стирает только СТРОКУ модуля из `__modules`,
     * таблицы остаются. Следующий «Установить» снова зовёт install(), а фреймворковый
     * migrateCustomTable → EntityMigrator::createTable → SqlPresentor::createTableQuery шлёт голый
     * `CREATE TABLE` без `IF NOT EXISTS`. Шесть ошибок «1050 Table already exists» ядро глотает:
     * оператор видит «установилось», а лог витрины красный. Ядро модулю не принадлежит — проверяем
     * наличие таблицы на своей стороне. Пропуск существующей таблицы — норма, поэтому молча.
     */
    protected function installTables(SchemaMigration $migration): void
    {
        // Карта владения sync'а (external_id ядра → local_id витрины).
        if (!$migration->tableExists(self::MAP_TABLE)) {
            $mapExternalId = (new EntityField('external_id'))->setTypeVarchar(64, false);
            $this->migrateCustomTable(self::MAP_TABLE, [
                (new EntityField('id'))->setTypeInt(11, false)->setAutoIncrement(),
                (new EntityField('entity_type'))->setTypeVarchar(16, false)->setIndexUnique(null, $mapExternalId),
                $mapExternalId,
                (new EntityField('local_id'))->setTypeInt(11, true),
                (new EntityField('applied_hash'))->setTypeVarchar(64, true),
                (new EntityField('image_state'))->setTypeVarchar(16, true),
            ]);
        }

        // Прогоны синхронизации (прогресс/статус/отмена/reconnect).
        if (!$migration->tableExists(self::JOBS_TABLE)) {
            $this->migrateCustomTable(self::JOBS_TABLE, [
                (new EntityField('id'))->setTypeInt(11, false)->setAutoIncrement(),
                (new EntityField('status'))->setTypeEnum(Contract::JOB_STATUSES, false)
                    ->setDefault(Contract::STATUS_CREATED)->setIndex(),
                (new EntityField('snapshot_version'))->setTypeInt(11, true)->setIndex(),
                (new EntityField('phase'))->setTypeVarchar(32, true),
                (new EntityField('files_total'))->setTypeInt(11, false)->setDefault(0),
                (new EntityField('files_done'))->setTypeInt(11, false)->setDefault(0),
                (new EntityField('bytes_done'))->setTypeInt(20, false)->setDefault(0),
                (new EntityField('error_message'))->setTypeText()->setNullable(),
                (new EntityField('cancel_requested'))->setTypeTinyInt(1, false)->setDefault(0),
                (new EntityField('started_at'))->setTypeDatetime(true),
                (new EntityField('finished_at'))->setTypeDatetime(true),
                (new EntityField('created'))->setTypeTimestamp(false),
                (new EntityField('updated'))->setTypeTimestamp(false),
            ]);
        }

        // Чекпоинт файлов набора (resume/докачка).
        if (!$migration->tableExists(self::JOB_FILES_TABLE)) {
            $this->migrateCustomTable(self::JOB_FILES_TABLE, [
                (new EntityField('id'))->setTypeInt(11, false)->setAutoIncrement(),
                (new EntityField('job_id'))->setTypeInt(11, false)->setIndex(),
                (new EntityField('name'))->setTypeVarchar(255, false),
                (new EntityField('sha256_expected'))->setTypeVarchar(64, false),
                (new EntityField('bytes'))->setTypeInt(20, false)->setDefault(0),
                (new EntityField('status'))->setTypeEnum(Contract::FILE_STATUSES, false)
                    ->setDefault(Contract::FILE_PENDING)->setIndex(),
            ]);
        }

        // Durable-список картинок товаров карты (вне staging — переживает чистку ФС, M3 §0.2).
        if (!$migration->tableExists(self::IMAGES_TABLE)) {
            $imgProductExternal = (new EntityField('product_external_id'))->setTypeVarchar(64, false)->setIndex();
            $this->migrateCustomTable(self::IMAGES_TABLE, [
                (new EntityField('id'))->setTypeInt(11, false)->setAutoIncrement(),
                $imgProductExternal,
                (new EntityField('product_local_id'))->setTypeInt(11, true)->setIndex(),
                (new EntityField('url'))->setTypeText(),
                (new EntityField('url_hash'))->setTypeVarchar(64, false),
                (new EntityField('sort'))->setTypeInt(11, false)->setDefault(0),
                (new EntityField('state'))->setTypeEnum(Contract::IMAGE_STATES, false)
                    ->setDefault(Contract::IMAGE_STATE_PENDING)->setIndex(),
                (new EntityField('attempts'))->setTypeInt(11, false)->setDefault(0),
                (new EntityField('filename'))->setTypeVarchar(255, true),
                (new EntityField('image_id'))->setTypeInt(11, true),
                // sha256 СОДЕРЖИМОГО объекта по обещанию ядра. Пусто = «усыновлять нельзя, качать».
                (new EntityField(Contract::IMAGE_CONTENT_SHA256_FIELD))->setTypeVarchar(64, true)->setDefault(null),
                (new EntityField('error_code'))->setTypeVarchar(64, true),
            ]);
        }

        // v2: one durable language-neutral image descriptor per core category.
        if (!$migration->tableExists(self::CATEGORY_IMAGES_TABLE)) {
            $categoryImageExternal = (new EntityField('category_external_id'))->setTypeVarchar(64, false)->setIndexUnique();
            $this->migrateCustomTable(self::CATEGORY_IMAGES_TABLE, [
                (new EntityField('id'))->setTypeInt(11, false)->setAutoIncrement(),
                $categoryImageExternal,
                (new EntityField('category_local_id'))->setTypeInt(11, false)->setIndex(),
                (new EntityField('source_instance'))->setTypeVarchar(64, false)->setIndex(),
                (new EntityField('source_id'))->setTypeVarchar(128, false),
                (new EntityField('url'))->setTypeText(),
                (new EntityField('sha256'))->setTypeVarchar(64, false),
                (new EntityField('mime'))->setTypeVarchar(32, false),
                (new EntityField('bytes'))->setTypeInt(11, false),
                (new EntityField('state'))->setTypeEnum(Contract::IMAGE_STATES, false)
                    ->setDefault(Contract::IMAGE_STATE_PENDING)->setIndex(),
                (new EntityField('attempts'))->setTypeInt(11, false)->setDefault(0),
                (new EntityField('filename'))->setTypeVarchar(255, true),
                (new EntityField('error_code'))->setTypeVarchar(64, true),
            ]);
        }

        // Журнал доставки заказов/заявок ядру (FEAT-ORD-M): entity (order|request) + local_id
        // (id в __orders/__callbacks), delivered_at/acked_at. Уникальность (entity, local_id) —
        // идемпотентная фиксация выдачи. Отдельная таблица (не __format__coresync_map).
        if (!$migration->tableExists(self::ORDERS_OUT_TABLE)) {
            $outLocalId = (new EntityField('local_id'))->setTypeInt(11, false);
            $this->migrateCustomTable(self::ORDERS_OUT_TABLE, [
                (new EntityField('id'))->setTypeInt(11, false)->setAutoIncrement(),
                (new EntityField('entity'))->setTypeVarchar(16, false)->setIndexUnique(null, $outLocalId),
                $outLocalId,
                (new EntityField('delivered_at'))->setTypeDatetime(true),
                (new EntityField('acked_at'))->setTypeDatetime(true),
            ]);
        }
    }

    /**
     * Апгрейд схемы 1.2.0 → 1.3.0 (FEAT-ORD-M): завести журнал доставки на УЖЕ установленных
     * витринах. Догоняется на старте тика ({@see SchemaUpgrader}, конвенция update_X_Y_Z волны 5) ИЛИ
     * ручной кнопкой «Обновить». Fail-closed через {@see SchemaMigration} (raw Database::query()===false
     * → бросок, маркер версии НЕ поднимается). Идемпотентно: CREATE TABLE IF NOT EXISTS — повтор = no-op.
     */
    public function update_1_3_0(): void
    {
        $sl = ServiceLocator::getInstance();
        /** @var Database $db */
        $db = $sl->getService(Database::class);
        /** @var QueryFactory $queryFactory */
        $queryFactory = $sl->getService(QueryFactory::class);

        $migration = new SchemaMigration($db, $queryFactory);
        $migration->execute(
            'CREATE TABLE IF NOT EXISTS `' . self::ORDERS_OUT_TABLE . '` ('
            . '`id` INT(11) NOT NULL AUTO_INCREMENT, '
            . '`entity` VARCHAR(16) NOT NULL, '
            . '`local_id` INT(11) NOT NULL, '
            . '`delivered_at` DATETIME NULL DEFAULT NULL, '
            . '`acked_at` DATETIME NULL DEFAULT NULL, '
            . 'PRIMARY KEY (`id`), '
            . 'UNIQUE KEY `entity_local_id` (`entity`, `local_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8'
        );
    }

    /**
     * Апгрейд схемы 1.3.0 → 1.4.0: durable identity категорий/брендов. Каждый ALTER идёт через
     * fail-closed/idempotent SchemaMigration; частичный успех безопасно догоняется следующим тиком.
     */
    public function update_1_4_0(): void
    {
        $sl = ServiceLocator::getInstance();
        /** @var Database $db */
        $db = $sl->getService(Database::class);
        /** @var QueryFactory $queryFactory */
        $queryFactory = $sl->getService(QueryFactory::class);

        $this->upgradeDictionaryIdentityFields(new SchemaMigration($db, $queryFactory));
    }

    /** Upgrade 1.4.0 -> 1.5.0: durable v2 category image state. */
    public function update_1_5_0(): void
    {
        $sl = ServiceLocator::getInstance();
        /** @var Database $db */
        $db = $sl->getService(Database::class);
        /** @var QueryFactory $queryFactory */
        $queryFactory = $sl->getService(QueryFactory::class);

        $this->upgradeCategoryImagesTable(new SchemaMigration($db, $queryFactory));
    }

    /**
     * Апгрейд схемы 1.5.4 → 1.5.5: content-хеш картинки товара в durable-таблице. Едет ТЕМ ЖЕ
     * механизмом, что остальные схемные шаги (fail-closed/идемпотентный {@see SchemaMigration},
     * догон на старте тика через {@see \Okay\Modules\Format\CoreSync\Core\Update\SchemaUpgrader}).
     */
    public function update_1_5_5(): void
    {
        $sl = ServiceLocator::getInstance();
        /** @var Database $db */
        $db = $sl->getService(Database::class);
        /** @var QueryFactory $queryFactory */
        $queryFactory = $sl->getService(QueryFactory::class);

        $this->upgradeProductImagesContentHash(new SchemaMigration($db, $queryFactory));
    }

    /** Upgrade exact target 1.5.6: durable product-image download diagnostics. */
    public function update_1_5_6(): void
    {
        $sl = ServiceLocator::getInstance();
        /** @var Database $db */
        $db = $sl->getService(Database::class);
        /** @var QueryFactory $queryFactory */
        $queryFactory = $sl->getService(QueryFactory::class);

        $this->upgradeProductImagesErrorCode(new SchemaMigration($db, $queryFactory));
    }

    /**
     * Upgrade exact target 1.5.7: same idempotent error_code primitive as 1.5.6. Tag okay-v1.5.6 was cut
     * BEFORE update_1_5_6() reached canon (2026-08-19 vs 2026-08-25), so a storefront installed from that
     * tag holds applied=1.5.6 without the column; SchemaUpgrader runs only migrations > applied, hence the
     * self-heal is re-declared under the first version that ships it. addColumnIfMissing keeps it a no-op
     * where the column already exists.
     */
    public function update_1_5_7(): void
    {
        $sl = ServiceLocator::getInstance();
        /** @var Database $db */
        $db = $sl->getService(Database::class);
        /** @var QueryFactory $queryFactory */
        $queryFactory = $sl->getService(QueryFactory::class);

        $this->upgradeProductImagesErrorCode(new SchemaMigration($db, $queryFactory));
    }

    /** Idempotent upgrade primitive, split out so the exact DDL contract is directly testable. */
    protected function upgradeProductImagesContentHash(SchemaMigration $migration): void
    {
        $migration->addColumnIfMissing(
            self::IMAGES_TABLE,
            Contract::IMAGE_CONTENT_SHA256_FIELD,
            'VARCHAR(64) NULL DEFAULT NULL'
        );
    }

    /** Idempotent exact-target self-heal for installations already marked as module 1.5.6. */
    protected function upgradeProductImagesErrorCode(SchemaMigration $migration): void
    {
        $migration->addColumnIfMissing(
            self::IMAGES_TABLE,
            'error_code',
            'VARCHAR(64) NULL DEFAULT NULL'
        );
    }

    /**
     * Апгрейд схемы 1.6.1 → 1.6.2: durable identity характеристик. Тот же fail-closed/идемпотентный
     * примитив, что у словарей 1.4.0; частичный успех безопасно догоняется следующим тиком.
     */
    public function update_1_6_2(): void
    {
        $sl = ServiceLocator::getInstance();
        /** @var Database $db */
        $db = $sl->getService(Database::class);
        /** @var QueryFactory $queryFactory */
        $queryFactory = $sl->getService(QueryFactory::class);

        $this->upgradeFeatureIdentityFields(new SchemaMigration($db, $queryFactory));
    }

    /** Idempotent upgrade primitive, split out so the exact DDL contract is directly testable. */
    protected function upgradeCategoryImagesTable(SchemaMigration $migration): void
    {
        $migration->execute(
            'CREATE TABLE IF NOT EXISTS `' . self::CATEGORY_IMAGES_TABLE . '` ('
            . '`id` INT(11) NOT NULL AUTO_INCREMENT, '
            . '`category_external_id` VARCHAR(64) NOT NULL, '
            . '`category_local_id` INT(11) NOT NULL, '
            . '`source_instance` VARCHAR(64) NOT NULL, '
            . '`source_id` VARCHAR(128) NOT NULL, '
            . '`url` TEXT NOT NULL, '
            . '`sha256` VARCHAR(64) NOT NULL, '
            . '`mime` VARCHAR(32) NOT NULL, '
            . '`bytes` INT(11) NOT NULL, '
            . '`state` ENUM(\'pending\',\'done\',\'failed\') NOT NULL DEFAULT \'pending\', '
            . '`attempts` INT(11) NOT NULL DEFAULT 0, '
            . '`filename` VARCHAR(255) NULL DEFAULT NULL, '
            . '`error_code` VARCHAR(64) NULL DEFAULT NULL, '
            . 'PRIMARY KEY (`id`), '
            . 'UNIQUE KEY `category_external_id` (`category_external_id`), '
            . 'KEY `category_local_id` (`category_local_id`), '
            . 'KEY `source_instance` (`source_instance`), '
            . 'KEY `state` (`state`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8'
        );
    }

    public function init()
    {
        $this->registerDictionaryIdentityFields();
        $this->registerBackendController('CoreSyncAdmin');
        $this->addBackendControllerPermission('CoreSyncAdmin', self::PERMISSION);

        $this->extendBackendMenu('left_catalog', [
            'format_coresync__left_catalog__title' => ['CoreSyncAdmin'],
        ]);

        // Крон: свежесть «часы» (каждые 30 минут), без наложений.
        $this->registerSchedule(
            (new Schedule([SyncRunner::class, 'run']))
                ->name('CoreSync snapshot pull')
                ->time('*/30 * * * *')
                ->overlap(false)
                ->timeout(3600)
        );

        // Хук создания заказа (FEAT-ORD-M §E): будим ядро пинком «есть новые заказы». Queue-extension
        // (сайд-эффект, возврат не меняем); фаерится после полного создания заказа. Best-effort —
        // недоступное ядро не ломает оформление (крон ядра всё равно догонит).
        $this->registerQueueExtension(
            [OrdersHelper::class, 'finalCreateOrderProcedure'],
            [OrdersHelperExtender::class, 'extendFinalCreateOrderProcedure']
        );
    }

    /** Fresh-install path для module-owned identity словарей. */
    protected function installDictionaryIdentityFields(): void
    {
        $this->migrateEntityField(
            CategoriesEntity::class,
            (new EntityField(self::DICTIONARY_MARKER_FIELD))->setTypeVarchar(64, true)->setDefault(null)
        );
        $this->migrateEntityField(
            BrandsEntity::class,
            (new EntityField(self::DICTIONARY_MARKER_FIELD))->setTypeVarchar(64, true)->setDefault(null)
        );
        $this->migrateEntityField(
            FeaturesEntity::class,
            (new EntityField(self::DICTIONARY_MARKER_FIELD))->setTypeVarchar(64, true)->setDefault(null)
        );
    }

    /**
     * Runtime-регистрация полей в core Entities (не language fields) - ТОЛЬКО когда применённая
     * версия схемы уже содержит колонку (>= {@see self::DICTIONARY_IDENTITY_SCHEMA_VERSION}).
     *
     * Окно, которое это закрывает: self-updater ({@see \Okay\Modules\Format\CoreSync\Core\Update\ModuleSwapper})
     * свапает файлы в КОНЦЕ тика, а догон схемы идёт на СТАРТЕ следующего - между ними новый init()
     * уже знает про поле, а колонки в БД ещё нет. Fail-closed: версия не прочиталась (null) -
     * не регистрируем; лучше тик без маркера словарей, чем 1054 Unknown column в каждом запросе
     * категорий. Лога здесь нет: пропуск - норма переходного окна, а init() исполняется на каждом
     * запросе витрины.
     */
    protected function registerDictionaryIdentityFields(): void
    {
        $appliedVersion = $this->dictionaryIdentitySchemaVersion();
        if ($appliedVersion === null
            || version_compare($appliedVersion, self::DICTIONARY_IDENTITY_SCHEMA_VERSION, '<')
        ) {
            return;
        }

        $this->registerEntityField(CategoriesEntity::class, self::DICTIONARY_MARKER_FIELD);
        $this->registerEntityField(BrandsEntity::class, self::DICTIONARY_MARKER_FIELD);

        // Свой порог: колонку характеристик добавляет только update_1_6_2 (см. константу выше).
        if (version_compare($appliedVersion, self::FEATURE_IDENTITY_SCHEMA_VERSION, '<')) {
            return;
        }

        $this->registerEntityField(FeaturesEntity::class, self::DICTIONARY_MARKER_FIELD);
    }

    /**
     * Шов «какая версия схемы модуля реально применена». Единственный честный источник -
     * durable-маркер `__modules.version` ({@see SchemaMarker::appliedVersion}): module.json после
     * свапа файлов уже новый, а маркер поднимается только после успешного догона схемы.
     *
     * Вынесен отдельно по образцу {@see self::installSchemaMigration()}: это единственное место,
     * где путь регистрации ходит в ServiceLocator, и тесты подменяют версию переопределением шва.
     * Кэша/статики намеренно нет - один SELECT по таблице модулей на запрос, зато после догона
     * схемы поле поднимается со следующего же запроса, без ожидания сброса кэша.
     */
    protected function dictionaryIdentitySchemaVersion(): ?string
    {
        /** @var EntityFactory $entityFactory */
        $entityFactory = ServiceLocator::getInstance()->getService(EntityFactory::class);

        return (new SchemaMarker($entityFactory))->appliedVersion();
    }

    /** Upgrade-path, вынесенный отдельно для прямого теста общего DDL-контракта install/update. */
    protected function upgradeDictionaryIdentityFields(SchemaMigration $migration): void
    {
        $ddl = 'VARCHAR(64) NULL DEFAULT NULL';
        $migration->addColumnIfMissing('__categories', self::DICTIONARY_MARKER_FIELD, $ddl);
        $migration->addColumnIfMissing('__brands', self::DICTIONARY_MARKER_FIELD, $ddl);
    }

    /** Upgrade-path идентичности характеристик — тем же примитивом, что и словари 1.4.0. */
    protected function upgradeFeatureIdentityFields(SchemaMigration $migration): void
    {
        $migration->addColumnIfMissing('__features', self::DICTIONARY_MARKER_FIELD, 'VARCHAR(64) NULL DEFAULT NULL');
    }
}
