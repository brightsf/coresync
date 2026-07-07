<?php

namespace Okay\Modules\Format\CoreSync\Core\Exceptions;

/**
 * Валюта манифеста не сопоставлена ни одному локальному currency_id (настройка currency_map).
 * Fail-closed ВСЕГО прогона ДО фазы products (enforcement, отложенный из M1).
 */
class CurrencyNotMappedException extends CoreSyncException
{
}
