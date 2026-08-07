<?php

namespace Okay\Modules\Format\CoreSync\Core\Orders;

use Okay\Core\EntityFactory;
use Okay\Core\Settings;
use Okay\Entities\OrderStatusEntity;
use Okay\Modules\Format\CoreSync\Core\Contract;
use Psr\Log\LoggerInterface;

/**
 * Строка-маркер статуса «принят в обработку» в справочнике оператора `__orders_status` (FEAT-ORD-M,
 * спека §5). Модуль заводит СВОЙ статус идемпотентно (id — в Settings) и по ack переводит в него
 * «новые» заказы. Дефолтный «новый» статус = первый по `position ASC` — ровно тот, что присваивает
 * новому заказу `OrdersEntity::add` (разведка ядра); только его модуль вправе флипать.
 */
class OrdersStatusMarker
{
    /** @var EntityFactory */
    private $entityFactory;
    /** @var Settings */
    private $settings;
    /** @var LoggerInterface|null */
    private $logger;

    public function __construct(EntityFactory $entityFactory, Settings $settings, ?LoggerInterface $logger = null)
    {
        $this->entityFactory = $entityFactory;
        $this->settings = $settings;
        $this->logger = $logger;
    }

    /**
     * id строки-маркера «принят в обработку». Идемпотентно: id из Settings, если строка ещё жива;
     * иначе завести её (в конец справочника) и запомнить id. 0 — не удалось (ack пропустит флип).
     */
    public function ensureAcceptedStatusId(): int
    {
        /** @var OrderStatusEntity $statuses */
        $statuses = $this->entityFactory->get(OrderStatusEntity::class);

        $stored = (int) $this->settings->get(Contract::SETTINGS_ACCEPTED_STATUS_ID_KEY);
        if ($stored > 0 && $this->statusExists($statuses, $stored)) {
            return $stored;
        }

        $id = (int) $statuses->add([
            'name'     => Contract::ACCEPTED_STATUS_NAME,
            'is_close' => 0,
            'position' => $this->nextPosition($statuses),
        ]);
        if ($id > 0) {
            $this->settings->set(Contract::SETTINGS_ACCEPTED_STATUS_ID_KEY, $id);
            if ($this->logger !== null) {
                $this->logger->info('CoreSync: заведён статус-маркер «принят в обработку» (id=' . $id . ')');
            }
        }

        return $id;
    }

    /**
     * id дефолтного «нового» статуса = первый по position (как в OrdersEntity::add). 0 — справочник
     * пуст (флип не выполнится — безопасно).
     */
    public function defaultNewStatusId(): int
    {
        /** @var OrderStatusEntity $statuses */
        $statuses = $this->entityFactory->get(OrderStatusEntity::class);
        $all = $this->allStatuses($statuses);
        $first = reset($all);

        return is_object($first) ? (int) $first->id : 0;
    }

    /**
     * @param OrderStatusEntity $statuses
     * @return array<int, object>
     */
    protected function allStatuses($statuses): array
    {
        return array_values((array) $statuses->find());
    }

    /**
     * @param OrderStatusEntity $statuses
     */
    protected function statusExists($statuses, int $id): bool
    {
        return is_object($statuses->get($id));
    }

    /**
     * @param OrderStatusEntity $statuses
     */
    private function nextPosition($statuses): int
    {
        $max = 0;
        foreach ($this->allStatuses($statuses) as $status) {
            $pos = (int) ($status->position ?? 0);
            if ($pos > $max) {
                $max = $pos;
            }
        }

        return $max + 1;
    }
}
