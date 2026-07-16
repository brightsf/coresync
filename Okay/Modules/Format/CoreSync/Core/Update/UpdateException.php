<?php

namespace Okay\Modules\Format\CoreSync\Core\Update;

use Okay\Modules\Format\CoreSync\Core\Exceptions\CoreSyncException;

/**
 * Любой сбой цикла самообновления модуля (пин/скачивание/sha256/распаковка/swap/само-проверка).
 * Наследует CoreSyncException — единый корень исключений модуля. Провал ЛЮБОГО шага обновления
 * бросает это исключение; оркестратор ловит его, громко логирует и фиксирует исход (откат гарантирован
 * на уровне swap'а). Наружу (в витрину) не пробрасывается — обновление это фон, а не тело запроса.
 */
class UpdateException extends CoreSyncException
{
}
