<?php

namespace Okay\Modules\Format\CoreSync\Core\Exceptions;

/**
 * Модуль не может честно описать себя для церемонии подключения (SATFEED-M §B): не задан/непригоден
 * `storefront_base_url` в настройках, либо не выводится шаблон карточки товара.
 *
 * Fail-closed: описание уезжает в `<url>` боевого фида ядра — соврать доменом из заголовка `Host`
 * или отдать шаблон без `{slug}` хуже, чем не ответить. Приёмник превращает это в ту же
 * неразличимую 404, что и любой другой отказ (текст — только в лог модуля, оператору).
 */
class DescribeUnavailableException extends CoreSyncException
{
}
