<?php

namespace Okay\Modules\Format\CoreSync\Entities;

use Okay\Core\Entity\Entity;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Okay\Modules\Format\CoreSync\Core\Exceptions\CoreSyncException;

/**
 * Карта владения sync'а: границы того, что модуль вправе трогать в витрине.
 * (external_id ядра → local_id Okay; applied_hash — skip-инвариант; image_state — coarse-флаг картинок.)
 */
class CoreSyncMapEntity extends Entity
{
    protected static $fields = [
        'id',
        'entity_type',
        'external_id',
        'local_id',
        'applied_hash',
        'image_state',
    ];

    protected static $table = '__format__coresync_map';
    protected static $tableAlias = 'csm';
    protected static $defaultOrderFields = [
        'id ASC',
    ];

    /**
     * «Полное перепринятие» (лечение дрифта): applied_hash строк-СУЩНОСТЕЙ → NULL, image_state товаров
     * → pending. Следующий прогон переприменяет всё той же версией (raw-update — не дёргаем
     * chain-extensions на десятках тысяч строк).
     *
     * Метки bind (entity_type=bind_marker) — состояние ФАЗЫ, а не применённое значение, и под сброс НЕ
     * попадают: applied_hash у них несёт флаг. Сброс по всей таблице гасил активную метку прерванного
     * bind (force-reapply при прерванном bind → частичная карта → следующий прогон уходит в full →
     * ДУБЛИ каталога, ровно то, от чего метка стоит) и отметку «bind выполнен» (→ возврат петли
     * D-SAT-BIND-LOOP-NEVER-APPLIES). Фильтр — замкнутый allow-list Contract::ENTITY_TYPES: новый тип
     * сущности карты обязан попасть в него, чтобы перепринимался.
     */
    public function resetForReapply(): void
    {
        $resetHash = $this->queryFactory->newUpdate();
        $resetHash->table(self::getTable())
            ->set('applied_hash', null) // → SET applied_hash = NULL
            ->where('entity_type IN (:entity_types)')
            ->bindValue('entity_types', Contract::ENTITY_TYPES);

        $resetImages = $this->queryFactory->newUpdate();
        $resetImages->table(self::getTable())
            ->cols(['image_state' => Contract::IMAGE_STATE_PENDING]) // bound-значение
            ->where('entity_type = :type')
            ->where('image_state IS NOT NULL')
            ->bindValue('type', Contract::ENTITY_PRODUCT);

        // Атомарно (D-OKAY-DB-NO-TX): оба UPDATE под транзакцией — частичное перепринятие
        // (сброшенные хэши без сброшенных image_state) невозможно. query() при ошибке возвращает
        // false и НЕ бросает → проверяем каждый шаг, false → rollBack + громкий провал.
        $this->runInTransaction(
            [$resetHash, $resetImages],
            'resetForReapply: не удалось сбросить карту перепринятия (rollback выполнен)'
        );
    }

    /**
     * Церемония «Связать заново» (D-SAT-BIND-REBIND-CEREMONY). Bind — ОДНОРАЗОВАЯ фаза: отметив
     * «bind отработал», модуль в bind больше не возвращается (фикс D-SAT-BIND-LOOP-NEVER-APPLIES).
     * Если оператор проставил SKU на витрине уже ПОСЛЕ первого (возможно холостого) bind, штатно
     * вернуть связывание можно только этим сбросом: снести строки владения товаров/вариантов И
     * погасить ОБЕ метки bind (in_progress + completed). Тогда следующий прогон снова увидит
     * «карта product/variant пуста + каталог непуст + метки сняты» → уйдёт в BIND и свяжет по SKU.
     *
     * ⚠ Это ДРУГАЯ операция, не resetForReapply: тот намеренно НЕ трогает bind_marker (иначе возврат
     * петли/дубли) и лишь зануляет applied_hash сущностей — строки владения остаются, count не падает
     * до 0, shouldBind() не срабатывает. Rebind, наоборот, обязан обнулить count product/variant.
     *
     * Владение категорий/брендов/свойств/редиректов НЕ трогаем — оно живёт независимо от связывания
     * товаров (bind матчит только товары/варианты по SKU).
     *
     * Атомарность: слой Okay\Core\Database транзакционного API не предоставляет (как и соседний
     * resetForReapply, идущий двумя отдельными запросами), поэтому существует промежуточное
     * состояние «выполнен только первый запрос» (crash процесса между двумя SQL; lock здесь не
     * держится — rebind()-контроллер зовёт метод вне SyncRunner).
     *
     * ПОРЯДОК ЗАПРОСОВ ОБЯЗАТЕЛЕН: сперва гашение меток, потом DELETE владения. Промежуточные
     * состояния двух порядков РАЗНЫЕ (приёмка stage-sat-rebind, пробы P1/P1'):
     *   • P1 (НЕБЕЗОПАСНЫЙ порядок, DELETE-first): строки product/variant снесены, метка completed
     *     ещё active → shouldBind() ложен по isBindCompleted → следующий АВТОНОМНЫЙ прогон (крон,
     *     без участия оператора) уходит в FULL с ПУСТОЙ картой → map->find промахивается по всем
     *     товарам → MAP_CREATE → весь каталог пере-СОЗДАЁТСЯ дублями. Состояние не самолечится:
     *     после ошибочного full повторный rebind находит дубль-SKU → conflict → 0 связано.
     *   • P1' (безопасный порядок, markers-first): метки сняты, карта владения ЕЩЁ на месте →
     *     shouldBind() ложен по непустой карте → следующий прогон = штатный идемпотентный FULL по
     *     живой карте (map->find попадает → UPDATE, дублей нет); оператор просто жмёт
     *     «Связать заново» ещё раз.
     * Замок порядка — testRebindCrashBetweenQueriesDoesNotDuplicateCatalog (BindTest): обратный
     * свап двух db->query красит его.
     */
    public function resetForRebind(): void
    {
        // 1) Гасим обе метки bind (in_progress + completed) ДО сноса владения — см. P1/P1' выше.
        //    completed держит «bind отработал» (без сброса shouldBind() вернёт false), in_progress —
        //    на случай прерванного bind. Одного UPDATE достаточно: обе метки делят entity_type.
        $clearMarkers = $this->queryFactory->newUpdate();
        $clearMarkers->table(self::getTable())
            ->set('applied_hash', null) // → SET applied_hash = NULL
            ->where('entity_type = :type')
            ->bindValue('type', Contract::ENTITY_BIND_MARKER);

        // 2) Снос владения товаров/вариантов: обнуляет их count → shouldBind() снова сможет сработать.
        $deleteEntities = $this->queryFactory->newDelete();
        $deleteEntities->from(self::getTable())
            ->where('entity_type IN (:entity_types)')
            ->bindValue('entity_types', [Contract::ENTITY_PRODUCT, Contract::ENTITY_VARIANT]);

        // Атомарно (D-OKAY-DB-NO-TX): транзакция делает промежуточное состояние «выполнен только
        // первый запрос» невозможным при штатном сбое (query() → false → rollBack). Порядок запросов
        // ПРЕЖНИЙ (markers-first) и внутри транзакции — defense-in-depth: если транзакция по какой-то
        // причине не защитит (частичный коммит), безопасен именно этот порядок (P1' выше). Массив
        // передаётся в порядке [гашение меток, DELETE] — runInTransaction исполняет его как есть.
        $this->runInTransaction(
            [$clearMarkers, $deleteEntities],
            'resetForRebind: не удалось сбросить связывание (rollback выполнен)'
        );
    }

    /**
     * Исполнить упорядоченный список запросов в одной транзакции ядра. query() ядра при ошибке SQL
     * возвращает false и НЕ бросает — поэтому проверяем результат каждого шага: первый же false →
     * rollBack + CoreSyncException (громкий провал вызывающему, вместо тихого частичного сброса).
     * Любое исключение внутри → rollBack + проброс. Порядок запросов сохраняется как передан.
     *
     * @param array<int, \Aura\SqlQuery\QueryInterface> $queries запросы в порядке исполнения
     * @throws CoreSyncException шаг не выполнился (rollback уже сделан)
     */
    private function runInTransaction(array $queries, string $failMessage): void
    {
        // Guard непатченного ядра. Транзакционное API (beginTransaction/commit/rollBack/inTransaction)
        // добавлено В ЯДРО Okay\Core\Database отдельным коммитом (b0fc15d). Но артефакт самообновления
        // пакует ТОЛЬКО каталог CoreSync/ (tools/build-artifact.sh) — правка ядра до сателлита НЕ
        // доезжает: любая витрина с непатченным ядром (или обновившаяся артефактом) на безусловном
        // beginTransaction() словила бы PHP fatal «Call to undefined method» прямо на «Связать
        // заново»/reapply. Это несущий инвариант доставки, не бойскаутство. Четыре метода добавлялись
        // атомарно одним коммитом — достаточно проверить один. Ядро без tx-API → исполняем те же
        // запросы в том же порядке без транзакции (runWithoutTransaction): деградация = ровно
        // пред-harden семантика (markers-first порядок как defense-in-depth, per-step false → тот же
        // громкий CoreSyncException; физического отката нет — это и была старая модель D-OKAY-DB-NO-TX).
        if (!method_exists($this->db, 'beginTransaction')) {
            $this->runWithoutTransaction($queries, $failMessage);

            return;
        }

        $this->db->beginTransaction();
        try {
            foreach ($queries as $query) {
                if ($this->db->query($query) === false) {
                    throw new CoreSyncException($failMessage);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Fallback-путь для непатченного ядра без tx-API (см. guard в runInTransaction). Те же запросы в
     * ТОМ ЖЕ порядке (для resetForRebind это markers-first — defense-in-depth), с той же per-step
     * проверкой query()===false → CoreSyncException. Отката физически нет (ядро транзакций не умеет) —
     * это ровно пред-harden модель: громкость провала сохраняется, атомарность недостижима.
     *
     * @param array<int, \Aura\SqlQuery\QueryInterface> $queries запросы в порядке исполнения
     * @throws CoreSyncException шаг не выполнился
     */
    private function runWithoutTransaction(array $queries, string $failMessage): void
    {
        foreach ($queries as $query) {
            if ($this->db->query($query) === false) {
                throw new CoreSyncException($failMessage);
            }
        }
    }
}
